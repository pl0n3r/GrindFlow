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
use App\Services\Scheduling\ContentScheduler;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
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
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
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
                        'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
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

    public function test_duplicate_asset_cannot_be_scheduled(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        [$duplicate, $destination] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function (): array {
                $canonical = $this->readyAsset();
                $duplicate = MediaAsset::query()->create([
                    'media_blob_id' => $canonical->media_blob_id,
                    'duplicate_of' => $canonical->getKey(),
                    'original_filename' => 'duplicate-media.jpg',
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
                    'name' => 'Primary channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);

                return [$duplicate, $destination];
            },
        );

        $this->actingAs($user)
            ->post(
                route('organizations.scheduler.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'asset_id' => $duplicate->getKey(),
                    'destination_id' => $destination->getKey(),
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
                    'scheduled_for_local' => now('UTC')
                        ->addDay()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'UTC',
                ],
            )
            ->assertSessionHasErrors('asset_id');

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                0,
                ScheduledPublication::query()->count(),
            ),
        );
    }

    public function test_past_time_cannot_be_scheduled(): void
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
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
                    'scheduled_for_local' => now('UTC')
                        ->subHour()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'UTC',
                ],
            )
            ->assertSessionHasErrors('scheduled_for_local');

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
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
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
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
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

        [$asset, $localDestination] = app(TenantContext::class)
            ->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn (): array => [
                    $this->readyAsset(),
                    PublishingDestination::query()->create([
                        'name' => 'Local channel',
                        'provider' => 'provider-test',
                        'status' => PublishingDestination::STATUS_ACTIVE,
                    ]),
                ],
            );

        [$foreignAsset, $foreignDestination] = app(TenantContext::class)
            ->runWithinOrganization(
                $otherUser,
                (string) $otherOrganization->getKey(),
                fn (): array => [
                    $this->readyAsset(),
                    PublishingDestination::query()->create([
                        'name' => 'Foreign channel',
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
                    'destination_id' => $foreignDestination->getKey(),
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
                    'scheduled_for_local' => now('UTC')
                        ->addDay()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'UTC',
                ],
            )
            ->assertNotFound();

        $this->actingAs($user)
            ->post(
                route('organizations.scheduler.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'asset_id' => $foreignAsset->getKey(),
                    'destination_id' => $localDestination->getKey(),
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
                    'scheduled_for_local' => now('UTC')
                        ->addDay()
                        ->format('Y-m-d\TH:i'),
                    'timezone' => 'UTC',
                ],
            )
            ->assertNotFound();

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                0,
                ScheduledPublication::query()->count(),
            ),
        );
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
                    'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
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
        $modelUser = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Editor);
        $this->membership($modelUser, $organization, UserRole::Model);

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
                ->post($route, [])
                ->assertStatus(503);

            $this->actingAs($modelUser)
                ->post($route, [])
                ->assertStatus(503);
        } finally {
            $migration = require $migrationPath;
            $migration->up();
        }
    }

    public function test_scheduler_rechecks_locked_asset_before_creating_publication(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user): void {
                $asset = $this->readyAsset();
                $destination = PublishingDestination::query()->create([
                    'name' => 'Primary channel',
                    'provider' => 'provider-test',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);

                $concurrentAsset = MediaAsset::query()
                    ->findOrFail($asset->getKey());
                $concurrentAsset->forceFill([
                    'metadata' => [
                        'processing' => [
                            'version' => app(MediaAssetProcessor::class)
                                ->currentVersion(),
                            'status' => 'processing',
                            'attempts' => 2,
                            'last_error' => null,
                        ],
                    ],
                ])->save();

                try {
                    app(ContentScheduler::class)->schedule(
                        $asset,
                        $destination,
                        $user,
                        now('UTC')->addDay()->format('Y-m-d\TH:i'),
                        'UTC',
                    );

                    $this->fail(
                        'A stale in-memory asset must be revalidated before scheduling.',
                    );
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey(
                        'asset_id',
                        $exception->errors(),
                    );
                }

                $this->assertSame(
                    0,
                    ScheduledPublication::query()->count(),
                );
            },
        );
    }

    public function test_one_request_schedules_multiple_destinations_idempotently(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, UserRole::Editor);

        [$asset, $destinations] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): array => [
                $this->readyAsset(),
                collect(['A', 'B'])->map(fn (string $name) => PublishingDestination::query()->create([
                    'name' => $name,
                    'provider' => 'sandbox',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ])),
            ],
        );

        $payload = [
            'asset_id' => $asset->getKey(),
            'destination_ids' => $destinations->pluck('id')->all(),
            'request_key' => '7ee97a13-e8c9-4325-82a6-2ab64f83572d',
            'scheduled_for_local' => now('UTC')->addDay()->format('Y-m-d\TH:i'),
            'timezone' => 'UTC',
        ];
        $route = route('organizations.scheduler.store', ['organizationId' => $organization->getKey()]);

        $withoutKey = $payload;
        unset($withoutKey['request_key']);
        $this->actingAs($user)->post($route, $withoutKey)
            ->assertSessionHasErrors('request_key');

        $this->actingAs($user)->post($route, $payload)->assertRedirect();
        $this->actingAs($user)->post($route, $payload)->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(2, ScheduledPublication::query()->count()),
        );
    }

    public function test_future_undelivered_schedule_can_be_edited_and_cancelled(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, UserRole::Studio);

        $publication = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user): ScheduledPublication {
                $destination = PublishingDestination::query()->create([
                    'name' => 'Sandbox',
                    'provider' => 'sandbox',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);

                return app(ContentScheduler::class)->schedule(
                    $this->readyAsset(),
                    $destination,
                    $user,
                    now('UTC')->addDays(2)->format('Y-m-d\TH:i'),
                    'UTC',
                );
            },
        );

        $rescheduledFor = CarbonImmutable::now('UTC')->addDays(3)->startOfMinute();

        $this->actingAs($user)->patch(route('organizations.scheduler.update', [
            'organizationId' => $organization->getKey(),
        ]), [
            'publication_id' => $publication->getKey(),
            'scheduled_for_local' => $rescheduledFor->format('Y-m-d\TH:i'),
            'timezone' => 'UTC',
        ])->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                $rescheduledFor->format('Y-m-d\TH:i'),
                $publication->fresh()->scheduled_for_utc?->format('Y-m-d\TH:i'),
            ),
        );

        $this->actingAs($user)->post(route('organizations.scheduler.cancel', [
            'organizationId' => $organization->getKey(),
        ]), ['publication_id' => $publication->getKey()])->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->assertSame(ScheduledPublication::STATUS_CANCELLED, $publication->fresh()->status),
        );
    }

    public function test_calendar_paginates_all_matching_schedules_without_cross_tenant_leaks(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherActor = User::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $this->membership($actor, $organization, UserRole::Editor);
        $this->membership($otherActor, $otherOrganization, UserRole::Editor);

        $utc = CarbonImmutable::now('UTC')->addDays(2)->startOfMinute();
        $scheduledIds = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor, $utc): array {
                $asset = $this->readyAsset();
                $active = PublishingDestination::query()->create([
                    'name' => 'Current channel',
                    'provider' => 'sandbox',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);
                $archived = PublishingDestination::query()->create([
                    'name' => 'Archived channel',
                    'provider' => 'sandbox',
                    'status' => PublishingDestination::STATUS_DISABLED,
                ]);

                $ids = [];

                // Identical UTC timestamps require a stable secondary UUID
                // ordering, including across the old 100-row preview cap.
                for ($i = 0; $i < 105; $i++) {
                    $ids[] = ScheduledPublication::query()->create([
                        'media_asset_id' => $asset->getKey(),
                        'publishing_destination_id' => $active->getKey(),
                        'scheduled_by_user_id' => $actor->getKey(),
                        'status' => ScheduledPublication::STATUS_SCHEDULED,
                        'scheduled_for_utc' => $utc,
                        'timezone' => 'UTC',
                    ])->getKey();
                }

                ScheduledPublication::query()->create([
                    'media_asset_id' => $asset->getKey(),
                    'publishing_destination_id' => $archived->getKey(),
                    'scheduled_by_user_id' => $actor->getKey(),
                    'status' => ScheduledPublication::STATUS_CANCELLED,
                    'scheduled_for_utc' => $utc,
                    'timezone' => 'UTC',
                ]);

                sort($ids, SORT_STRING);

                return $ids;
            },
        );

        app(TenantContext::class)->runWithinOrganization(
            $otherActor,
            (string) $otherOrganization->getKey(),
            function () use ($otherActor, $utc): void {
                $asset = $this->readyAsset();
                $destination = PublishingDestination::query()->create([
                    'name' => 'Foreign private channel',
                    'provider' => 'sandbox',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);

                for ($i = 0; $i < 3; $i++) {
                    ScheduledPublication::query()->create([
                        'media_asset_id' => $asset->getKey(),
                        'publishing_destination_id' => $destination->getKey(),
                        'scheduled_by_user_id' => $otherActor->getKey(),
                        'status' => ScheduledPublication::STATUS_SCHEDULED,
                        'scheduled_for_utc' => $utc,
                        'timezone' => 'UTC',
                    ]);
                }
            },
        );

        $route = route('organizations.scheduler.index', [
            'organizationId' => $organization->getKey(),
        ]);

        $first = $this->actingAs($actor)->get($route)
            ->assertOk()
            ->assertSee('106 matching')
            ->assertSee('Showing 1–25 of 106')
            ->assertSee('Page 1 of 5')
            ->assertSee('Calendar preview reflects this page')
            ->assertSee('Archived channel · inactive')
            ->assertDontSee('Foreign private channel');

        $firstPage = $first->viewData('publications');

        $this->assertSame(106, $firstPage->total());
        $this->assertSame(25, $firstPage->count());
        $this->assertSame(
            array_slice($scheduledIds, 0, 25),
            $firstPage->getCollection()->pluck('id')->all(),
        );
        $this->assertSame(
            25,
            $first->viewData('calendarDays')->sum(
                fn ($items): int => $items->count(),
            ),
        );
        $this->assertSame(
            ['Current channel'],
            $first->viewData('destinations')->pluck('name')->all(),
        );

        $second = $this->actingAs($actor)->get($firstPage->nextPageUrl())
            ->assertOk()
            ->assertSee('Showing 26–50 of 106')
            ->assertSee('Page 2 of 5');

        $this->assertSame(
            array_slice($scheduledIds, 25, 25),
            $second->viewData('publications')->getCollection()->pluck('id')->all(),
        );

        $last = $this->actingAs($actor)->get($route.'?page=5')
            ->assertOk()
            ->assertSee('Showing 101–106 of 106')
            ->assertSee('Page 5 of 5');

        $this->assertSame(6, $last->viewData('publications')->count());

        $this->actingAs($actor)->get($route.'?page=8')
            ->assertOk()
            ->assertSee('No schedules on this page.')
            ->assertSee('Go to last available page')
            ->assertDontSee('No matching schedules.');

        $this->actingAs($actor)->get($route.'?page=0')
            ->assertSessionHasErrors('page');
        $this->actingAs($actor)->get($route.'?page=10001')
            ->assertSessionHasErrors('page');
    }

    public function test_calendar_preserves_filters_across_pages_including_disabled_destinations(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($actor, $organization, UserRole::Editor);

        $date = CarbonImmutable::now('UTC')->addDays(3)->startOfMinute();

        [$active, $disabled] = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor, $date): array {
                $asset = $this->readyAsset();
                $active = PublishingDestination::query()->create([
                    'name' => 'Available channel',
                    'provider' => 'sandbox',
                    'status' => PublishingDestination::STATUS_ACTIVE,
                ]);
                $disabled = PublishingDestination::query()->create([
                    'name' => 'Retired channel',
                    'provider' => 'sandbox',
                    'status' => PublishingDestination::STATUS_DISABLED,
                ]);

                for ($i = 0; $i < 28; $i++) {
                    ScheduledPublication::query()->create([
                        'media_asset_id' => $asset->getKey(),
                        'publishing_destination_id' => $disabled->getKey(),
                        'scheduled_by_user_id' => $actor->getKey(),
                        'status' => ScheduledPublication::STATUS_CANCELLED,
                        'scheduled_for_utc' => $date,
                        'timezone' => 'UTC',
                    ]);
                }

                ScheduledPublication::query()->create([
                    'media_asset_id' => $asset->getKey(),
                    'publishing_destination_id' => $active->getKey(),
                    'scheduled_by_user_id' => $actor->getKey(),
                    'status' => ScheduledPublication::STATUS_SCHEDULED,
                    'scheduled_for_utc' => $date,
                    'timezone' => 'UTC',
                ]);

                return [$active, $disabled];
            },
        );

        $route = route('organizations.scheduler.index', [
            'organizationId' => $organization->getKey(),
        ]);
        $filters = [
            'status' => 'cancelled',
            'destination_id' => $disabled->getKey(),
            'from' => $date->format('Y-m-d'),
            'to' => $date->format('Y-m-d'),
        ];

        $first = $this->actingAs($actor)->get($route.'?'.http_build_query($filters))
            ->assertOk()
            ->assertSee('28 matching')
            ->assertSee('Showing 1–25 of 28')
            ->assertSee('Retired channel · inactive');

        $page = $first->viewData('publications');
        $this->assertSame(28, $page->total());
        $this->assertSame(25, $page->count());
        $this->assertFalse($first->viewData('destinations')->contains('id', $disabled->getKey()));
        $this->assertTrue($first->viewData('destinations')->contains('id', $active->getKey()));

        $next = $page->nextPageUrl();
        $this->assertIsString($next);
        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);

        $this->assertSame('2', $query['page']);
        unset($query['page']);
        $this->assertEquals($filters, $query);

        $second = $this->actingAs($actor)->get($next)
            ->assertOk()
            ->assertSee('Showing 26–28 of 28')
            ->assertSee('Page 2 of 2');

        $this->assertSame(3, $second->viewData('publications')->count());

        $this->actingAs($actor)->get($route.'?status=scheduled&destination_id='.$disabled->getKey())
            ->assertOk()
            ->assertSee('No matching schedules.');
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
