<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\TrackedLink;
use App\Models\User;
use App\Services\Media\MediaAssetProcessor;
use App\Services\Scheduling\ContentScheduler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulerPickerSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_and_link_search_reach_entries_past_first_hundred_without_tenant_leak(): void
    {
        [$actor, $organization] = $this->identity();
        [$foreigner, $foreignOrganization] = $this->identity();

        [$deepAsset, $deepLink, $destination] = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor): array {
                $deepAsset = $this->asset('ZZ-original-media-needle.jpg');
                $deepLink = $this->link($actor, 'ZZ-deep-link-needle', 'deep-autumn-campaign');
                $destination = $this->destination();

                for ($i = 0; $i < 105; $i++) {
                    $this->asset('Current file '.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'.jpg');
                    $this->link($actor, 'AAA Link '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'general');
                }

                return [$deepAsset, $deepLink, $destination];
            },
        );

        app(TenantContext::class)->runWithinOrganization(
            $foreigner,
            (string) $foreignOrganization->getKey(),
            function () use ($foreigner): void {
                $this->asset('ZZ-original-media-needle-foreign.jpg');
                $this->link($foreigner, 'ZZ-deep-link-needle-foreign', 'deep-autumn-campaign');
            },
        );

        $route = $this->indexRoute($organization);
        $default = $this->actingAs($actor)->get($route)
            ->assertOk()
            ->assertSee('106 eligible total')
            ->assertSee('Only 100 matching media options')
            ->assertSee('Only 100 matching active links')
            ->assertSee('Find media &amp; tracked links', false);

        $this->assertSame(106, $default->viewData('eligibleAssetCount'));
        $this->assertSame(106, $default->viewData('assetMatches'));
        $this->assertSame(106, $default->viewData('linkMatches'));
        $this->assertCount(100, $default->viewData('eligibleAssets'));
        $this->assertCount(100, $default->viewData('trackedLinks'));
        $this->assertFalse($default->viewData('eligibleAssets')->contains('id', $deepAsset->getKey()));
        $this->assertFalse($default->viewData('trackedLinks')->contains('id', $deepLink->getKey()));

        $search = $this->actingAs($actor)->get(
            $route.'?'.http_build_query([
                'media_q' => 'original-media-needle',
                'link_q' => 'deep-autumn-campaign',
            ]),
        )->assertOk()
            ->assertSee($deepAsset->original_filename)
            ->assertSee($deepLink->label)
            ->assertDontSee('ZZ-original-media-needle-foreign')
            ->assertDontSee('ZZ-deep-link-needle-foreign');

        $this->assertSame(1, $search->viewData('assetMatches'));
        $this->assertSame(1, $search->viewData('linkMatches'));
        $this->assertSame(
            [$deepAsset->getKey()],
            $search->viewData('eligibleAssets')->pluck('id')->all(),
        );
        $this->assertSame(
            [$deepLink->getKey()],
            $search->viewData('trackedLinks')->pluck('id')->all(),
        );

        $byId = $this->actingAs($actor)->get(
            $route.'?media_q='.$deepAsset->getKey().'&link_q='.$deepLink->token,
        )->assertOk();
        $this->assertSame(1, $byId->viewData('assetMatches'));
        $this->assertSame(1, $byId->viewData('linkMatches'));

        $this->actingAs($actor)
            ->post($route, [
                'asset_id' => $deepAsset->getKey(),
                'destination_ids' => [$destination->getKey()],
                'request_key' => (string) Str::uuid(),
                'tracked_link_id' => $deepLink->getKey(),
                'scheduled_for_local' => now('UTC')->addDays(3)->format('Y-m-d\TH:i'),
                'timezone' => 'UTC',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($deepAsset, $deepLink): void {
                $publication = ScheduledPublication::query()->sole();
                $this->assertSame($deepAsset->getKey(), $publication->media_asset_id);
                $this->assertSame(
                    $deepLink->getKey(),
                    $publication->linkAssignment?->tracked_link_id,
                );
            },
        );

        $this->actingAs($actor)->get($route.'?media_q='.str_repeat('a', 101))
            ->assertSessionHasErrors('media_q');
        $this->actingAs($actor)->get($route.'?link_q='.str_repeat('b', 101))
            ->assertSessionHasErrors('link_q');
    }

    public function test_picker_keeps_valid_old_and_assigned_items_without_exposing_disabled_or_foreign_links(): void
    {
        [$actor, $organization] = $this->identity();
        [$foreignActor, $foreignOrganization] = $this->identity();

        [$asset, $deepLink, $destination, $publication] = app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor): array {
                $asset = $this->asset('Retain-old-selection.jpg');
                $deepLink = $this->link($actor, 'ZZ-retained-link', 'archive');
                $destination = $this->destination();

                for ($i = 0; $i < 104; $i++) {
                    $this->link($actor, 'AAA filler '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'general');
                }

                $publication = app(ContentScheduler::class)->schedule(
                    $asset,
                    $destination,
                    $actor,
                    now('UTC')->addDays(2)->format('Y-m-d\TH:i'),
                    'UTC',
                    (string) $deepLink->getKey(),
                );

                return [$asset, $deepLink, $destination, $publication];
            },
        );

        $foreign = app(TenantContext::class)->runWithinOrganization(
            $foreignActor,
            (string) $foreignOrganization->getKey(),
            fn (): TrackedLink => $this->link($foreignActor, 'Foreign-only-link', 'private'),
        );

        $route = $this->indexRoute($organization);
        $view = $this->actingAs($actor)->get($route.'?link_q=AAA')
            ->assertOk()
            ->assertSee('ZZ-retained-link')
            ->assertDontSee('Foreign-only-link');

        $this->assertSame(104, $view->viewData('linkMatches'));
        $this->assertTrue($view->viewData('trackedLinks')->contains('id', $deepLink->getKey()));
        $this->assertTrue($view->viewData('publications')->getCollection()->contains('id', $publication->getKey()));

        $old = $this->actingAs($actor)->withSession([
            '_old_input' => [
                'asset_id' => $asset->getKey(),
                'tracked_link_id' => $deepLink->getKey(),
            ],
        ])->get($route.'?media_q=not-there&link_q=not-there')
            ->assertOk()
            ->assertSee('Retain-old-selection.jpg')
            ->assertSee('ZZ-retained-link')
            ->assertSee('No matching eligible media.');

        $this->assertSame(0, $old->viewData('assetMatches'));
        $this->assertSame(0, $old->viewData('linkMatches'));
        $this->assertTrue($old->viewData('eligibleAssets')->contains('id', $asset->getKey()));
        $this->assertTrue($old->viewData('trackedLinks')->contains('id', $deepLink->getKey()));

        $foreignOld = $this->actingAs($actor)->withSession([
            '_old_input' => ['tracked_link_id' => $foreign->getKey()],
        ])->get($route.'?link_q=not-there')
            ->assertOk();
        $this->assertFalse($foreignOld->viewData('trackedLinks')->contains('id', $foreign->getKey()));

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            fn () => TrackedLink::query()->findOrFail($deepLink->getKey())
                ->forceFill(['status' => TrackedLink::STATUS_DISABLED])
                ->save(),
        );

        $disabled = $this->actingAs($actor)->get($route.'?link_q=not-there')
            ->assertOk()
            ->assertSee('Current link unavailable. Choose another or remove.');

        $this->assertFalse($disabled->viewData('trackedLinks')->contains('id', $deepLink->getKey()));
        $this->assertSame(0, $disabled->viewData('linkMatches'));
        $this->assertSame($destination->getKey(), $publication->publishing_destination_id);
    }

    public function test_search_and_calendar_filters_coexist_and_missing_link_migration_is_safe(): void
    {
        [$actor, $organization] = $this->identity();

        app(TenantContext::class)->runWithinOrganization(
            $actor,
            (string) $organization->getKey(),
            function () use ($actor): void {
                $asset = $this->asset('Filtered-calendar-needle.jpg');
                $destination = $this->destination();
                $this->link($actor, 'Filtered-campaign-needle', 'needle');

                for ($i = 0; $i < 27; $i++) {
                    app(ContentScheduler::class)->schedule(
                        $asset,
                        $destination,
                        $actor,
                        now('UTC')->addDays(2)->format('Y-m-d\TH:i'),
                        'UTC',
                    );
                }
            },
        );

        $route = $this->indexRoute($organization);
        $query = [
            'status' => 'scheduled',
            'media_q' => 'Filtered-calendar-needle',
            'link_q' => 'Filtered-campaign-needle',
        ];
        $first = $this->actingAs($actor)->get($route.'?'.http_build_query($query))
            ->assertOk()
            ->assertSee('27 matching')
            ->assertSee('Showing 1–25 of 27');

        $url = $first->viewData('publications')->nextPageUrl();
        $this->assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $pageQuery);
        $this->assertSame('2', $pageQuery['page']);
        unset($pageQuery['page']);
        $this->assertEquals($query, $pageQuery);

        Schema::dropIfExists('scheduled_publication_links');
        try {
            $safe = $this->actingAs($actor)->get($route.'?media_q=Filtered-calendar')
                ->assertOk()
                ->assertDontSee('Tracked link (optional)');
            $this->assertSame(0, $safe->viewData('linkMatches'));
            $this->assertSame(1, $safe->viewData('assetMatches'));
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
            'role' => UserRole::Editor,
        ]);

        return [$actor, $organization];
    }

    private function indexRoute(Organization $organization): string
    {
        return route('organizations.scheduler.index', [
            'organizationId' => $organization->getKey(),
        ]);
    }

    private function asset(string $filename): MediaAsset
    {
        $blob = MediaBlob::query()->create([
            'storage_disk' => 'local',
            'storage_key' => 'organizations/test/blobs/'.Str::uuid(),
            'sha256' => hash('sha256', (string) Str::uuid()),
            'byte_size' => 1024,
            'mime_type' => 'image/jpeg',
        ]);

        return MediaAsset::query()->create([
            'media_blob_id' => $blob->getKey(),
            'original_filename' => $filename,
            'source_type' => 'manual_upload',
            'status' => MediaAsset::STATUS_READY,
            'metadata' => [
                'processing' => [
                    'status' => 'completed',
                    'version' => app(MediaAssetProcessor::class)->currentVersion(),
                    'attempts' => 1,
                ],
            ],
        ]);
    }

    private function link(User $actor, string $label, string $campaign): TrackedLink
    {
        return TrackedLink::query()->create([
            'created_by_user_id' => $actor->getKey(),
            'token' => Str::random(22),
            'label' => $label,
            'destination_url' => 'https://example.com/destination',
            'campaign' => $campaign,
            'status' => TrackedLink::STATUS_ACTIVE,
        ]);
    }

    private function destination(): PublishingDestination
    {
        return PublishingDestination::query()->create([
            'name' => 'Sandbox channel',
            'provider' => 'sandbox',
            'status' => PublishingDestination::STATUS_ACTIVE,
        ]);
    }
}
