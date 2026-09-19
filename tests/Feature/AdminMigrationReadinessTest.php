<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Operations\MigrationReadiness;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class AdminMigrationReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_exact_pending_migration_inventory(): void
    {
        $this->fakePendingMigrations([
            '2026_09_18_200000_create_scheduling_tables',
            '2026_09_19_021500_create_publication_deliveries_table',
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.system'))
            ->assertOk()
            ->assertSee('data-pending-migration-inventory', false)
            ->assertSee('2026_09_18_200000_create_scheduling_tables')
            ->assertSee('2026_09_19_021500_create_publication_deliveries_table')
            ->assertSee('name="migration_batch"', false)
            ->assertSee('name="backup_confirmed"', false)
            ->assertSee('name="confirmation"', false);

        $response->assertDontSee('Database schema is current');
    }

    public function test_pending_batch_does_not_hide_independent_module_readiness(): void
    {
        $this->fakePendingMigrations([
            '2026_09_19_080000_new_module_requires_deploy',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.system'))
            ->assertOk()
            ->assertSee('data-pending-migrations="1"', false)
            ->assertSee('data-module-readiness="vault:ready"', false)
            ->assertSee('data-module-readiness="scheduling:ready"', false)
            ->assertSee('data-module-readiness="distribution:ready"', false)
            ->assertSee('data-module-readiness="traffic:ready"', false)
            ->assertSee('data-module-readiness="finance:ready"', false)
            ->assertSee('name="migration_batch"', false);
    }

    public function test_missing_operator_confirmation_never_runs_migrations(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->actingAs($this->admin())
            ->post(route('admin.system.migrate'))
            ->assertSessionHasErrors([
                'backup_confirmed',
                'confirmation',
                'migration_batch',
            ]);
    }

    public function test_stale_or_changed_batch_is_rejected_without_running_migrations(): void
    {
        $this->fakePendingMigrations([
            '2026_09_19_021500_create_publication_deliveries_table',
        ]);

        Artisan::shouldReceive('call')->never();

        $this->actingAs($this->admin())
            ->post(route('admin.system.migrate'), [
                'backup_confirmed' => '1',
                'confirmation' => 'MIGRAR',
                'migration_batch' => str_repeat('b', 64),
            ])
            ->assertRedirect(route('admin.system'))
            ->assertSessionHasErrors('migration');
    }

    public function test_exact_confirmed_batch_can_reach_migration_command(): void
    {
        $fingerprint = $this->fakePendingMigrations([
            '2026_09_19_021500_create_publication_deliveries_table',
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', ['--force' => true])
            ->andReturn(0);

        $this->actingAs($this->admin())
            ->post(route('admin.system.migrate'), [
                'backup_confirmed' => '1',
                'confirmation' => 'MIGRAR',
                'migration_batch' => $fingerprint,
            ])
            ->assertRedirect(route('admin.system'))
            ->assertSessionHas('status');
    }

    public function test_non_admin_cannot_invoke_migration_command(): void
    {
        Artisan::shouldReceive('call')->never();

        $user = User::factory()->create([
            'platform_role' => UserRole::Model,
        ]);

        $this->actingAs($user)
            ->post(route('admin.system.migrate'), [
                'backup_confirmed' => '1',
                'confirmation' => 'MIGRAR',
                'migration_batch' => str_repeat('a', 64),
            ])
            ->assertForbidden();
    }

    public function test_fingerprint_changes_when_migration_file_content_changes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gf-migration-');
        $this->assertIsString($path);

        $migrator = Mockery::mock(Migrator::class);
        $migrator->shouldReceive('getMigrationFiles')
            ->twice()
            ->with(database_path('migrations'))
            ->andReturn([
                '2026_09_19_021500_create_publication_deliveries_table' => $path,
            ]);
        $migrator->shouldReceive('repositoryExists')
            ->twice()
            ->andReturn(false);

        try {
            file_put_contents($path, '<?php // v1');

            $readiness = new MigrationReadiness($migrator);
            $first = $readiness->snapshot();

            file_put_contents($path, '<?php // v2');

            $second = $readiness->snapshot();

            $this->assertSame($first['names'], $second['names']);
            $this->assertNotSame(
                $first['fingerprint'],
                $second['fingerprint'],
            );
        } finally {
            unlink($path);
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function fakePendingMigrations(array $names): string
    {
        $fingerprint = hash('sha256', implode("\n", $names));

        $readiness = Mockery::mock(MigrationReadiness::class);
        $readiness->shouldReceive('snapshot')
            ->andReturn([
                'names' => $names,
                'fingerprint' => $fingerprint,
            ]);

        $this->app->instance(MigrationReadiness::class, $readiness);

        return $fingerprint;
    }

    private function admin(): User
    {
        return User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);
    }
}
