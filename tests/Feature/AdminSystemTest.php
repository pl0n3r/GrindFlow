<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_open_system_status_page(): void
    {
        $admin = User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('admin.system'));

        $this->get(route('admin.system'))
            ->assertOk()
            ->assertSee('System')
            ->assertSee('Runtime configuration')
            ->assertSee('Database connection')
            ->assertSee('Media object storage')
            ->assertSee('data-media-storage-configured=', false)
            ->assertSee('Session driver')
            ->assertSee('Queue connection');
    }


    public function test_system_status_reports_media_storage_ready_without_exposing_secrets(): void
    {
        config([
            'grindflow.media.direct_upload_disk' => 'media',
            'filesystems.disks.media.driver' => 's3',
            'filesystems.disks.media.key' => 'secret-test-key',
            'filesystems.disks.media.secret' => 'secret-test-value',
            'filesystems.disks.media.bucket' => 'private-test-bucket',
        ]);

        $admin = User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.system'))
            ->assertOk()
            ->assertSee('Media storage')
            ->assertSee('Ready')
            ->assertSee('data-media-storage-configured="1"', false);

        $response
            ->assertDontSee('secret-test-key')
            ->assertDontSee('secret-test-value')
            ->assertDontSee('private-test-bucket');
    }

    public function test_non_admin_cannot_open_system_status_page(): void
    {
        $user = User::factory()->create([
            'platform_role' => UserRole::Model,
        ]);

        $this->actingAs($user)
            ->get(route('admin.system'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.system'))
            ->assertRedirect(route('login'));
    }
}
