<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Operations\ProductionWritePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProductionWritePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_construction_allows_non_destructive_versioned_operations(): void
    {
        config(['app.phase' => 'construccion']);

        app(ProductionWritePolicy::class)->assertAutonomousWriteAllowed(
            operation: 'synthetic-provisioning',
            destructive: false,
            bulk: false,
            versioned: true,
            backupVerified: false,
            lockHeld: true,
        );

        $this->assertTrue(true);
    }

    public function test_live_and_destructive_operations_fail_closed(): void
    {
        config(['app.phase' => 'live']);

        try {
            app(ProductionWritePolicy::class)->assertAutonomousWriteAllowed(
                operation: 'synthetic-provisioning',
                destructive: false,
                bulk: false,
                versioned: true,
                backupVerified: false,
                lockHeld: true,
            );
            $this->fail('Live phase must fail closed for autonomous writes.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disabled outside construction', $exception->getMessage());
        }

        config(['app.phase' => 'construccion']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destructive production writes require explicit owner authorization.');

        app(ProductionWritePolicy::class)->assertAutonomousWriteAllowed(
            operation: 'delete',
            destructive: true,
            bulk: false,
            versioned: true,
            backupVerified: true,
            lockHeld: true,
        );
    }

    public function test_bulk_or_migration_requires_recent_verified_backup_and_lock(): void
    {
        config(['app.phase' => 'construccion']);
        $policy = app(ProductionWritePolicy::class);

        foreach ([[false, true], [true, false]] as [$backup, $lock]) {
            try {
                $policy->assertAutonomousWriteAllowed(
                    operation: 'migration',
                    destructive: false,
                    bulk: true,
                    versioned: true,
                    backupVerified: $backup,
                    lockHeld: $lock,
                );
                $this->fail('Migration must require both backup and lock.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }

        $policy->assertAutonomousWriteAllowed(
            operation: 'migration',
            destructive: false,
            bulk: true,
            versioned: true,
            backupVerified: true,
            lockHeld: true,
        );
    }

    public function test_documentation_and_migration_flow_require_verifiable_backup_evidence(): void
    {
        config(['app.phase' => 'construccion']);
        Storage::fake('local');

        $admin = User::factory()->create(['platform_role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.system.migrate'), [
                'backup_confirmed' => '1',
                'confirmation' => 'MIGRAR',
                'migration_batch' => str_repeat('a', 64),
            ])
            ->assertSessionHasErrors('backup_receipt');
    }
}
