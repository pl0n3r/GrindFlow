<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Operations\MigrationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
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

        $fingerprint = str_repeat('a', 64);
        $readiness = Mockery::mock(MigrationReadiness::class);
        $readiness->shouldReceive('snapshot')
            ->once()
            ->andReturn([
                'names' => ['2026_09_18_200000_create_scheduling_tables'],
                'fingerprint' => $fingerprint,
            ]);
        $this->app->instance(MigrationReadiness::class, $readiness);

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', ['--force' => true])
            ->andReturn(0);

        $this->actingAs($admin)
            ->post(route('admin.system.migrate'), [
                'backup_confirmed' => '1',
                'confirmation' => 'MIGRAR',
                'migration_batch' => $fingerprint,
            ])
            ->assertRedirect(route('admin.system'))
            ->assertSessionHas('status', 'Migraciones de base de datos completadas.');
    }
}
