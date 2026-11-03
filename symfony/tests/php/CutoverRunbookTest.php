<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use PHPUnit\Framework\TestCase;

final class CutoverRunbookTest extends TestCase
{
    private static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    // Canonical coordination target: CutoverRunbookTest::test_criterio_principal.
    public function test_criterio_principal(): void
    {
        $root = self::projectRoot();
        $runbook = file_get_contents($root.'/docs/launch/cutover-symfony-runbook.md');
        $checker = file_get_contents($root.'/scripts/hostinger-readonly-check.sh');
        self::assertIsString($runbook);
        self::assertIsString($checker);

        $previous = -1;
        foreach ([
            '### 01 — Guardas y SHA anterior',
            '### 02 — Backup y prueba de restauración (#780)',
            '### 03 — Hostinger de solo lectura',
            '### 04 — Paridad, datos y un solo escritor',
            '### 05 — Conmutación por el propietario',
            '### 06 — `/health` exact-SHA',
            '### 07 — Smoke autenticado y tenant (#794)',
            '### 08 — Vuelta atrás inmediata y cierre',
        ] as $step) {
            $offset = strpos($runbook, $step);
            self::assertNotFalse($offset, $step);
            self::assertGreaterThan($previous, $offset, $step.' fuera de orden');
            $previous = $offset;
        }

        foreach (['/l/{token}', '/guest-upload/{token}', '/dashboard', '/login',
            'SIN EQUIVALENCIA PROBADA', 'PREVIOUS_SHA', 'TARGET_SHA',
            'release_sha', 'NO-GO', 'dos escritores', 'PR **posterior**'] as $needle) {
            self::assertStringContainsString($needle, $runbook);
        }
        self::assertStringContainsString('--read-only', $checker);
        self::assertStringContainsString('READ_ONLY_RESULT=NO_GO', $checker);
        self::assertStringContainsString('READ_ONLY_RESULT=CANDIDATE_ONLY', $checker);
        self::assertStringContainsString('WEB_PHP_FPM=UNVERIFIED', $checker);
        self::assertStringContainsString('php_cli_minimum_8_3', $checker);
        self::assertStringContainsString('composer_cli', $checker);
        self::assertStringContainsString('COMPOSER_VERSION=', $checker);
        self::assertStringContainsString('for extension in ctype iconv pdo_mysql openssl mbstring', $checker);
    }

    public function testMissingCheckoutAlwaysFailsClosed(): void
    {
        $script = self::projectRoot().'/scripts/hostinger-readonly-check.sh';
        $path = self::projectRoot().'/.nonexistent-cutover-fixture-801';
        self::assertFileDoesNotExist($path);
        $lines = [];
        $exitCode = 0;
        exec('bash '.escapeshellarg($script).' --read-only --root '.escapeshellarg($path).' 2>&1', $lines, $exitCode);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('READ_ONLY_RESULT=NO_GO', implode("\n", $lines));
        self::assertFileDoesNotExist($path);
    }
}
