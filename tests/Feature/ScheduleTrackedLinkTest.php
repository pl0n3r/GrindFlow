<?php

namespace Tests\Feature;

use App\Contracts\DistributionProvider;
use App\Enums\UserRole;
use App\Jobs\DispatchScheduledPublication;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PublicationDelivery;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\ScheduledPublicationLink;
use App\Models\TrackedLink;
use App\Models\TrackedLinkDailyMetric;
use App\Models\User;
use App\Services\Distribution\DistributionProviderRegistry;
use App\Services\Distribution\DistributionResult;
use App\Services\Distribution\PublicationDeliveryManager;
use App\Services\Media\MediaAssetProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ScheduleTrackedLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_can_attach_active_link_from_same_organization(): void
    {
        [$actor, $organization] = $this->identity();
        [$asset, $destination, $link] = $this->fixtures($actor, $organization);

        $this->actingAs($actor)
            ->get($this->indexRoute($organization))
            ->assertOk()
            ->assertSee('Tracked link (optional)')
            ->assertSee($link->label);

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                $link,
            ))
            ->assertRedirect()
            ->assertSessionHas('status');

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($link): void {
                $publication = ScheduledPublication::query()->sole();
                $assignment = ScheduledPublicationLink::query()->sole();

                $this->assertSame(
                    $publication->getKey(),
                    $assignment->scheduled_publication_id,
                );
                $this->assertSame(
                    $link->getKey(),
                    $assignment->tracked_link_id,
                );
                $this->assertSame(
                    $link->label,
                    $publication->linkAssignment?->trackedLink?->label,
                );
            },
        );
    }

    public function test_foreign_tracked_link_is_not_schedulable(): void
    {
        [$actor, $organization] = $this->identity();
        [$otherActor, $otherOrganization] = $this->identity();

        [$asset, $destination] = $this->fixtures($actor, $organization);
        [, , $foreignLink] = $this->fixtures($otherActor, $otherOrganization);

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                $foreignLink,
            ))
            ->assertNotFound();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function (): void {
                $this->assertSame(0, ScheduledPublication::query()->count());
                $this->assertSame(0, ScheduledPublicationLink::query()->count());
            },
        );
    }

    #[Group('database')]
    public function test_composite_database_fk_rejects_cross_tenant_link_association(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL is required for the composite FK contract.');
        }

        [$actor, $organization] = $this->identity();
        [$otherActor, $otherOrganization] = $this->identity();
        [$asset, $destination] = $this->fixtures($actor, $organization);
        [, , $foreignLink] = $this->fixtures($otherActor, $otherOrganization);

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                null,
            ))
            ->assertRedirect();

        $publication = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn (): ScheduledPublication => ScheduledPublication::query()->sole(),
        );

        $this->expectException(QueryException::class);

        DB::table('scheduled_publication_links')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->getKey(),
            'scheduled_publication_id' => $publication->getKey(),
            'tracked_link_id' => $foreignLink->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_disabled_link_cannot_enter_a_schedule(): void
    {
        [$actor, $organization] = $this->identity();
        [$asset, $destination, $link] = $this->fixtures($actor, $organization);

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($link): void {
                TrackedLink::query()
                    ->findOrFail($link->getKey())
                    ->forceFill(['status' => TrackedLink::STATUS_DISABLED])
                    ->save();
            },
        );

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                $link,
            ))
            ->assertSessionHasErrors('tracked_link_id');

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                0,
                ScheduledPublication::query()->count(),
            ),
        );
    }

    public function test_existing_scheduler_survives_missing_link_migration(): void
    {
        [$actor, $organization] = $this->identity();
        [$asset, $destination, $link] = $this->fixtures($actor, $organization);

        Schema::dropIfExists('scheduled_publication_links');

        try {
            $this->actingAs($actor)
                ->get($this->indexRoute($organization))
                ->assertOk()
                ->assertDontSee('Tracked link (optional)');

            $this->actingAs($actor)
                ->post($this->indexRoute($organization), $this->payload(
                    $asset,
                    $destination,
                    null,
                ))
                ->assertRedirect()
                ->assertSessionHas('status');

            $this->actingAs($actor)
                ->post($this->indexRoute($organization), $this->payload(
                    $asset,
                    $destination,
                    $link,
                ))
                ->assertStatus(503);
        } finally {
            $migration = require database_path(
                'migrations/2026_09_19_053000_create_scheduled_publication_links.php',
            );
            $migration->up();
        }
    }

    public function test_disabling_link_before_delivery_blocks_provider_io(): void
    {
        Queue::fake([DispatchScheduledPublication::class]);

        [$actor, $organization] = $this->identity();
        [$asset, $destination, $link] = $this->fixtures($actor, $organization);

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                $link,
            ))
            ->assertRedirect();

        $this->travel(2)->days();

        $provider = new class implements DistributionProvider
        {
            public int $calls = 0;

            public function publish(
                ScheduledPublication $publication,
                string $idempotencyKey,
            ): DistributionResult {
                $this->calls++;

                return new DistributionResult('unexpected-publish');
            }
        };

        app(DistributionProviderRegistry::class)
            ->register('provider-test', $provider);

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor, $link): void {
                $publication = ScheduledPublication::query()->sole();

                $this->assertTrue(
                    app(PublicationDeliveryManager::class)
                        ->queue($publication, $actor),
                );

                TrackedLink::query()
                    ->findOrFail($link->getKey())
                    ->forceFill(['status' => TrackedLink::STATUS_DISABLED])
                    ->save();
            },
        );

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor): void {
                $delivery = PublicationDelivery::query()->sole();

                app(PublicationDeliveryManager::class)
                    ->dispatch($delivery, $actor);

                $this->assertSame(
                    PublicationDelivery::STATUS_FAILED,
                    $delivery->fresh()->status,
                );
            },
        );

        $this->assertSame(0, $provider->calls);
    }

    public function test_manager_can_attach_swap_and_detach_a_link_without_losing_metrics(): void
    {
        [$actor, $organization] = $this->identity();
        [$asset, $destination, $first] = $this->fixtures($actor, $organization);

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                null,
            ))
            ->assertRedirect();

        [$publication, $second] = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor, $first): array {
                $publication = ScheduledPublication::query()->sole();

                TrackedLinkDailyMetric::query()->create([
                    'tracked_link_id' => $first->getKey(),
                    'metric_date' => '2026-09-19',
                    'clicks' => 17,
                ]);

                $second = TrackedLink::query()->create([
                    'created_by_user_id' => $actor->getKey(),
                    'token' => Str::random(22),
                    'label' => 'Second campaign',
                    'destination_url' => 'https://example.test/second',
                    'status' => TrackedLink::STATUS_ACTIVE,
                ]);

                return [$publication, $second];
            },
        );

        $url = $this->linkEditRoute($organization);

        $this->actingAs($actor)->get($this->indexRoute($organization))
            ->assertOk()
            ->assertSee('Save tracked link')
            ->assertSee($url);

        $this->actingAs($actor)->patch($url, [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => $first->getKey(),
        ])->assertRedirect()->assertSessionHas('status');

        $this->actingAs($actor)->patch($url, [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => $first->getKey(),
        ])->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($publication, $first): void {
                $this->assertSame(1, ScheduledPublicationLink::query()->count());
                $this->assertSame(
                    $first->getKey(),
                    ScheduledPublicationLink::query()->sole()->tracked_link_id,
                );
                $this->assertSame(
                    17,
                    (int) TrackedLinkDailyMetric::query()
                        ->where('tracked_link_id', $first->getKey())
                        ->value('clicks'),
                );
            },
        );

        $this->actingAs($actor)->patch($url, [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => $second->getKey(),
        ])->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($second): void {
                $this->assertSame(1, ScheduledPublicationLink::query()->count());
                $this->assertSame(
                    $second->getKey(),
                    ScheduledPublicationLink::query()->sole()->tracked_link_id,
                );
            },
        );

        $this->actingAs($actor)->patch($url, [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => '',
        ])->assertRedirect();

        $this->actingAs($actor)->patch($url, [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => '',
        ])->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($publication, $first, $second): void {
                $this->assertSame(0, ScheduledPublicationLink::query()->count());
                $this->assertSame(1, ScheduledPublication::query()->count());
                $this->assertNotNull(TrackedLink::query()->find($first->getKey()));
                $this->assertNotNull(TrackedLink::query()->find($second->getKey()));
                $this->assertSame(
                    17,
                    (int) TrackedLinkDailyMetric::query()->sole()->clicks,
                );
                $this->assertNull(
                    $publication->fresh()?->linkAssignment,
                );
            },
        );
    }

    public function test_link_edit_rejects_other_tenant_model_and_invalid_or_disabled_link(): void
    {
        [$actor, $organization] = $this->identity();
        [$otherActor, $otherOrganization] = $this->identity();
        [$asset, $destination, $link] = $this->fixtures($actor, $organization);
        [, , $foreignLink] = $this->fixtures($otherActor, $otherOrganization);

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                $link,
            ))->assertRedirect();

        $publication = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn (): ScheduledPublication => ScheduledPublication::query()->sole(),
        );

        $this->actingAs($actor)->patch($this->linkEditRoute($organization), [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => $foreignLink->getKey(),
        ])->assertNotFound();

        $this->actingAs($otherActor)->patch($this->linkEditRoute($otherOrganization), [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => $foreignLink->getKey(),
        ])->assertNotFound();

        $this->actingAs($actor)->patch($this->linkEditRoute($organization), [
            'publication_id' => $publication->getKey(),
        ])->assertSessionHasErrors('tracked_link_id');

        $this->actingAs($actor)->patch($this->linkEditRoute($organization), [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => 'not-a-uuid',
        ])->assertSessionHasErrors('tracked_link_id');

        $model = User::factory()->create();
        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $model->getKey(),
            'role' => UserRole::Model,
        ]);

        $this->actingAs($model)->patch($this->linkEditRoute($organization), [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => '',
        ])->assertForbidden();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn () => TrackedLink::query()->findOrFail($link->getKey())
                ->forceFill(['status' => TrackedLink::STATUS_DISABLED])
                ->save(),
        );

        $this->actingAs($actor)->get($this->indexRoute($organization))
            ->assertOk()
            ->assertSee('Current link unavailable. Choose another or remove.')
            ->assertSee('Test campaign');

        $this->actingAs($actor)->patch($this->linkEditRoute($organization), [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => $link->getKey(),
        ])->assertSessionHasErrors('tracked_link_id');

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                $link->getKey(),
                ScheduledPublicationLink::query()->sole()->tracked_link_id,
            ),
        );
    }

    public function test_link_edit_rejects_cancelled_or_claimed_delivery(): void
    {
        [$actor, $organization] = $this->identity();
        [$asset, $destination, $link] = $this->fixtures($actor, $organization);

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                $link,
            ))->assertRedirect();

        $publication = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn (): ScheduledPublication => ScheduledPublication::query()->sole(),
        );

        $url = $this->linkEditRoute($organization);

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn () => PublicationDelivery::query()->create([
                'scheduled_publication_id' => $publication->getKey(),
                'idempotency_key' => (string) Str::uuid(),
                'status' => PublicationDelivery::STATUS_QUEUED,
                'attempts' => 0,
            ]),
        );

        $this->actingAs($actor)->patch($url, [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => '',
        ])->assertSessionHasErrors('tracked_link_id');

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($publication): void {
                PublicationDelivery::query()->where(
                    'scheduled_publication_id',
                    $publication->getKey(),
                )->delete();
                $publication->forceFill([
                    'status' => ScheduledPublication::STATUS_CANCELLED,
                ])->save();
            },
        );

        $this->actingAs($actor)->patch($url, [
            'publication_id' => $publication->getKey(),
            'tracked_link_id' => '',
        ])->assertSessionHasErrors('tracked_link_id');

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                $link->getKey(),
                ScheduledPublicationLink::query()->sole()->tracked_link_id,
            ),
        );
    }

    public function test_link_edit_requires_existing_link_schema(): void
    {
        [$actor, $organization] = $this->identity();
        [$asset, $destination] = $this->fixtures($actor, $organization);

        $this->actingAs($actor)
            ->post($this->indexRoute($organization), $this->payload(
                $asset,
                $destination,
                null,
            ))->assertRedirect();

        $publication = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn (): ScheduledPublication => ScheduledPublication::query()->sole(),
        );

        Schema::dropIfExists('scheduled_publication_links');

        try {
            $this->actingAs($actor)->patch($this->linkEditRoute($organization), [
                'publication_id' => $publication->getKey(),
                'tracked_link_id' => '',
            ])->assertStatus(503);

            $this->actingAs($actor)->get($this->indexRoute($organization))
                ->assertOk()
                ->assertDontSee('Save tracked link');
        } finally {
            $migration = require database_path(
                'migrations/2026_09_19_053000_create_scheduled_publication_links.php',
            );
            $migration->up();
        }
    }

    /**
     * @return array{User, Organization}
     */
    private function identity(): array
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $actor->getKey(),
            'role' => UserRole::Studio,
        ]);

        return [$actor, $organization];
    }

    /**
     * @return array{MediaAsset, PublishingDestination, TrackedLink}
     */
    private function fixtures(
        User $actor,
        Organization $organization,
    ): array {
        return app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor): array {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'organizations/test/blobs/'.Str::uuid(),
                    'sha256' => hash('sha256', (string) Str::uuid()),
                    'byte_size' => 1024,
                    'mime_type' => 'image/jpeg',
                ]);

                $asset = MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => 'attribution-test.jpg',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                    'metadata' => [
                        'processing' => [
                            'status' => 'completed',
                            'version' => app(MediaAssetProcessor::class)
                                ->currentVersion(),
                        ],
                    ],
                ]);

                $destination = PublishingDestination::query()->create([
                    'name' => 'Testing destination',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);

                $link = TrackedLink::query()->create([
                    'created_by_user_id' => $actor->getKey(),
                    'token' => Str::random(22),
                    'label' => 'Test campaign',
                    'destination_url' => 'https://example.test/landing',
                    'status' => TrackedLink::STATUS_ACTIVE,
                ]);

                return [$asset, $destination, $link];
            },
        );
    }

    private function linkEditRoute(Organization $organization): string
    {
        return route('organizations.scheduler.tracked-link.update', [
            'organizationId' => $organization->getKey(),
        ]);
    }

    private function indexRoute(Organization $organization): string
    {
        return route('organizations.scheduler.index', [
            'organizationId' => $organization->getKey(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(
        MediaAsset $asset,
        PublishingDestination $destination,
        ?TrackedLink $link,
    ): array {
        $data = [
            'asset_id' => (string) $asset->getKey(),
            'destination_id' => (string) $destination->getKey(),
            'request_key' => (string) Str::uuid(),
            'scheduled_for_local' => now('UTC')
                ->addDay()
                ->format('Y-m-d\TH:i'),
            'timezone' => 'UTC',
        ];

        if ($link !== null) {
            $data['tracked_link_id'] = (string) $link->getKey();
        }

        return $data;
    }
}
