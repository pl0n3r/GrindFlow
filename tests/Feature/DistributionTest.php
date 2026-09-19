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
use App\Models\User;
use App\Services\Distribution\DistributionProviderException;
use App\Services\Distribution\DistributionProviderRegistry;
use App\Services\Distribution\DistributionResult;
use App\Services\Distribution\DistributionScheduler;
use App\Services\Distribution\PublicationDeliveryManager;
use App\Services\Media\MediaAssetProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DispatchScheduledPublication::class]);
    }

    public function test_due_publication_is_queued_once(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);

        $this->assertSame(
            1,
            app(DistributionScheduler::class)->dispatchDue(),
        );
        $this->assertSame(
            0,
            app(DistributionScheduler::class)->dispatchDue(),
        );

        Queue::assertPushed(
            DispatchScheduledPublication::class,
            1,
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($publication): void {
                $delivery = PublicationDelivery::query()->sole();

                $this->assertSame(
                    $publication->getKey(),
                    $delivery->scheduled_publication_id,
                );
                $this->assertSame(
                    PublicationDelivery::STATUS_QUEUED,
                    $delivery->status,
                );
                $this->assertSame(0, $delivery->attempts);
                $this->assertStringContainsString(
                    (string) $publication->getKey(),
                    $delivery->idempotency_key,
                );
            },
        );
    }

    public function test_invalid_actor_history_cannot_starve_later_valid_publication(): void
    {
        [$staleUser, $organization] = $this->identity();

        for ($index = 0; $index < 20; $index++) {
            $this->duePublication($staleUser, $organization);
        }

        Membership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $staleUser->getKey())
            ->delete();

        $this->travel(2)->minutes();

        $validUser = User::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $validUser->getKey(),
            'role' => UserRole::Studio,
        ]);

        $validPublication = $this->duePublication(
            $validUser,
            $organization,
        );

        $this->assertSame(
            1,
            app(DistributionScheduler::class)->dispatchDue(),
        );

        $delivery = app(TenantContext::class)->runWithinOrganization(
            $validUser,
            (string) $organization->getKey(),
            fn (): PublicationDelivery => PublicationDelivery::query()
                ->where(
                    'scheduled_publication_id',
                    $validPublication->getKey(),
                )
                ->sole(),
        );

        $this->assertSame(
            PublicationDelivery::STATUS_QUEUED,
            $delivery->status,
        );
    }

    public function test_terminal_history_cannot_starve_new_due_publication(): void
    {
        [$user, $organization] = $this->identity();

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user, $organization): void {
                for ($index = 0; $index < 20; $index++) {
                    $publication = $this->duePublication(
                        $user,
                        $organization,
                    );

                    PublicationDelivery::query()->create([
                        'scheduled_publication_id' => $publication->getKey(),
                        'idempotency_key' => 'terminal-'.$publication->getKey(),
                        'status' => PublicationDelivery::STATUS_PUBLISHED,
                        'attempts' => 1,
                        'published_at' => now('UTC'),
                        'external_publication_id' => 'external-'.$index,
                    ]);
                }
            },
        );

        $freshPublication = $this->duePublication(
            $user,
            $organization,
        );

        $this->assertSame(
            1,
            app(DistributionScheduler::class)->dispatchDue(),
        );

        $freshDelivery = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): PublicationDelivery => PublicationDelivery::query()
                ->where(
                    'scheduled_publication_id',
                    $freshPublication->getKey(),
                )
                ->sole(),
        );

        $this->assertSame(
            PublicationDelivery::STATUS_QUEUED,
            $freshDelivery->status,
        );
        Queue::assertPushed(
            DispatchScheduledPublication::class,
            fn (DispatchScheduledPublication $job): bool => $job->deliveryId
                === $freshDelivery->getKey(),
        );
    }

    public function test_abandoned_queued_delivery_can_be_redriven_after_lease(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);
        $delivery = $this->queueAndGetDelivery(
            $publication,
            $user,
            $organization,
        );

        $this->travel(6)->minutes();

        $redriven = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): bool => app(PublicationDeliveryManager::class)
                ->queue($publication, $user),
        );

        $this->assertTrue($redriven);
        Queue::assertPushed(
            DispatchScheduledPublication::class,
            2,
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                1,
                PublicationDelivery::query()->count(),
            ),
        );
    }

    public function test_queue_dispatch_failure_remains_retriable(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);

        $dispatcher = \Mockery::mock(BusDispatcher::class);
        $dispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));

        app()->instance(BusDispatcher::class, $dispatcher);

        try {
            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn (): bool => app(PublicationDeliveryManager::class)
                    ->queue($publication, $user),
            );

            $this->fail('Queue dispatch failure must be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'queue unavailable',
                $exception->getMessage(),
            );
        }

        $delivery = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): PublicationDelivery => PublicationDelivery::query()->sole(),
        );

        $this->assertSame(
            PublicationDelivery::STATUS_RETRY_SCHEDULED,
            $delivery->status,
        );
        $this->assertSame(0, $delivery->attempts);
        $this->assertSame(
            'distribution_dispatch_failed',
            $delivery->last_error_code,
        );
        $this->assertNull($delivery->claimed_until);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertTrue(
            $delivery->next_attempt_at->isAfter(
                now('UTC')->addSeconds(50),
            ),
        );
        $this->assertTrue(
            $delivery->next_attempt_at->isBefore(
                now('UTC')->addSeconds(70),
            ),
        );
    }

    public function test_authentication_failure_is_terminal_and_distinct(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);
        $provider = new SequenceDistributionProvider([
            DistributionProviderException::authentication(),
        ]);

        $this->provider($provider);
        $delivery = $this->queueAndGetDelivery(
            $publication,
            $user,
            $organization,
        );

        $this->dispatch($delivery, $user, $organization);

        $fresh = $this->delivery(
            $delivery,
            $user,
            $organization,
        );

        $this->assertSame(
            PublicationDelivery::STATUS_AUTHENTICATION_FAILED,
            $fresh->status,
        );
        $this->assertSame(1, $fresh->attempts);
        $this->assertNull($fresh->next_attempt_at);
        $this->assertSame(
            'distribution_authentication_failed',
            $fresh->last_error_code,
        );

        $this->dispatch($fresh, $user, $organization);

        $this->assertSame(1, $provider->calls);
    }

    public function test_rate_limit_is_bounded_and_successful_retry_is_idempotent(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);
        $provider = new SequenceDistributionProvider([
            DistributionProviderException::rateLimited(5),
            new DistributionResult('external-publication-123'),
        ]);

        $this->provider($provider);
        $delivery = $this->queueAndGetDelivery(
            $publication,
            $user,
            $organization,
        );

        $this->dispatch($delivery, $user, $organization);

        $retry = $this->delivery(
            $delivery,
            $user,
            $organization,
        );

        $this->assertSame(
            PublicationDelivery::STATUS_RETRY_SCHEDULED,
            $retry->status,
        );
        $this->assertSame(0, $retry->attempts);
        $this->assertSame(
            'distribution_rate_limited',
            $retry->last_error_code,
        );
        $this->assertNotNull($retry->next_attempt_at);
        $this->assertTrue(
            $retry->next_attempt_at->isAfter(
                now('UTC')->addSeconds(50),
            ),
        );
        $this->assertTrue(
            $retry->next_attempt_at->isBefore(
                now('UTC')->addSeconds(70),
            ),
        );

        $this->makeRetryDue($retry, $user, $organization);
        $this->dispatch($retry, $user, $organization);

        $published = $this->delivery(
            $delivery,
            $user,
            $organization,
        );

        $this->assertSame(
            PublicationDelivery::STATUS_PUBLISHED,
            $published->status,
        );
        $this->assertSame(1, $published->attempts);
        $this->assertSame(
            'external-publication-123',
            $published->external_publication_id,
        );
        $this->assertNotNull($published->published_at);
        $this->assertSame(2, $provider->calls);
        $this->assertCount(2, $provider->idempotencyKeys);
        $this->assertSame(
            $provider->idempotencyKeys[0],
            $provider->idempotencyKeys[1],
        );

        $this->dispatch($published, $user, $organization);

        $this->assertSame(2, $provider->calls);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                1,
                PublicationDelivery::query()->count(),
            ),
        );
    }

    public function test_rate_limits_do_not_consume_attempt_budget(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);
        $provider = new SequenceDistributionProvider([
            DistributionProviderException::rateLimited(60),
            DistributionProviderException::rateLimited(60),
            DistributionProviderException::rateLimited(60),
            DistributionProviderException::rateLimited(60),
            DistributionProviderException::rateLimited(60),
        ]);

        $this->provider($provider);
        $delivery = $this->queueAndGetDelivery(
            $publication,
            $user,
            $organization,
        );

        for ($index = 0; $index < 5; $index++) {
            if ($index > 0) {
                $this->makeRetryDue(
                    $delivery,
                    $user,
                    $organization,
                );
            }

            $this->dispatch($delivery, $user, $organization);
            $delivery = $this->delivery(
                $delivery,
                $user,
                $organization,
            );

            $this->assertSame(
                PublicationDelivery::STATUS_RETRY_SCHEDULED,
                $delivery->status,
            );
            $this->assertSame(0, $delivery->attempts);
        }

        $this->assertSame(5, $provider->calls);
        $this->assertSame(
            'distribution_rate_limited',
            $delivery->last_error_code,
        );
    }

    public function test_stale_worker_cannot_overwrite_newer_claim_on_success(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);
        $delivery = $this->queueAndGetDelivery(
            $publication,
            $user,
            $organization,
        );

        $this->provider(
            new LeaseTakeoverDistributionProvider(
                (string) $delivery->getKey(),
                false,
            ),
        );

        $this->dispatch($delivery, $user, $organization);

        $fresh = $this->delivery(
            $delivery,
            $user,
            $organization,
        );

        $this->assertSame(
            PublicationDelivery::STATUS_PROCESSING,
            $fresh->status,
        );
        $this->assertSame(2, $fresh->attempts);
        $this->assertNull($fresh->external_publication_id);
        $this->assertNull($fresh->published_at);
    }

    public function test_stale_worker_cannot_schedule_retry_over_newer_claim(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);
        $delivery = $this->queueAndGetDelivery(
            $publication,
            $user,
            $organization,
        );

        $this->provider(
            new LeaseTakeoverDistributionProvider(
                (string) $delivery->getKey(),
                true,
            ),
        );

        $this->dispatch($delivery, $user, $organization);

        $fresh = $this->delivery(
            $delivery,
            $user,
            $organization,
        );

        $this->assertSame(
            PublicationDelivery::STATUS_PROCESSING,
            $fresh->status,
        );
        $this->assertSame(2, $fresh->attempts);
        $this->assertNull($fresh->next_attempt_at);
        $this->assertNull($fresh->last_error_code);
    }

    public function test_transient_failures_stop_after_bounded_attempts(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);
        $provider = new SequenceDistributionProvider([
            DistributionProviderException::transient(),
            DistributionProviderException::transient(),
            DistributionProviderException::transient(),
            DistributionProviderException::transient(),
        ]);

        $this->provider($provider);
        $delivery = $this->queueAndGetDelivery(
            $publication,
            $user,
            $organization,
        );

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            if ($attempt > 1) {
                $this->makeRetryDue(
                    $delivery,
                    $user,
                    $organization,
                );
            }

            $this->dispatch($delivery, $user, $organization);
            $delivery = $this->delivery(
                $delivery,
                $user,
                $organization,
            );
        }

        $this->assertSame(
            PublicationDelivery::STATUS_FAILED,
            $delivery->status,
        );
        $this->assertSame(4, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(
            'distribution_transient_failure_exhausted',
            $delivery->last_error_code,
        );
        $this->assertSame(4, $provider->calls);

        $this->dispatch($delivery, $user, $organization);

        $this->assertSame(4, $provider->calls);
    }

    public function test_ineligible_schedule_is_never_sent_to_provider(): void
    {
        [$user, $organization] = $this->identity();
        $publication = $this->duePublication($user, $organization);
        $provider = new SequenceDistributionProvider([
            new DistributionResult('must-not-publish'),
        ]);

        $this->provider($provider);
        $delivery = $this->queueAndGetDelivery(
            $publication,
            $user,
            $organization,
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($publication): void {
                $asset = MediaAsset::query()
                    ->findOrFail($publication->media_asset_id);
                $metadata = $asset->metadata;
                $metadata['processing']['status'] = 'failed';

                $asset->forceFill(['metadata' => $metadata])->save();
            },
        );

        $this->dispatch($delivery, $user, $organization);

        $fresh = $this->delivery(
            $delivery,
            $user,
            $organization,
        );

        $this->assertSame(
            PublicationDelivery::STATUS_FAILED,
            $fresh->status,
        );
        $this->assertSame(
            'distribution_schedule_ineligible',
            $fresh->last_error_code,
        );
        $this->assertSame(0, $provider->calls);
    }

    public function test_cross_tenant_delivery_queue_is_rejected(): void
    {
        [$user, $organization] = $this->identity();
        [$otherUser, $otherOrganization] = $this->identity();
        $publication = $this->duePublication($user, $organization);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->runWithinOrganization(
            $otherUser,
            (string) $otherOrganization->getKey(),
            fn () => app(PublicationDeliveryManager::class)
                ->queue($publication, $otherUser),
        );
    }

    public function test_distribution_scheduler_is_safe_before_delivery_migration(): void
    {
        Schema::dropIfExists('publication_deliveries');

        $migrationPath = database_path(
            'migrations/2026_09_19_021500_create_publication_deliveries_table.php',
        );

        try {
            $this->assertSame(
                0,
                app(DistributionScheduler::class)->dispatchDue(),
            );
        } finally {
            $migration = require $migrationPath;
            $migration->up();
        }
    }

    /**
     * @return array{User, Organization}
     */
    private function identity(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Studio,
        ]);

        return [$user, $organization];
    }

    private function duePublication(
        User $user,
        Organization $organization,
    ): ScheduledPublication {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user): ScheduledPublication {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'organizations/test/blobs/'.fake()->uuid(),
                    'sha256' => hash('sha256', fake()->uuid()),
                    'byte_size' => 1024,
                    'mime_type' => 'image/jpeg',
                ]);

                $asset = MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => 'distribution-media.jpg',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                    'metadata' => [
                        'processing' => [
                            'version' => app(MediaAssetProcessor::class)
                                ->currentVersion(),
                            'status' => 'completed',
                            'attempts' => 1,
                            'last_error' => null,
                        ],
                    ],
                ]);

                $destination = PublishingDestination::query()->create([
                    'name' => 'Test distribution channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);

                return ScheduledPublication::query()->create([
                    'media_asset_id' => $asset->getKey(),
                    'publishing_destination_id' => $destination->getKey(),
                    'scheduled_by_user_id' => $user->getKey(),
                    'status' => ScheduledPublication::STATUS_SCHEDULED,
                    'scheduled_for_utc' => now('UTC')->subMinute(),
                    'timezone' => 'UTC',
                ]);
            },
        );
    }

    private function provider(
        SequenceDistributionProvider $provider,
    ): void {
        app(DistributionProviderRegistry::class)
            ->register('provider-test', $provider);
    }

    private function queueAndGetDelivery(
        ScheduledPublication $publication,
        User $user,
        Organization $organization,
    ): PublicationDelivery {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($publication, $user): PublicationDelivery {
                $this->assertTrue(
                    app(PublicationDeliveryManager::class)
                        ->queue($publication, $user),
                );

                return PublicationDelivery::query()->sole();
            },
        );
    }

    private function dispatch(
        PublicationDelivery $delivery,
        User $user,
        Organization $organization,
    ): void {
        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(PublicationDeliveryManager::class)
                ->dispatch(
                    PublicationDelivery::query()
                        ->findOrFail($delivery->getKey()),
                    $user,
                ),
        );
    }

    private function delivery(
        PublicationDelivery $delivery,
        User $user,
        Organization $organization,
    ): PublicationDelivery {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): PublicationDelivery => PublicationDelivery::query()
                ->findOrFail($delivery->getKey()),
        );
    }

    private function makeRetryDue(
        PublicationDelivery $delivery,
        User $user,
        Organization $organization,
    ): void {
        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($delivery): void {
                PublicationDelivery::query()
                    ->findOrFail($delivery->getKey())
                    ->forceFill([
                        'next_attempt_at' => now('UTC')->subSecond(),
                    ])
                    ->save();
            },
        );
    }
}

