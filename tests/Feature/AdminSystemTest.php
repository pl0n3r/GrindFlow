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
            ->get(route('admin.system'))
            ->assertOk()
            ->assertSee('System')
            ->assertSee('Runtime configuration')
            ->assertSee('Database connection')
            ->assertSee('Session driver')
            ->assertSee('Queue connection');
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
