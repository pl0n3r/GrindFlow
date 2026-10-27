<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use GrindFlow\Infrastructure\Diagnostics\JobFailureDiagnostics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JobFailureDiagnosticsTest extends TestCase
{
    private const NOW = 1780000000;

    public function testClassifiedFailuresProduceStableSafeSummary(): void
    {
        $data = [
            self::entry('scheduler', 2, 'timeout', self::NOW - 80),
            self::entry('distribution', 1, 'validation_failed', self::NOW - 120),
            self::entry('scheduler', 4, 'retry_exhausted', self::NOW - 20),
        ];
        $expected = [
            'schema_version' => 1,
            'as_of' => gmdate('Y-m-d\TH:i:s\Z', self::NOW),
            'total_failures' => 3,
            'jobs' => [
                ['type' => 'distribution', 'failures' => 1, 'attempts' => 1,
                    'last_cause' => 'validation_failed', 'age_seconds' => 120],
                ['type' => 'scheduler', 'failures' => 2, 'attempts' => 4,
                    'last_cause' => 'retry_exhausted', 'age_seconds' => 20],
            ],
        ];
        self::assertSame($expected, JobFailureDiagnostics::summarize($data, self::clock()));
        self::assertSame($expected, JobFailureDiagnostics::summarize(array_reverse($data), self::clock()));
    }

    public function testEmptyAndEqualTimeFailuresRemainDeterministic(): void
    {
        self::assertSame([
            'schema_version' => 1, 'as_of' => gmdate('Y-m-d\TH:i:s\Z', self::NOW),
            'total_failures' => 0, 'jobs' => [],
        ], JobFailureDiagnostics::summarize([], self::clock()));

        $a = self::entry('media', 1, 'unknown', self::NOW);
        $b = self::entry('media', 3, 'timeout', self::NOW);
        foreach ([[$a, $b], [$b, $a]] as $data) {
            self::assertSame([[
                'type' => 'media', 'failures' => 2, 'attempts' => 3,
                'last_cause' => 'timeout', 'age_seconds' => 0,
            ]], JobFailureDiagnostics::summarize($data, self::clock())['jobs']);
        }
    }

    public function testUntrustedPayloadsAndPersonalInformationAreRejectedWithoutEcho(): void
    {
        $safe = self::entry('publication', 1, 'unknown', self::NOW - 1);
        $untrusted = [
            array_merge($safe, ['payload' => ['token' => 'synthetic-private-token']]),
            array_merge($safe, ['email' => 'synthetic-user@example.test']),
            array_replace($safe, ['type' => 'client-synthetic-user@example.test']),
            array_replace($safe, ['cause' => 'Authorization: synthetic-private-token']),
            array_replace($safe, ['attempts' => '3']),
            array_replace($safe, ['attempts' => 0]),
            array_replace($safe, ['failed_at' => self::NOW + 1]),
            array_replace($safe, ['failed_at' => -1]),
        ];
        foreach ($untrusted as $bad) {
            try {
                JobFailureDiagnostics::summarize([$bad], self::clock());
                self::fail('Unsafe metadata must be rejected.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('INVALID_JOB_FAILURE_METADATA', $e->getMessage());
                self::assertStringNotContainsString('synthetic-private-token', $e->getMessage());
                self::assertStringNotContainsString('@example.test', $e->getMessage());
            }
        }
    }

    public function testShapeAndVolumeLimitsFailClosed(): void
    {
        foreach ([['arbitrary' => self::entry('media', 1, 'unknown', self::NOW)],
            array_fill(0, 1001, self::entry('media', 1, 'unknown', self::NOW))] as $invalid) {
            $this->expectInvalid($invalid);
        }
        $badOrder = self::entry('media', 1, 'unknown', self::NOW);
        $badOrder = ['cause' => $badOrder['cause']] + $badOrder;
        $this->expectInvalid([$badOrder]);
    }

    /** @param array<mixed> $failures */
    private function expectInvalid(array $failures): void
    {
        try {
            JobFailureDiagnostics::summarize($failures, self::clock());
            self::fail('Invalid shape must be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('INVALID_JOB_FAILURE_METADATA', $e->getMessage());
        }
    }

    /** @return array{type: string, attempts: int, cause: string, failed_at: int} */
    private static function entry(string $type, int $attempts, string $cause, int $failedAt): array
    {
        return ['type' => $type, 'attempts' => $attempts, 'cause' => $cause, 'failed_at' => $failedAt];
    }

    private static function clock(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.self::NOW));
    }
}
