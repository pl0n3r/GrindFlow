<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class WorkspaceSidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_sidebar_exposes_one_consistent_admin_navigation(): void
    {
        $user = User::factory()->create(['platform_role' => UserRole::Admin]);
        $organization = Organization::factory()->create();

        $this->actingAs($user);

        $html = Blade::render(
            '<x-workspace-sidebar :organization="$organization" active="scheduler" />',
            ['organization' => $organization],
        );

        foreach ([
            'Resumen',
            'Biblioteca',
            'Programación',
            'Distribución',
            'Tráfico',
            'Finanzas',
            'Sistema',
            'Diagnósticos',
            'Cerrar sesión',
        ] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertStringContainsString('data-workspace-sidebar', $html);
        $this->assertStringContainsString('gf-navitem__icon', $html);
    }

    public function test_workspace_pages_do_not_reintroduce_private_sidebar_copies(): void
    {
        foreach ([
            'dashboard.blade.php',
            'vault/index.blade.php',
            'scheduling/index.blade.php',
            'distribution/index.blade.php',
            'traffic/index.blade.php',
            'finance/index.blade.php',
            'admin/system.blade.php',
            'admin/diagnostics.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertIsString($source);
            $this->assertStringContainsString('<x-workspace-sidebar', $source, $view);
            $this->assertStringNotContainsString('<aside class="gf-sidebar">', $source, $view);
        }
    }
}
