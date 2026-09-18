<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_run_database_migrations(): void
    {
        $user = User::factory()->create([
            'platform_role' => UserRole::Model,
        ]);

        $this->actingAs($user)
            ->post(route('admin.system.migrate'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_run_pending_migrations_from_system_ui(): void
    {
        $admin = User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', ['--force' => true])
            ->andReturn(0);

        $this->actingAs($admin)
            ->post(route('admin.system.migrate'))
            ->assertRedirect(route('admin.system'))
            ->assertSessionHas('status', 'Migraciones de base de datos completadas.');
    }
}
