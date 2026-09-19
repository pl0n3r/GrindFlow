<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Operations\MigrationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
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

    public function test_system_reports_live_database_when_migration_inventory_fails(): void
    {
        $admin = User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);
        $organization = Organization::factory()->create();
        $readiness = Mockery::mock(MigrationReadiness::class);
        $readiness->shouldReceive('snapshot')
            ->once()
            ->andThrow(new RuntimeException('sensitive-internal-schema-error'));
        $this->app->instance(MigrationReadiness::class, $readiness);

        $this->actingAs($admin)
            ->get(route('admin.system'))
            ->assertOk()
            ->assertSee('Connected')
            ->assertSee('data-pending-migrations="unknown"', false)
            ->assertSee('El inventario no esta disponible')
            ->assertSee(route('organizations.vault.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertDontSee('name="migration_batch"', false)
            ->assertDontSee('sensitive-internal-schema-error');
    }

    public function test_admin_system_links_existing_workspace_modules_for_visible_organization(): void
    {
        $admin = User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);
        $organization = Organization::factory()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.system'))
            ->assertOk();

        foreach ([
            'organizations.vault.index',
            'organizations.scheduler.index',
            'organizations.distribution.index',
            'organizations.traffic.index',
        ] as $name) {
            $response->assertSee(route($name, [
                'organizationId' => $organization->getKey(),
            ]));
        }

        $response->assertSee('GrindFlow v'.config('version.number'));
    }

    public function test_dashboard_distribution_link_is_available_only_for_visible_organization(): void
    {
        $user = User::factory()->create([
            'platform_role' => UserRole::Model,
        ]);
        $visible = Organization::factory()->create();
        $other = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $visible->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Editor,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('organizations.distribution.index', [
                'organizationId' => $visible->getKey(),
            ]))
            ->assertDontSee(route('organizations.distribution.index', [
                'organizationId' => $other->getKey(),
            ]));
    }

    public function test_admin_system_keeps_workspace_navigation_disabled_without_organizations(): void
    {
        $admin = User::factory()->create([
            'platform_role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.system'))
            ->assertOk()
            ->assertSee('gf-navitem--disabled', false)
            ->assertDontSee('/organizations/example/vault');
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
