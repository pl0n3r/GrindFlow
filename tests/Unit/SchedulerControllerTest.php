<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\User;
use App\Services\Media\MediaAssetProcessor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HTTP regression around the real legacy SchedulerController routes. Every
 * publication and identity is synthetic and isolated by RefreshDatabase.
 */
class SchedulerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_schedules_for_own_tenant_with_explicit_timezone(): void
    {
        [$editor, $organization] = $this->identity(UserRole::Editor);
        [$asset, $destination] = $this->fixtures($editor, $organization);
        $local = CarbonImmutable::now('America/Bogota')->addDays(3)
            ->startOfMinute()->format('Y-m-d\TH:i');
        $expectedUtc = CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i', $local, 'America/Bogota',
        )->setTimezone('UTC');

        $this->actingAs($editor)->post($this->storeUrl($organization), [
            'asset_id' => $asset->getKey(),
            'destination_ids' => [$destination->getKey()],
            'request_key' => (string) Str::uuid(),
            'scheduled_for_local' => $local,
            'timezone' => 'America/Bogota',
        ])->assertRedirect($this->indexUrl($organization));

        app(TenantContext::class)->runWithinOrganization(
            $editor,
            (string) $organization->getKey(),
            function () use ($asset, $destination, $expectedUtc): void {
                $publication = ScheduledPublication::query()->sole();
                $this->assertSame((string) $asset->getKey(), (string) $publication->media_asset_id);
                $this->assertSame((string) $destination->getKey(), (string) $publication->publishing_destination_id);
                $this->assertSame('America/Bogota', $publication->timezone);
                $this->assertSame(
                    $expectedUtc->format('Y-m-d H:i:s'),
                    $publication->scheduled_for_utc->format('Y-m-d H:i:s'),
                );
                $this->assertSame(ScheduledPublication::STATUS_SCHEDULED, $publication->status);
            },
        );
    }

    public function test_model_cannot_schedule_and_foreign_tenant_is_not_visible(): void
    {
        [$viewer, $mine] = $this->identity(UserRole::Model);
        [$asset, $destination] = $this->fixtures($viewer, $mine);
        $payload = $this->payload($asset, $destination);

        $this->actingAs($viewer)->post($this->storeUrl($mine), $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('scheduled_publications', 0);

        [$editor, $own] = $this->identity(UserRole::Editor);
        [$ownAsset] = $this->fixtures($editor, $own);
        [$stranger, $foreign] = $this->identity(UserRole::Editor);
        [, $foreignDestination] = $this->fixtures($stranger, $foreign);

        // A tenant-scoped destination lookup must not accept a global ID.
        $this->actingAs($editor)->post($this->storeUrl($own),
            $this->payload($ownAsset, $foreignDestination),
        )->assertNotFound();
        $this->actingAs($editor)->get($this->indexUrl($foreign))
            ->assertNotFound();
        $this->assertDatabaseCount('scheduled_publications', 0);
    }

    public function test_invalid_calendar_inputs_and_timezone_do_not_create_schedules(): void
    {
        [$editor, $organization] = $this->identity(UserRole::Editor);
        [$asset, $destination] = $this->fixtures($editor, $organization);
        $base = $this->payload($asset, $destination);

        $this->actingAs($editor)->post($this->storeUrl($organization), array_merge($base, [
            'scheduled_for_local' => 'not-a-date',
        ]))->assertSessionHasErrors('scheduled_for_local');
        $this->actingAs($editor)->post($this->storeUrl($organization), array_merge($base, [
            'timezone' => 'Invalid/Timezone',
        ]))->assertSessionHasErrors('timezone');
        $this->actingAs($editor)->post($this->storeUrl($organization), array_merge($base, [
            'scheduled_for_local' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i'),
        ]))->assertSessionHasErrors('scheduled_for_local');
        $this->assertDatabaseCount('scheduled_publications', 0);
    }

    /** @return array{User, Organization} */
    private function identity(UserRole $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::query()->create([
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'role' => $role,
        ]);

        return [$user, $organization];
    }

    /** @return array{MediaAsset, PublishingDestination} */
    private function fixtures(User $user, Organization $organization): array
    {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function (): array {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'organizations/synthetic/blobs/'.Str::uuid(),
                    'sha256' => hash('sha256', (string) Str::uuid()),
                    'byte_size' => 100,
                    'mime_type' => 'image/jpeg',
                ]);
                $asset = MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => 'synthetic-schedule.jpg',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                    'metadata' => [
                        'processing' => [
                            'status' => 'completed',
                            'version' => app(MediaAssetProcessor::class)->currentVersion(),
                            'attempts' => 1,
                            'last_error' => null,
                        ],
                    ],
                ]);
                $destination = PublishingDestination::query()->create([
                    'name' => 'Synthetic test destination',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);

                return [$asset, $destination];
            },
        );
    }

    /** @return array<string, mixed> */
    private function payload(MediaAsset $asset, PublishingDestination $destination): array
    {
        return [
            'asset_id' => $asset->getKey(),
            'destination_ids' => [$destination->getKey()],
            'request_key' => (string) Str::uuid(),
            'scheduled_for_local' => CarbonImmutable::now('UTC')->addDays(3)->format('Y-m-d\TH:i'),
            'timezone' => 'UTC',
        ];
    }

    private function storeUrl(Organization $organization): string
    {
        return route('organizations.scheduler.store', ['organizationId' => $organization->getKey()]);
    }

    private function indexUrl(Organization $organization): string
    {
        return route('organizations.scheduler.index', ['organizationId' => $organization->getKey()]);
    }
}
