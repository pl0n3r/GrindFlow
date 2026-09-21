<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class NavigationLabelsTest extends TestCase
{
    public function test_workspace_navigation_uses_readable_spanish_labels_without_decorative_unicode_glyphs(): void
    {
        $views = [
            'dashboard.blade.php',
            'vault/index.blade.php',
            'scheduling/index.blade.php',
            'distribution/index.blade.php',
            'traffic/index.blade.php',
            'finance/index.blade.php',
            'admin/system.blade.php',
            'admin/diagnostics.blade.php',
        ];

        foreach ($views as $view) {
            $blade = file_get_contents(resource_path('views/'.$view));
            self::assertIsString($blade, $view);
            self::assertSame(1, substr_count($blade, '<x-workspace-sidebar'), $view);
            self::assertStringNotContainsString('<aside class="gf-sidebar">', $blade, $view);
        }

        $component = file_get_contents(resource_path('views/components/workspace-sidebar.blade.php'));
        self::assertIsString($component);

        foreach ([
            'Resumen',
            'Biblioteca',
            'Programación',
            'Distribución',
            'Tráfico',
            'Finanzas',
            'Sistema',
            'Diagnósticos',
        ] as $label) {
            self::assertStringContainsString("'label' => '".$label."'", $component);
        }

        self::assertSame(0, preg_match('/[◫◇⌁⇢⌗⌘\x{FFFD}]/u', $component));
        self::assertStringContainsString('gf-navitem__icon', $component);
        self::assertStringContainsString('<svg', $component);
    }
}
