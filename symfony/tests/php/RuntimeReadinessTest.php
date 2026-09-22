<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Runtime\RuntimeReadiness;
use PHPUnit\Framework\TestCase;

final class RuntimeReadinessTest extends TestCase
{
    private const REQUIRED = ['ctype', 'iconv', 'PDO', 'pdo_mysql'];

    public function testSupportedRuntimeSatisfiesContract(): void
    {
        self::assertTrue((new RuntimeReadiness())->supports(80300, self::REQUIRED));
        self::assertTrue((new RuntimeReadiness())->supports(80599, self::REQUIRED));
    }

    public function testUnsupportedPhpVersionsFailClosed(): void
    {
        $readiness = new RuntimeReadiness();

        self::assertFalse($readiness->supports(80299, self::REQUIRED));
        self::assertFalse($readiness->supports(90000, self::REQUIRED));
    }

    public function testMissingRequiredExtensionFailsClosed(): void
    {
        self::assertFalse((new RuntimeReadiness())->supports(
            80300,
            ['ctype', 'iconv', 'pdo'],
        ));
    }
}
