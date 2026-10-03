<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\VaultTriageItem;
use App\Support\Security\MobileUploadGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuestMobileUploadFlowTest extends TestCase
{
    use RefreshDatabase;

    private const string SIGNING_KEY = 'guest-mobile-upload-test-key-0000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('grindflow.media.disk', 'local');
        config()->set('grindflow.media.guest_upload_signing_key', self::SIGNING_KEY);
        app()->forgetInstance(MobileUploadGrant::class);
    }

    public function test_guest_mobile_flow_previews_valid_files_and_surfaces_rejections(): void
    {
        $organization = Organization::factory()->create();
        $token = $this->token($organization, 'ux-preview', maxFiles: 2, maxBytes: 2_097_152);

        $response = $this->get(route('guest.upload.show', ['token' => $token]));

        $response
            ->assertOk()
            ->assertSee('Selecciona, revisa y envía.')
            ->assertSee('Agregar fotos o videos')
            ->assertSee('Archivos rechazados')
            ->assertSee('data-guest-upload', false)
            ->assertSee('URL.createObjectURL', false)
            ->assertSee('URL.revokeObjectURL', false)
            ->assertSee('textContent', false)
            ->assertSee('Retirar')
            ->assertSee('tipo de archivo no permitido');

        $this->assertGuest();
    }

    public function test_guest_mobile_flow_works_without_authenticated_session_and_preserves_tenant_scope(): void
    {
        $organization = Organization::factory()->create();
        $token = $this->token($organization, 'tenant-scope');

        $response = $this->post(route('guest.upload.store'), [
            'grant' => $token,
            'media' => [
                UploadedFile::fake()->create('mobile.jpg', 4, 'image/jpeg'),
            ],
        ]);

        $response
            ->assertOk()
            ->assertSee('Recibimos 1 archivo')
            ->assertDontSee((string) $organization->getKey())
            ->assertDontSee('tenant-scope');

        $asset = MediaAsset::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->first();

        $this->assertInstanceOf(MediaAsset::class, $asset);
        $this->assertNull($asset->ingested_by_user_id);
        $this->assertNull($asset->profile_id);

        $this->assertDatabaseHas('vault_triage_items', [
            'organization_id' => $organization->getKey(),
            'media_asset_id' => $asset->getKey(),
            'status' => VaultTriageItem::STATUS_PENDING,
            'assigned_profile_id' => null,
        ]);

        $this->post(route('guest.upload.store'), [
            'grant' => $token,
            'media' => [
                UploadedFile::fake()->create('replay.jpg', 4, 'image/jpeg'),
            ],
        ])
            ->assertUnprocessable()
            ->assertSee('No pudimos enviar los archivos.');

        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('mobile_upload_grant_uses', 1);
        $this->assertGuest();
    }

    public function test_invalid_link_and_invalid_media_fail_closed_with_public_errors(): void
    {
        $this->get(route('guest.upload.show', ['token' => 'v1.invalid.signature']))
            ->assertNotFound();

        $organization = Organization::factory()->create();
        $token = $this->token($organization, 'invalid-media');

        $this->post(route('guest.upload.store'), [
            'grant' => $token,
            'media' => [
                UploadedFile::fake()->create('payload.txt', 1, 'text/plain'),
            ],
        ])
            ->assertUnprocessable()
            ->assertSee('No pudimos enviar los archivos.')
            ->assertDontSee((string) $organization->getKey())
            ->assertDontSee('invalid-media');

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('mobile_upload_grant_uses', 0);
    }

    private function token(
        Organization $organization,
        string $nonce,
        int $maxFiles = 5,
        int $maxBytes = 10_485_760,
    ): string {
        $now = now()->timestamp;

        return app(MobileUploadGrant::class)->issue(
            (string) $organization->getKey(),
            $now,
            $now + 600,
            $maxFiles,
            $maxBytes,
            $nonce,
        );
    }
}
