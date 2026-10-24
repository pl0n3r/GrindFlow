<?php

namespace Tests\Unit;

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
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Tenant-bound HTTP behavior; all actors and data are synthetic. */
class DistributionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DispatchScheduledPublication::class]);
    }

    public function test_studio_can_create_only_sandbox_destinations(): void
    {
        [$actor, $org] = $this->identity();
        $url = route('organizations.distribution.destinations.store', [
            'organizationId' => $org->getKey(),
        ]);
        $this->actingAs($actor)->post($url, [
            'name' => '  QA sandbox  ',
            'provider' => 'sandbox',
        ])->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            function (): void {
                $destination = PublishingDestination::query()->sole();
                $this->assertSame('QA sandbox', $destination->name);
                $this->assertSame('sandbox', $destination->provider);
                $this->assertSame(PublishingDestination::STATUS_ACTIVE, $destination->status);
                $this->assertSame(['mode' => 'sandbox'], $destination->metadata);
            },
        );

        $this->actingAs($actor)->post($url, [
            'name' => 'External forbidden',
            'provider' => 'external',
        ])->assertSessionHasErrors('provider');
        $this->actingAs($actor)->post($url, [
            'name' => '',
            'provider' => 'sandbox',
        ])->assertSessionHasErrors('name');

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            fn () => $this->assertSame(1, PublishingDestination::query()->count()),
        );
        Queue::assertNothingPushed();
    }

    public function test_studio_can_update_own_destination(): void
    {
        [$actor, $org] = $this->identity();
        $destination = $this->destination($actor, $org);
        $this->actingAs($actor)->patch(route(
            'organizations.distribution.destinations.update',
            ['organizationId' => $org->getKey(), 'destinationId' => $destination->getKey()],
        ), [
            'name' => 'Updated owned sandbox',
            'status' => PublishingDestination::STATUS_DISABLED,
        ])->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            function () use ($destination): void {
                $this->assertSame('Updated owned sandbox', $destination->fresh()->name);
                $this->assertSame(PublishingDestination::STATUS_DISABLED, $destination->fresh()->status);
                $this->assertSame('sandbox', $destination->fresh()->provider);
            },
        );
        Queue::assertNothingPushed();
    }

    public function test_retry_budget_exhaustion_is_not_queued(): void
    {
        [$actor, $org] = $this->identity();
        $delivery = $this->delivery($actor, $org, PublicationDelivery::STATUS_FAILED);
        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            fn () => $delivery->forceFill([
                'attempts' => \App\Services\Distribution\PublicationDeliveryManager::MAX_ATTEMPTS,
            ])->save(),
        );

        $this->actingAs($actor)->post(route(
            'organizations.distribution.deliveries.retry',
            ['organizationId' => $org->getKey(), 'deliveryId' => $delivery->getKey()],
        ))->assertStatus(409);
        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            fn () => $this->assertSame(
                PublicationDelivery::STATUS_FAILED,
                $delivery->fresh()->status,
            ),
        );
        Queue::assertNothingPushed();
    }

    public function test_model_cannot_create_or_update_destinations(): void
    {
        [$owner, $org] = $this->identity();
        $model = User::factory()->create();
        Membership::query()->create([
            'organization_id' => $org->getKey(),
            'user_id' => $model->getKey(),
            'role' => UserRole::Model,
        ]);
        $destination = $this->destination($owner, $org);

        $this->actingAs($model)->post(route(
            'organizations.distribution.destinations.store',
            ['organizationId' => $org->getKey()],
        ), ['name' => 'Forbidden', 'provider' => 'sandbox'])->assertForbidden();

        $this->actingAs($model)->patch(route(
            'organizations.distribution.destinations.update',
            ['organizationId' => $org->getKey(), 'destinationId' => $destination->getKey()],
        ), [
            'name' => 'Forbidden update',
            'status' => PublishingDestination::STATUS_DISABLED,
        ])->assertForbidden();

        app(TenantContext::class)->runWithinOrganization(
            $owner,
            (string) $org->getKey(),
            function () use ($destination): void {
                $this->assertSame('QA destination', $destination->fresh()->name);
                $this->assertSame(PublishingDestination::STATUS_ACTIVE, $destination->fresh()->status);
                $this->assertSame(1, PublishingDestination::query()->count());
            },
        );
        Queue::assertNothingPushed();
    }

    public function test_foreign_destination_is_not_mutated_even_with_a_valid_local_studio(): void
    {
        [$local, $localOrg] = $this->identity();
        [$foreign, $foreignOrg] = $this->identity();
        $destination = $this->destination($foreign, $foreignOrg);

        $this->actingAs($local)->patch(route(
            'organizations.distribution.destinations.update',
            ['organizationId' => $localOrg->getKey(), 'destinationId' => $destination->getKey()],
        ), [
            'name' => 'Cross-tenant overwrite',
            'status' => PublishingDestination::STATUS_DISABLED,
        ])->assertNotFound();

        app(TenantContext::class)->runWithinOrganization(
            $foreign,
            (string) $foreignOrg->getKey(),
            function () use ($destination): void {
                $this->assertSame('QA destination', $destination->fresh()->name);
                $this->assertSame(PublishingDestination::STATUS_ACTIVE, $destination->fresh()->status);
            },
        );
        $this->actingAs($local)->get(route(
            'organizations.distribution.index',
            ['organizationId' => $localOrg->getKey()],
        ))->assertOk()->assertDontSee('QA destination');
    }

    public function test_destination_update_rejects_invalid_status_without_mutating_row(): void
    {
        [$actor, $org] = $this->identity();
        $destination = $this->destination($actor, $org);
        $this->actingAs($actor)->patch(route(
            'organizations.distribution.destinations.update',
            ['organizationId' => $org->getKey(), 'destinationId' => $destination->getKey()],
        ), [
            'name' => 'Should not update',
            'status' => 'published',
        ])->assertSessionHasErrors('status');

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            function () use ($destination): void {
                $this->assertSame('QA destination', $destination->fresh()->name);
                $this->assertSame(PublishingDestination::STATUS_ACTIVE, $destination->fresh()->status);
            },
        );
    }

    public function test_index_rejects_invalid_status_dates_and_pages(): void
    {
        [$actor, $org] = $this->identity();
        $url = route('organizations.distribution.index', [
            'organizationId' => $org->getKey(),
        ]);
        $this->actingAs($actor)->get($url)->assertOk();
        $this->actingAs($actor)->get($url.'?status=bogus')->assertSessionHasErrors('status');
        $this->actingAs($actor)->get($url.'?from=2026-10-25&to=2026-10-24')
            ->assertSessionHasErrors('to');
        $this->actingAs($actor)->get($url.'?destination_id=invalid')
            ->assertSessionHasErrors('destination_id');
        $this->actingAs($actor)->get($url.'?page=0')->assertSessionHasErrors('page');
        $this->actingAs($actor)->get($url.'?page=10001')->assertSessionHasErrors('page');
    }

    public function test_retry_rejects_foreign_delivery_and_non_retryable_states(): void
    {
        [$actor, $org] = $this->identity();
        [$foreign, $foreignOrg] = $this->identity();
        $queued = $this->delivery($actor, $org, PublicationDelivery::STATUS_QUEUED);
        $alien = $this->delivery($foreign, $foreignOrg, PublicationDelivery::STATUS_FAILED);

        $this->actingAs($actor)->post(route(
            'organizations.distribution.deliveries.retry',
            ['organizationId' => $org->getKey(), 'deliveryId' => $queued->getKey()],
        ))->assertStatus(409);
        $this->actingAs($actor)->post(route(
            'organizations.distribution.deliveries.retry',
            ['organizationId' => $org->getKey(), 'deliveryId' => $alien->getKey()],
        ))->assertNotFound();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            fn () => $this->assertSame(PublicationDelivery::STATUS_QUEUED, $queued->fresh()->status),
        );
        app(TenantContext::class)->runWithinOrganization(
            $foreign,
            (string) $foreignOrg->getKey(),
            fn () => $this->assertSame(PublicationDelivery::STATUS_FAILED, $alien->fresh()->status),
        );
        Queue::assertNothingPushed();
    }

    /** @return array{User, Organization} */
    private function identity(): array
    {
        $actor = User::factory()->create();
        $org = Organization::factory()->create();
        Membership::query()->create([
            'organization_id' => $org->getKey(),
            'user_id' => $actor->getKey(),
            'role' => UserRole::Studio,
        ]);

        return [$actor, $org];
    }

    private function destination(User $actor, Organization $org): PublishingDestination
    {
        return app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            fn (): PublishingDestination => PublishingDestination::query()->create([
                'name' => 'QA destination',
                'provider' => 'sandbox',
                'status' => PublishingDestination::STATUS_ACTIVE,
            ]),
        );
    }

    private function delivery(User $actor, Organization $org, string $status): PublicationDelivery
    {
        return app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $org->getKey(),
            function () use ($actor, $status): PublicationDelivery {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'organizations/qa/blobs/'.fake()->uuid(),
                    'sha256' => hash('sha256', fake()->uuid()),
                    'byte_size' => 1024,
                    'mime_type' => 'image/jpeg',
                ]);
                $asset = MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => 'synthetic.jpg',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                ]);
                $destination = PublishingDestination::query()->create([
                    'name' => 'Queue fixture',
                    'provider' => 'sandbox',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);
                $schedule = ScheduledPublication::query()->create([
                    'media_asset_id' => $asset->getKey(),
                    'publishing_destination_id' => $destination->getKey(),
                    'scheduled_by_user_id' => $actor->getKey(),
                    'status' => ScheduledPublication::STATUS_SCHEDULED,
                    'scheduled_for_utc' => now('UTC')->subMinute(),
                    'timezone' => 'UTC',
                ]);
                return PublicationDelivery::query()->create([
                    'scheduled_publication_id' => $schedule->getKey(),
                    'idempotency_key' => 'qa-'.$schedule->getKey(),
                    'status' => $status,
                    'attempts' => 0,
                ]);
            },
        );
    }
}
