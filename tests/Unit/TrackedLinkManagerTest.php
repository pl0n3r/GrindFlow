<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\TrackedLink;
use App\Models\TrackedLinkDailyMetric;
use App\Models\User;
use App\Services\Traffic\TrackedLinkManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class TrackedLinkManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_normalizes_fields_and_allocates_a_public_token(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);

        $link = $this->within($actor, $organization, fn (): TrackedLink => $this->manager()->create(
            $actor,
            '  Summer link  ',
            '  https://example.test/offer  ',
            '  social  ',
            '  ',
        ),
        );

        $this->assertSame('Summer link', $link->label);
        $this->assertSame('https://example.test/offer', $link->destination_url);
        $this->assertSame('social', $link->channel);
        $this->assertNull($link->campaign);
        $this->assertSame(TrackedLink::STATUS_ACTIVE, $link->status);
        $this->assertSame($organization->getKey(), $link->organization_id);
        $this->assertSame($actor->getKey(), $link->created_by_user_id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{22}$/', $link->token);
        $this->assertDatabaseCount('tracked_links', 1);
    }

    public function test_create_requires_tenant_context_and_manager_role(): void
    {
        [$actor] = $this->identity(UserRole::Studio);
        $this->assertDenied(fn (): TrackedLink => $this->manager()->create(
            $actor, 'No context', 'https://example.test', null, null,
        ));

        [$viewer, $organization] = $this->identity(UserRole::Model);
        $this->assertDenied(fn (): mixed => $this->within(
            $viewer,
            $organization,
            fn (): TrackedLink => $this->manager()->create(
                $viewer, 'No role', 'https://example.test', null, null,
            ),
        ));
        $this->assertDatabaseCount('tracked_links', 0);
    }

    public function test_create_retries_global_token_collisions_across_tenants(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Editor);
        [$foreignActor, $foreignOrganization] = $this->identity(UserRole::Studio);
        $existing = $this->within($foreignActor, $foreignOrganization, fn (): TrackedLink => $this->manager()->create(
            $foreignActor, 'Foreign', 'https://example.test/foreign', null, null,
        ),
        );
        $collision = $existing->token;
        $fresh = str_repeat('B', 22);
        $calls = 0;

        Str::createRandomStringsUsing(static function (int $length) use (&$calls, $collision, $fresh): string {
            $calls++;

            return $calls === 1 ? $collision : $fresh;
        });

        try {
            $created = $this->within($actor, $organization, fn (): TrackedLink => $this->manager()->create(
                $actor, 'Local', 'https://example.test/local', null, null,
            ),
            );
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame(2, $calls);
        $this->assertSame($fresh, $created->token);
        $this->assertNotSame($collision, $created->token);
        $this->assertDatabaseCount('tracked_links', 2);
    }

    public function test_create_fails_closed_after_eight_token_collisions(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $existing = $this->within($actor, $organization, fn (): TrackedLink => $this->manager()->create(
            $actor, 'Existing', 'https://example.test/existing', null, null,
        ),
        );
        $calls = 0;
        Str::createRandomStringsUsing(static function (int $length) use (&$calls, $existing): string {
            $calls++;

            return $existing->token;
        });

        try {
            $this->within($actor, $organization, function () use ($actor): void {
                try {
                    $this->manager()->create(
                        $actor, 'Rejected', 'https://example.test/rejected', null, null,
                    );
                    $this->fail('Expected the bounded token allocator to reject collisions.');
                } catch (RuntimeException $exception) {
                    $this->assertSame(
                        'Unable to allocate a unique tracked-link token.',
                        $exception->getMessage(),
                    );
                }
            });
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame(8, $calls);
        $this->assertDatabaseCount('tracked_links', 1);
    }

    public function test_set_status_is_reversible_idempotent_and_preserves_click_history(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $link = $this->within($actor, $organization, fn (): TrackedLink => $this->manager()->create(
            $actor, 'Campaign', 'https://example.test/target', 'x', 'august',
        ),
        );
        $token = $link->token;
        $this->within($actor, $organization, function () use ($actor, $link): void {
            TrackedLinkDailyMetric::query()->create([
                'tracked_link_id' => $link->getKey(),
                'metric_date' => '2026-09-19',
                'clicks' => 7,
            ]);

            $manager = $this->manager();
            $manager->setStatus($actor, (string) $link->getKey(), TrackedLink::STATUS_DISABLED);
            $manager->setStatus($actor, (string) $link->getKey(), TrackedLink::STATUS_DISABLED);
            $this->assertSame(TrackedLink::STATUS_DISABLED, $link->fresh()->status);
            $manager->setStatus($actor, (string) $link->getKey(), TrackedLink::STATUS_ACTIVE);
            $this->assertSame(TrackedLink::STATUS_ACTIVE, $link->fresh()->status);
        });

        $this->assertSame($token, $link->fresh()->token);
        $this->assertSame(7, (int) DB::table('tracked_link_daily_metrics')
            ->where('tracked_link_id', $link->getKey())->value('clicks'));
    }

    public function test_set_status_rejects_invalid_values_and_foreign_ids_without_writes(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Editor);
        [$foreignActor, $foreignOrganization] = $this->identity(UserRole::Studio);
        $foreign = $this->within($foreignActor, $foreignOrganization, fn (): TrackedLink => $this->manager()->create(
            $foreignActor, 'Foreign', 'https://example.test/foreign', null, null,
        ),
        );

        $this->within($actor, $organization, function () use ($actor, $foreign): void {
            try {
                $this->manager()->setStatus($actor, (string) $foreign->getKey(), 'deleted');
                $this->fail('Unsupported status was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Unsupported tracked-link status.', $exception->getMessage());
            }
            try {
                $this->manager()->setStatus(
                    $actor, (string) $foreign->getKey(), TrackedLink::STATUS_DISABLED,
                );
                $this->fail('Foreign link was visible.');
            } catch (ModelNotFoundException) {
                // Scoped lookup does not reveal a foreign link.
            }
        });

        $this->assertSame(TrackedLink::STATUS_ACTIVE, $foreign->fresh()->status);
    }

    public function test_update_details_normalizes_nullable_fields_without_rotating_token_or_status(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $link = $this->within($actor, $organization, fn (): TrackedLink => $this->manager()->create(
            $actor, 'Original', 'https://example.test/original', 'x', 'spring',
        ),
        );
        $originalToken = $link->token;
        $this->within($actor, $organization, function () use ($actor, $link): void {
            TrackedLinkDailyMetric::query()->create([
                'tracked_link_id' => $link->getKey(),
                'metric_date' => '2026-09-19',
                'clicks' => 11,
            ]);
            $this->manager()->setStatus(
                $actor, (string) $link->getKey(), TrackedLink::STATUS_DISABLED,
            );
            $this->manager()->updateDetails(
                $actor,
                (string) $link->getKey(),
                '  Renamed  ',
                '  https://example.test/new  ',
                '  ',
                '  autumn  ',
            );
        });

        $updated = $link->fresh();
        $this->assertSame('Renamed', $updated->label);
        $this->assertSame('https://example.test/new', $updated->destination_url);
        $this->assertNull($updated->channel);
        $this->assertSame('autumn', $updated->campaign);
        $this->assertSame(TrackedLink::STATUS_DISABLED, $updated->status);
        $this->assertSame($originalToken, $updated->token);
        $this->assertSame(11, (int) DB::table('tracked_link_daily_metrics')
            ->where('tracked_link_id', $link->getKey())->value('clicks'));
    }

    public function test_update_details_rejects_invalid_metadata_atomically(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $link = $this->within($actor, $organization, fn (): TrackedLink => $this->manager()->create(
            $actor, 'Before', 'https://example.test/before', 'x', 'fixed',
        ),
        );
        $invalid = [
            ['', 'https://example.test/ok', null, null],
            [str_repeat('L', 192), 'https://example.test/ok', null, null],
            ['Name', 'javascript:alert(1)', null, null],
            ['Name', 'https://example.test/ok', str_repeat('C', 65), null],
            ['Name', 'https://example.test/ok', null, str_repeat('D', 129)],
        ];

        $this->within($actor, $organization, function () use ($actor, $link, $invalid): void {
            foreach ($invalid as [$label, $url, $channel, $campaign]) {
                try {
                    $this->manager()->updateDetails(
                        $actor, (string) $link->getKey(), $label, $url, $channel, $campaign,
                    );
                    $this->fail('Invalid metadata was accepted.');
                } catch (InvalidArgumentException) {
                    $this->assertSame('Before', $link->fresh()->label);
                    $this->assertSame('https://example.test/before', $link->fresh()->destination_url);
                }
            }
        });
    }

    public function test_update_details_denies_foreign_scope_and_unprivileged_actor(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        [$foreignActor, $foreignOrganization] = $this->identity(UserRole::Studio);
        [$viewer, $viewerOrganization] = $this->identity(UserRole::Model);
        $foreign = $this->within($foreignActor, $foreignOrganization, fn (): TrackedLink => $this->manager()->create(
            $foreignActor, 'Foreign', 'https://example.test/foreign', null, null,
        ),
        );
        $this->within($actor, $organization, function () use ($actor, $foreign): void {
            try {
                $this->manager()->updateDetails(
                    $actor, (string) $foreign->getKey(), 'Leak', 'https://example.test/leak', null, null,
                );
                $this->fail('Foreign link was visible.');
            } catch (ModelNotFoundException) {
                // The tenant-scoped lock must not reveal the foreign link.
            }
        });
        $this->assertDenied(fn (): mixed => $this->within(
            $viewer,
            $viewerOrganization,
            fn (): mixed => $this->manager()->updateDetails(
                $viewer, (string) $foreign->getKey(), 'Leak', 'https://example.test/leak', null, null,
            ),
        ));
        $this->assertSame('Foreign', $foreign->fresh()->label);
    }

    public function test_update_details_rolls_back_on_database_failure(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Deterministic SQLite trigger for rollback test.');
        }
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $link = $this->within($actor, $organization, fn (): TrackedLink => $this->manager()->create(
            $actor, 'Before', 'https://example.test/before', 'x', 'fixed',
        ),
        );
        DB::statement("CREATE TRIGGER reject_link_update BEFORE UPDATE ON tracked_links BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->within($actor, $organization, function () use ($actor, $link): void {
                try {
                    $this->manager()->updateDetails(
                        $actor, (string) $link->getKey(), 'After', 'https://example.test/after', null, null,
                    );
                    $this->fail('Expected database failure.');
                } catch (QueryException) {
                    $this->assertSame('Before', $link->fresh()->label);
                    $this->assertSame('https://example.test/before', $link->fresh()->destination_url);
                }
            });
        } finally {
            DB::statement('DROP TRIGGER reject_link_update');
        }
    }

    private function manager(): TrackedLinkManager
    {
        return app(TrackedLinkManager::class);
    }

    /** @return array{User, Organization} */
    private function identity(UserRole $role): array
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::query()->create([
            'user_id' => $actor->getKey(),
            'organization_id' => $organization->getKey(),
            'role' => $role,
        ]);

        return [$actor, $organization];
    }

    private function within(User $actor, Organization $organization, callable $callback): mixed
    {
        return app(TenantContext::class)->runWithinOrganization(
            $actor, (string) $organization->getKey(), $callback,
        );
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected manager to deny unauthorized call.');
        } catch (AuthorizationException) {
            // No write may occur without a privileged organization context.
        }
    }
}
