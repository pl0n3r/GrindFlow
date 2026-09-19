<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_schedule_currently_processed_media_with_explicit_timezone(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Editor);

        [$asset, $destination] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): array => [
                $this->readyAsset(),
                PublishingDestination::query()->create([
                    'name' => 'Primary channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]),
            ],
        );

        $timezone = 'America/Bogota';
        $local = CarbonImmutable::now($timezone)
            ->addDay()
            ->startOfMinute()
            ->format('Y-m-d\TH:i');

        $this->actingAs($user)
            ->post(
                route('organizations.scheduler.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'asset_id' => $asset->getKey(),
                    'destination_id' => $destination->getKey(),
                    'scheduled_for_local' => $local,
                    'timezone' => $timezone,
                ],
            )
            ->assertRedirect(
                route('organizations.scheduler.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )
            ->assertSessionHas('status');

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use (
                $asset,
                $destination,
                $local,
                $timezone,
                $user,
            ): void {
                $publication = ScheduledPublication::query()->firstOrFail();

                $expectedUtc = CarbonImmutable::createFromFormat(
                    '!Y-m-d\TH:i',
                    $local,
                    $timezone,
                )->setTimezone('UTC');

                $this->assertSame($asset->getKey(), $publication->media_asset_id);
                $this->assertSame(
                    $destination->getKey(),
                    $publication->publishing_destination_id,
                );
                $this->assertSame($user->getKey(), $publication->scheduled_by_user_id);
                $this->assertSame($timezone, $publication->timezone);
                $this->assertSame(
                    $expectedUtc->format('Y-m-d H:i:s'),
                    $publication->scheduled_for_utc->format('Y-m-d H:i:s'),
                );
                $this->assertSame(
                    ScheduledPublication::STATUS_SCHEDULED,
                    $publication->status,
                );
            },
        );
    }

    public function test_scheduled_utc_is_read_as_utc_with_non_utc_application_timezone(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Editor);

        [$asset, $destination] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): array => [
                $this->readyAsset(),
                PublishingDestination::query()->create([
                    'name' => 'Primary channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]),
            ],
        );

        $timezone = 'America/Bogota';
        $local = CarbonImmutable::now($timezone)
            ->addDays(2)
            ->startOfMinute()
            ->format('Y-m-d\TH:i');
        $expectedUtc = CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $local,
            $timezone,
        )->setTimezone('UTC');

        $this->actingAs($user)
            ->post(
                route('organizations.scheduler.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'asset_id' => $asset->getKey(),
                    'destination_id' => $destination->getKey(),
                    'scheduled_for_local' => $local,
                    'timezone' => $timezone,
                ],
            )
            ->assertRedirect();

        $originalPhpTimezone = date_default_timezone_get();
        $originalAppTimezone = (string) config('app.timezone');

        try {
            date_default_timezone_set('America/New_York');
            config(['app.timezone' => 'America/New_York']);

            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                function () use ($expectedUtc): void {
                    $publication = ScheduledPublication::query()->firstOrFail();

                    $this->assertSame(
                        'UTC',
                        $publication->scheduled_for_utc->getTimezone()->getName(),
                    );
                    $this->assertSame(
                        $expectedUtc->format('Y-m-d H:i:s'),
                        $publication->scheduled_for_utc->format('Y-m-d H:i:s'),
                    );
                },
            );
        } finally {
            config(['app.timezone' => $originalAppTimezone]);
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function test_incomplete_or_stale_media_cannot_enter_scheduled_state(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        [$assets, $destination] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function (): array {
                $currentVersion = app(MediaAssetProcessor::class)->currentVersion();
                $cases = [
                    [
                        'version' => $currentVersion,
                        'status' => 'failed',
                        'attempts' => 1,
                        'last_error' => 'processing_failed',
                    ],
                    [
                        'version' => $currentVersion,
                        'status' => 'queued',
                        'attempts' => 0,
                        'last_error' => null,
                    ],
                    [
                        'version' => max(0, $currentVersion - 1),
                        'status' => 'completed',
                        'attempts' => 1,
                        'last_error' => null,
                    ],
                ];

                $assets = collect($cases)
                    ->map(fn (array $processing): MediaAsset => $this->readyAsset([
                        'processing' => $processing,
                    ]))
                    ->all();

                $destination = PublishingDestination::query()->create([
                    'name' => 'Primary channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);

                return [$assets, $destination];
            },
        );

        foreach ($assets as $asset) {
            $this->from(
                route('organizations.scheduler.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )->actingAs($user)
                ->post(
                    route('organizations.scheduler.store', [
                        'organizationId' => $organization->getKey(),
                    ]),
                    [
                        'asset_id' => $asset->getKey(),
                        'destination_id' => $destination->getKey(),
                        'scheduled_for_local' => now('UTC')
                            ->addDay()
                            ->format('Y-m-d\TH:i'),
                        'timezone' => 'UTC',
                    ],
                )
                ->assertRedirect()
                ->assertSessionHasErrors('asset_id');
        }

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                0,
                ScheduledPublication::query()->count(),
            ),
        );
    }

    public function test_disabled_destination_cannot_be_scheduled(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        [$asset, $destination] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): array => [
                $this->readyAsset(),
                PublishingDestination::query()->create([
                    'name' => 'Disabled channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_DISABLED,
                ]),
            ],
        );

        $this->actingAs($user)
            ->post(
                route('organizations.scheduler.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'asset_id' => $asset->getKey(),
                    'destination_id' => $destination->getKey(),
                    'scheduled_for_local' => now('UTC')
                        ->addDay()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'UTC',
                ],
            )
            ->assertSessionHasErrors('destination_id');

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                0,
                ScheduledPublication::query()->count(),
            ),
        );
    }

    public function test_model_role_cannot_create_schedules(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Model);

        [$asset, $destination] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): array => [
                $this->readyAsset(),
                PublishingDestination::query()->create([
                    'name' => 'Primary channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]),
            ],
        );

        $this->actingAs($user)
            ->post(
                route('organizations.scheduler.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'asset_id' => $asset->getKey(),
                    'destination_id' => $destination->getKey(),
                    'scheduled_for_local' => now('UTC')
                        ->addDay()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'UTC',
                ],
            )
            ->assertForbidden();
    }

    public function test_cross_tenant_destination_is_not_visible_to_scheduler_post(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);
        $this->membership($otherUser, $otherOrganization, UserRole::Studio);

        $asset = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaAsset => $this->readyAsset(),
        );

        $foreignDestination = app(TenantContext::class)->runWithinOrganization(
            $otherUser,
            (string) $otherOrganization->getKey(),
            fn (): PublishingDestination => PublishingDestination::query()->create([
                'name' => 'Foreign channel',
                'provider' => 'provider-test',
                'status' => PublishingDestination::STATUS_ACTIVE,
            ]),
        );

        $this->actingAs($user)
            ->post(
                route('organizations.scheduler.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'asset_id' => $asset->getKey(),
                    'destination_id' => $foreignDestination->getKey(),
                    'scheduled_for_local' => now('UTC')
                        ->addDay()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'UTC',
                ],
            )
            ->assertNotFound();
    }

    public function test_timezone_must_be_explicit_and_valid(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Editor);

        [$asset, $destination] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): array => [
                $this->readyAsset(),
                PublishingDestination::query()->create([
                    'name' => 'Primary channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]),
            ],
        );

        $this->actingAs($user)
            ->post(
                route('organizations.scheduler.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'asset_id' => $asset->getKey(),
                    'destination_id' => $destination->getKey(),
                    'scheduled_for_local' => now('UTC')
                        ->addDay()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'Not/A_Timezone',
                ],
            )
            ->assertSessionHasErrors('timezone');
    }

    public function test_scheduler_page_is_scoped_to_the_requested_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Editor);

        $this->actingAs($user)
            ->get(
                route('organizations.scheduler.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )
            ->assertOk()
            ->assertSee('Scheduler');

        $this->actingAs($user)
            ->get(
                route('organizations.scheduler.index', [
                    'organizationId' => $otherOrganization->getKey(),
                ]),
            )
            ->assertNotFound();
    }

    public function test_scheduler_endpoints_are_safe_before_scheduling_migration(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Editor);

        Schema::dropIfExists('scheduled_publications');
        Schema::dropIfExists('publishing_destinations');

        $migrationPath = database_path(
            'migrations/2026_09_18_200000_create_scheduling_tables.php',
        );

        try {
            $route = route('organizations.scheduler.index', [
                'organizationId' => $organization->getKey(),
            ]);

            $this->actingAs($user)
                ->get($route)
                ->assertOk()
                ->assertSee('Scheduling migration required.');

            $this->actingAs($user)
                ->post($route, [
                    'asset_id' => fake()->uuid(),
                    'destination_id' => fake()->uuid(),
                    'scheduled_for_local' => now('UTC')
                        ->addDay()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'UTC',
                ])
                ->assertStatus(503);
        } finally {
            $migration = require $migrationPath;
            $migration->up();
        }
    }

    private function readyAsset(?array $metadata = null): MediaAsset
    {
        $blob = MediaBlob::query()->create([
            'storage_disk' => 'local',
            'storage_key' => 'organizations/test/blobs/'.fake()->uuid(),
            'sha256' => hash('sha256', fake()->uuid()),
            'byte_size' => 1024,
            'mime_type' => 'image/jpeg',
        ]);

        return MediaAsset::query()->create([
            'media_blob_id' => $blob->getKey(),
            'original_filename' => 'scheduled-media.jpg',
            'source_type' => 'manual_upload',
            'status' => MediaAsset::STATUS_READY,
            'metadata' => $metadata ?? [
                'processing' => [
                    'version' => app(MediaAssetProcessor::class)->currentVersion(),
                    'status' => 'completed',
                    'attempts' => 1,
                    'last_error' => null,
                ],
            ],
        ]);
    }

    private function membership(
        User $user,
        Organization $organization,
        UserRole $role,
    ): Membership {
        return Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
    }
}
