<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression guard for the actual MVP state branches. The browser test in
 * tests/Browser/ui-states.spec.ts additionally executes the React transitions
 * against synthetic same-origin API responses.
 */
final class UiStatesTemplateTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testListPanelsDistinguishLoadingEmptyAndErrorWithAccessibleRoles(): void
    {
        $cases = [
            'LibraryCollectionsPanel.tsx' => [
                'Cargando colecciones y contando activos…',
                'No hay colecciones en esta organización.',
                'No se pudieron cargar las colecciones.',
                'role="alert"',
                'role="status"',
            ],
            'ManualHandoffPanel.tsx' => [
                'Cargando cola manual…',
                'No hay entregas manuales pendientes.',
                'No se pudo cargar la cola.',
                'Cargando destinos…',
                'No hay destinos manuales registrados.',
                'No se pudieron cargar los destinos.',
                'role="alert"',
                'role="status"',
            ],
            'VaultPanel.tsx' => [
                'Cargando biblioteca…',
                'Todavía no hay archivos en esta organización.',
                'La papelera está vacía.',
                'role="alert"',
                'role="status"',
                '!loading && !error && assets.length === 0',
            ],
            'ScheduleDraftPanel.tsx' => [
                'Cargando borradores…',
                'No hay borradores registrados todavía.',
                'className="weekly-error" role="alert"',
                'role="status"',
            ],
        ];
        foreach ($cases as $component => $needles) {
            $source = self::component($component);
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $source, $component.': '.$needle);
            }
        }
    }

    public function testFormsExposePendingAndFailureWithoutAssumingSuccess(): void
    {
        $admin = self::component('AdminApp.tsx');
        foreach ([
            'Cargando contexto seguro…',
            'No se pudo abrir el espacio',
            'role="alert"',
            'disabled={saving}',
            'disabled={savingProfile',
            'disabled={changingPassword',
            'Guardando…',
        ] as $expected) {
            self::assertStringContainsString($expected, $admin);
        }
        $schedule = self::component('ScheduleDraftPanel.tsx');
        self::assertStringContainsString("busyId === 'create' ? 'Guardando…'", $schedule);
        self::assertStringContainsString('role="alert">{error}', $schedule);
        $vault = self::component('VaultPanel.tsx');
        self::assertStringContainsString('disabled={uploading || loading', $vault);
        self::assertStringContainsString('Cargando detalles…', $vault);
    }

    public function testLoginAndPreviewRenderSafeAccessibleInitialStates(): void
    {
        $client = static::createClient();
        $login = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $login->filter('form.identity-form'));
        self::assertCount(1, $login->filter('input[name="_csrf_token"]'));
        self::assertCount(1, $login->filter('input#identity-password[type="password"][required]'));
        $preview = $client->request('GET', '/preview');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $preview->filter('.preview-loading[aria-live="polite"]'));
        self::assertStringContainsString('Cargando interfaz de vista previa', $preview->text());
    }

    private static function component(string $filename): string
    {
        $path = dirname(__DIR__, 2).'/frontend/admin/'.$filename;
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
