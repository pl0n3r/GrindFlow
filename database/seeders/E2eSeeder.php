<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\PublishingDestination;
use App\Models\RevenueAllocation;
use App\Models\ScheduledPublication;
use App\Models\ScheduledPublicationLink;
use App\Models\TrackedLink;
use App\Models\TrackedLinkDailyMetric;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\MediaAssetProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use RuntimeException;

class E2eSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('E2E data can only be seeded in local or testing environments.');
        }

        $password = (string) env('E2E_USER_PASSWORD', '');

        if ($password === '') {
            throw new RuntimeException('E2E_USER_PASSWORD is required.');
        }

        $email = (string) env('E2E_USER_EMAIL', 'e2e-browser@grindflow.test');
        $name = (string) env('E2E_USER_NAME', 'E2E Browser User');
        $organizationName = (string) env('E2E_ORG_NAME', 'E2E Browser Workspace');
        $organizationSlug = (string) env('E2E_ORG_SLUG', 'e2e-browser-workspace');

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->password = $password;
        $user->email_verified_at = now();
        $user->platform_role = UserRole::Model;
        $user->save();

        $organization = Organization::query()->firstOrNew(['slug' => $organizationSlug]);
        $organization->name = $organizationName;
        $organization->type = OrganizationType::Studio;
        $organization->save();

        Membership::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            ['role' => UserRole::Studio],
        );
        
        // The browser fixture only lives in a disposable, migrated local/
        // testing database. Never seed staging/production or trigger providers.
        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $this->seedWorkflowFixture($user),
        );
    }

    private function seedWorkflowFixture(User $user): void
    {
        $processorVersion = app(MediaAssetProcessor::class)->currentVersion();

        // Named fixture + deterministic source_ref make retries safe without
        // erasing any existing E2E rows; no bytes are published externally.
        $media = null;

        for ($i = 0; $i <= 105; $i++) {
            $deep = $i === 0;
            $filename = $deep
                ? 'ZZ-e2e-needle-media.jpg'
                : 'AAA-e2e-filler-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'.jpg';
            $sourceRef = 'e2e-browser-workflow-media-'.$i;
            $existing = MediaAsset::query()->where('source_ref', $sourceRef)->first();

            if ($existing !== null) {
                if ($deep) {
                    $media = $existing;
                }

                continue;
            }

            $storageKey = 'e2e-workflow/blobs/'.$i;
            $blob = MediaBlob::query()->firstOrCreate(
                ['sha256' => hash('sha256', $storageKey)],
                [
                    'storage_disk' => 'local',
                    'storage_key' => $storageKey,
                    'byte_size' => 1024,
                    'mime_type' => 'image/jpeg',
                ],
            );

            $asset = MediaAsset::query()->create([
                'media_blob_id' => $blob->getKey(),
                'original_filename' => $filename,
                'source_ref' => $sourceRef,
                'source_type' => 'e2e-browser-fixture',
                'status' => MediaAsset::STATUS_READY,
                'metadata' => [
                    'processing' => [
                        'status' => 'completed',
                        'version' => $processorVersion,
                    ],
                ],
            ]);

            if ($deep) {
                $media = $asset;
            }
        }

        $deepLink = null;

        for ($i = 0; $i <= 105; $i++) {
            $deep = $i === 0;
            $label = $deep
                ? 'ZZ E2E needle link'
                : 'AAA E2E filler '.str_pad((string) $i, 3, '0', STR_PAD_LEFT);

            $link = TrackedLink::query()->firstOrCreate(
                ['label' => $label],
                [
                    'created_by_user_id' => $user->getKey(),
                    'token' => Str::random(22),
                    'destination_url' => 'https://example.test/e2e-landing',
                    'channel' => $deep ? 'e2e-needle' : 'e2e-filler',
                    'campaign' => $deep ? 'e2e-needle-campaign' : 'e2e-filler',
                    'status' => TrackedLink::STATUS_ACTIVE,
                ],
            );

            if ($deep) {
                $deepLink = $link;
            }
        }

        $destination = PublishingDestination::query()->firstOrCreate(
            ['name' => 'E2E Sandbox Channel'],
            [
                'provider' => 'e2e-sandbox',
                'status' => PublishingDestination::STATUS_ACTIVE,
            ],
        );

        if ($media === null || $deepLink === null) {
            throw new RuntimeException('Browser workflow fixtures could not be created.');
        }

        // 27 seeded rows put the create/edit case on page 2. This exercises
        // pagination and a real POST without requiring background jobs.
        $scheduledCount = ScheduledPublication::query()
            ->where('media_asset_id', $media->getKey())
            ->where('publishing_destination_id', $destination->getKey())
            ->count();

        for ($i = $scheduledCount; $i < 27; $i++) {
            $publication = ScheduledPublication::query()->create([
                'media_asset_id' => $media->getKey(),
                'publishing_destination_id' => $destination->getKey(),
                'scheduled_by_user_id' => $user->getKey(),
                'status' => ScheduledPublication::STATUS_SCHEDULED,
                'scheduled_for_utc' => now('UTC')->addDays(2)->addMinutes($i),
                'timezone' => 'UTC',
                'request_key' => (string) Str::uuid(),
            ]);

            if ($i === 0) {
                ScheduledPublicationLink::query()->create([
                    'scheduled_publication_id' => $publication->getKey(),
                    'tracked_link_id' => $deepLink->getKey(),
                ]);
            }
        }

        TrackedLinkDailyMetric::query()->firstOrCreate(
            [
                'tracked_link_id' => $deepLink->getKey(),
                'metric_date' => now('UTC')->toDateString(),
            ],
            ['clicks' => 7],
        );

        if (RevenueAllocation::query()->where('source_label', 'E2E seeded reconciliation')->doesntExist()) {
            RevenueAllocation::query()->create([
                'created_by_user_id' => $user->getKey(),
                'source_label' => 'E2E seeded reconciliation',
                'amount_minor' => 2500,
                'currency' => 'COP',
                'occurred_on' => now('UTC')->toDateString(),
            ]);
        }
    }

}
