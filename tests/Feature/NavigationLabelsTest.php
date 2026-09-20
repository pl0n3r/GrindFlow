<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class NavigationLabelsTest extends TestCase
{
    public function testWorkspaceMenusUseReadableSpanishLabelsWithoutDecorativeGlyphs(): void
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

        $knownLabels = [
            'Resumen', 'Biblioteca', 'Programación', 'Distribución',
            'Tráfico', 'Finanzas', 'Sistema', 'Diagnósticos',
        ];

        foreach ($views as $view) {
            $blade = file_get_contents(resource_path('views/'.$view));
            self::assertIsString($blade, $view);
            self::assertSame(1, preg_match('/<nav class="gf-sidebar__nav"[\s\S]*?<\/nav>/', $blade, $match), $view);
            $navigation = $match[0];
            self::assertStringNotContainsString('gf-navitem__icon', $navigation, $view);
            self::assertSame(0, preg_match('/[◫◇⌁⇢⌗⌘\x{FFFD}]/u', $navigation), $view);
            self::assertGreaterThanOrEqual(
                1,
                preg_match_all('/<span class="gf-navitem__text">([^<]+)<\/span>/', $navigation, $labels),
                $view,
            );
            self::assertContains('Resumen', $labels[1], $view);
            foreach ($labels[1] as $label) {
                self::assertContains($label, $knownLabels, $view);
            }
        }
    }
}
