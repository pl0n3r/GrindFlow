<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_view_only_an_authorized_organization_vault(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Model);
        $this->membership($otherUser, $otherOrganization, UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function (): void {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'organizations/a/blobs/aa/hash-a',
                    'sha256' => str_repeat('a', 64),
                    'byte_size' => 12,
                    'mime_type' => 'image/jpeg',
                ]);

                MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => 'visible.jpg',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                ]);
            },
        );

        app(TenantContext::class)->runWithinOrganization(
            $otherUser,
            (string) $otherOrganization->getKey(),
            function (): void {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'organizations/b/blobs/bb/hash-b',
                    'sha256' => str_repeat('b', 64),
                    'byte_size' => 15,
                    'mime_type' => 'image/jpeg',
                ]);

                MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => 'hidden.jpg',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                ]);
            },
        );

        $this->actingAs($user)
            ->get(route('organizations.vault.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertOk()
            ->assertSee('visible.jpg')
            ->assertSee(route('organizations.distribution.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertSee(route('organizations.traffic.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertDontSee(route('organizations.finance.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertDontSee(route('organizations.distribution.index', [
                'organizationId' => $otherOrganization->getKey(),
            ]))
            ->assertDontSee('hidden.jpg');

        $this->actingAs($user)
            ->get(route('organizations.vault.index', [
                'organizationId' => $otherOrganization->getKey(),
            ]))
            ->assertNotFound();
    }

    public function test_studio_vault_navigation_shows_finance_for_own_organization_only(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $this->actingAs($user)
            ->get(route('organizations.vault.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertOk()
            ->assertSee(route('organizations.finance.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertDontSee(route('organizations.finance.index', [
                'organizationId' => $foreign->getKey(),
            ]));
    }

    public function test_manager_upload_deduplicates_bytes_but_keeps_ingestion_records(): void
    {
        Storage::fake('local');
        config(['grindflow.media.disk' => 'local']);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $first = UploadedFile::fake()
            ->createWithContent('clip-a.mp4', 'identical-media-payload')
            ->mimeType('video/mp4');

        $second = UploadedFile::fake()
            ->createWithContent('clip-b.mp4', 'identical-media-payload')
            ->mimeType('video/mp4');

        $route = route('organizations.vault.store', [
            'organizationId' => $organization->getKey(),
        ]);

        $this->actingAs($user)
            ->post($route, ['media' => $first])
            ->assertRedirect();

        $this->actingAs($user)
            ->post($route, ['media' => $second])
            ->assertRedirect();

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function (): void {
                $this->assertSame(1, MediaBlob::query()->count());
                $this->assertSame(2, MediaAsset::query()->count());

                $canonical = MediaAsset::query()
                    ->whereNull('duplicate_of')
                    ->firstOrFail();
                $duplicate = MediaAsset::query()
                    ->where('status', MediaAsset::STATUS_DUPLICATE)
                    ->firstOrFail();

                $this->assertSame($canonical->getKey(), $duplicate->duplicate_of);
                $this->assertSame($canonical->media_blob_id, $duplicate->media_blob_id);
            },
        );

        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_non_manager_member_cannot_upload(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Model);

        $file = UploadedFile::fake()
            ->createWithContent('blocked.mp4', 'blocked-media')
            ->mimeType('video/mp4');

        $this->actingAs($user)
            ->post(
                route('organizations.vault.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                ['media' => $file],
            )
            ->assertForbidden();
    }

    public function test_media_models_fail_closed_without_tenant_context(): void
    {
        $admin = User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);
        $organization = Organization::factory()->create();

        app(TenantContext::class)->runWithinOrganization(
            $admin,
            (string) $organization->getKey(),
            function (): void {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'organizations/test/blobs/cc/hash-c',
                    'sha256' => str_repeat('c', 64),
                    'byte_size' => 25,
                    'mime_type' => 'image/png',
                ]);

                MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => 'tenant.png',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                ]);
            },
        );

        $this->assertSame(0, MediaBlob::query()->count());
        $this->assertSame(0, MediaAsset::query()->count());
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
