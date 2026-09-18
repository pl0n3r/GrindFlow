<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DirectMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_a_short_lived_direct_upload_intent(): void
    {
        $this->configureDirectStorage();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('temporaryUploadUrl')
            ->once()
            ->andReturn([
                'url' => 'https://storage.example.test/signed-upload',
                'headers' => [
                    'Content-Type' => 'video/mp4',
                    'x-amz-acl' => 'private',
                ],
            ]);

        Storage::shouldReceive('disk')
            ->once()
            ->with('media')
            ->andReturn($disk);

        $this->actingAs($user)
            ->postJson(
                route('organizations.vault.direct.create', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'filename' => 'large-clip.mp4',
                    'mime_type' => 'video/mp4',
                    'byte_size' => 50_000_000,
                ],
            )
            ->assertOk()
            ->assertJsonPath('url', 'https://storage.example.test/signed-upload')
            ->assertJsonPath('headers.Content-Type', 'video/mp4')
            ->assertJsonStructure([
                'url',
                'headers',
                'upload_token',
                'expires_at',
            ]);
    }

    public function test_direct_upload_completion_verifies_hash_and_deduplicates_bytes(): void
    {
        Storage::fake('s3');
        config(['grindflow.media.direct_upload_disk' => 's3']);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $payload = 'same-direct-upload-bytes';
        $sha256 = hash('sha256', $payload);

        $firstStaging = 'organizations/'.$organization->getKey().'/staging/'.Str::uuid();
        Storage::disk('s3')->put($firstStaging, $payload);

        $firstResponse = $this->actingAs($user)
            ->postJson(
                route('organizations.vault.direct.complete', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'upload_token' => $this->token(
                        $organization,
                        $user,
                        $firstStaging,
                        'first.mp4',
                        'video/mp4',
                        strlen($payload),
                    ),
                ],
            );

        $firstResponse
            ->assertOk()
            ->assertJsonPath('status', MediaAsset::STATUS_READY);

        $finalKey = sprintf(
            'organizations/%s/blobs/%s/%s',
            $organization->getKey(),
            substr($sha256, 0, 2),
            $sha256,
        );

        Storage::disk('s3')->assertExists($finalKey);
        Storage::disk('s3')->assertMissing($firstStaging);

        $secondStaging = 'organizations/'.$organization->getKey().'/staging/'.Str::uuid();
        Storage::disk('s3')->put($secondStaging, $payload);

        $this->actingAs($user)
            ->postJson(
                route('organizations.vault.direct.complete', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'upload_token' => $this->token(
                        $organization,
                        $user,
                        $secondStaging,
                        'second.mp4',
                        'video/mp4',
                        strlen($payload),
                    ),
                ],
            )
            ->assertOk()
            ->assertJsonPath('status', MediaAsset::STATUS_DUPLICATE);

        Storage::disk('s3')->assertMissing($secondStaging);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($sha256): void {
                $this->assertSame(1, MediaBlob::query()->count());
                $this->assertSame(2, MediaAsset::query()->count());

                $blob = MediaBlob::query()->firstOrFail();

                $this->assertSame($sha256, $blob->sha256);
                $this->assertSame('s3', $blob->storage_disk);
                $this->assertSame('sha256_verified', $blob->metadata['integrity']);

                $duplicate = MediaAsset::query()
                    ->where('status', MediaAsset::STATUS_DUPLICATE)
                    ->firstOrFail();

                $this->assertNotNull($duplicate->duplicate_of);
            },
        );
    }

    public function test_direct_upload_token_cannot_cross_tenant_boundary(): void
    {
        Storage::fake('s3');
        config(['grindflow.media.direct_upload_disk' => 's3']);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);
        $this->membership($user, $otherOrganization, UserRole::Studio);

        $staging = 'organizations/'.$organization->getKey().'/staging/'.Str::uuid();
        Storage::disk('s3')->put($staging, 'tenant-bound');

        $this->actingAs($user)
            ->postJson(
                route('organizations.vault.direct.complete', [
                    'organizationId' => $otherOrganization->getKey(),
                ]),
                [
                    'upload_token' => $this->token(
                        $organization,
                        $user,
                        $staging,
                        'tenant-bound.mp4',
                        'video/mp4',
                        strlen('tenant-bound'),
                    ),
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('upload_token');

        Storage::disk('s3')->assertExists($staging);
    }

    public function test_non_manager_cannot_create_direct_upload_intent(): void
    {
        $this->configureDirectStorage();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Model);

        $this->actingAs($user)
            ->postJson(
                route('organizations.vault.direct.create', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'filename' => 'blocked.mp4',
                    'mime_type' => 'video/mp4',
                    'byte_size' => 1024,
                ],
            )
            ->assertForbidden();
    }

    private function configureDirectStorage(): void
    {
        config([
            'grindflow.media.direct_upload_disk' => 'media',
            'filesystems.disks.media.driver' => 's3',
            'filesystems.disks.media.key' => 'test-key',
            'filesystems.disks.media.secret' => 'test-secret',
            'filesystems.disks.media.bucket' => 'test-bucket',
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

    private function token(
        Organization $organization,
        User $user,
        string $storageKey,
        string $filename,
        string $mimeType,
        int $byteSize,
    ): string {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'organization_id' => (string) $organization->getKey(),
            'user_id' => (string) $user->getKey(),
            'disk' => 's3',
            'storage_key' => $storageKey,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