final class LeaseTakeoverDistributionProvider implements DistributionProvider
{
    public function __construct(
        private readonly string $deliveryId,
        private readonly bool $failTransiently,
    ) {}

    public function publish(
        ScheduledPublication $publication,
        string $idempotencyKey,
    ): DistributionResult {
        PublicationDelivery::query()
            ->whereKey($this->deliveryId)
            ->update([
                'status' => PublicationDelivery::STATUS_PROCESSING,
                'attempts' => 2,
                'claimed_until' => now('UTC')->addMinutes(5),
                'next_attempt_at' => null,
                'last_error_code' => null,
                'updated_at' => now(),
            ]);

        if ($this->failTransiently) {
            throw DistributionProviderException::transient();
        }

        return new DistributionResult('newer-claim-wins');
    }
}

final class SequenceDistributionProvider implements DistributionProvider
{
    public int $calls = 0;

    /**
     * @var list<string>
     */
    public array $idempotencyKeys = [];

    /**
     * @param  list<DistributionResult|DistributionProviderException>  $sequence
     */
    public function __construct(private array $sequence) {}

    public function publish(
        ScheduledPublication $publication,
        string $idempotencyKey,
    ): DistributionResult {
        $this->calls++;
        $this->idempotencyKeys[] = $idempotencyKey;

        $next = array_shift($this->sequence);

        if ($next instanceof DistributionProviderException) {
            throw $next;
        }

        if ($next instanceof DistributionResult) {
            return $next;
        }

        throw DistributionProviderException::transient();
    }
}
