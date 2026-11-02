<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use GrindFlow\Infrastructure\Scheduler\JobRunEvidence;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JobRunEvidenceTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const OTHER = 'abcdef0123456789abcdef0123456789';

    public function testStartFinishRetainsOnlyClassifiedSafeEvidence(): void
    {
        $evidence = new JobRunEvidence();
        $start = self::time(100, '+03:00');
        $end = self::time(130, '-04:00');
        self::assertTrue($evidence->begin('scheduler', self::KEY, 1, $start));
        self::assertSame([[
            'type' => 'scheduler',
            'key_sha256' => hash('sha256', self::KEY),
            'attempt' => 1,
            'started_at' => '1970-01-01T00:01:40Z',
            'finished_at' => null,
            'result' => 'running',
        ]], $evidence->records());
        $evidence->finish(self::KEY, 1, 'succeeded', $end);
        $records = $evidence->records();
        self::assertSame('1970-01-01T00:02:10Z', $records[0]['finished_at']);
        self::assertSame('succeeded', $records[0]['result']);
        self::assertSame(1, $records[0]['attempt']);
        self::assertCount(1, $records);
        self::assertStringNotContainsString(self::KEY, json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function testRetriesCannotDuplicateSyntheticEffect(): void
    {
        $evidence = new JobRunEvidence();
        $effects = 0;
        self::assertTrue($evidence->begin('scheduler', self::KEY, 1, self::time(10)));
        ++$effects;
        self::assertFalse($evidence->begin('scheduler', self::KEY, 1, self::time(11)));
        self::assertSame(1, $effects);
        $evidence->finish(self::KEY, 1, 'succeeded', self::time(12));
        self::assertFalse($evidence->begin('scheduler', self::KEY, 2, self::time(13)));
        self::assertSame(1, $effects);
        self::assertCount(1, $evidence->records());
    }

    public function testFailedAttemptMayRetryExactlyNextNumber(): void
    {
        $evidence = new JobRunEvidence();
        self::assertTrue($evidence->begin('distribution', self::KEY, 1, self::time(10)));
        $evidence->finish(self::KEY, 1, 'failed', self::time(11));
        self::assertTrue($evidence->begin('distribution', self::KEY, 2, self::time(12)));
        $evidence->finish(self::KEY, 2, 'succeeded', self::time(13));
        self::assertFalse($evidence->begin('distribution', self::KEY, 3, self::time(14)));
        self::assertCount(2, $evidence->records());
        self::assertSame(['failed', 'succeeded'], array_column($evidence->records(), 'result'));
        self::assertSame([1, 2], array_column($evidence->records(), 'attempt'));
        self::assertSame(hash('sha256', self::KEY), $evidence->records()[1]['key_sha256']);
    }

    public function testSecretsPayloadsAndAmbiguousMetadataFailClosedWithoutEcho(): void
    {
        foreach (['x', 'synthetic@example.test', 'Bearer secret',
            'eyJhbGciOiJIUzI1NiJ9.e30.signature', '0123456789abcdef0123456789abcdef payload',
            strtoupper(self::KEY)] as $key) {
            $this->invalid(static function () use ($key): void {
                (new JobRunEvidence())->begin('scheduler', $key, 1, self::time(10));
            });
        }
        foreach (['payload', 'token', 'actor@example.test', 'other', ''] as $type) {
            $this->invalid(static function () use ($type): void {
                (new JobRunEvidence())->begin($type, self::KEY, 1, self::time(10));
            });
        }
        $this->invalid(static function (): void {
            (new JobRunEvidence())->begin('scheduler', self::KEY, 0, self::time(10));
        });
        $evidence = new JobRunEvidence();
        self::assertTrue($evidence->begin('scheduler', self::KEY, 1, self::time(10)));
        $this->invalid(static function () use ($evidence): void {
            $evidence->finish(self::KEY, 1, 'Authorization: secret', self::time(11));
        });
        $this->invalid(static function () use ($evidence): void {
            $evidence->finish(self::KEY, 1, 'failed', self::time(9));
        });
        $this->invalid(static function () use ($evidence): void {
            $evidence->finish(self::OTHER, 1, 'failed', self::time(12));
        });
    }

    public function testObviousPredictableIdempotencyKeysCannotBeAudited(): void
    {
        // A SHA-256 digest does not make a guessable nonce confidential.
        foreach ([str_repeat('0', 32), str_repeat('f', 128),
            str_repeat('ab', 16), str_repeat('01', 32),
            str_repeat('abc', 11), str_repeat('abcd', 8),
            str_repeat('abcdef', 6), str_repeat('01234567', 4)] as $weakKey) {
            $this->invalid(static function () use ($weakKey): void {
                (new JobRunEvidence())->begin('scheduler', $weakKey, 1, self::time(10));
            });
        }
        $evidence = new JobRunEvidence();
        $syntheticKey = hash('sha256', 'fixture-nonce-that-is-not-real');
        self::assertTrue($evidence->begin('scheduler', $syntheticKey, 1, self::time(10)));
        self::assertSame(hash('sha256', $syntheticKey), $evidence->records()[0]['key_sha256']);
        self::assertStringNotContainsString($syntheticKey,
            json_encode($evidence->records(), JSON_THROW_ON_ERROR));
    }

    public function testInvalidRetryOrderingAndTypeCrossUseAreDenied(): void
    {
        $evidence = new JobRunEvidence();
        $this->invalid(static function () use ($evidence): void {
            $evidence->begin('scheduler', self::KEY, 2, self::time(10));
        });
        self::assertTrue($evidence->begin('scheduler', self::KEY, 1, self::time(10)));
        $this->invalid(static function () use ($evidence): void {
            $evidence->begin('media', self::KEY, 1, self::time(11));
        });
        $this->invalid(static function () use ($evidence): void {
            $evidence->finish(self::KEY, 2, 'succeeded', self::time(12));
        });
        $evidence->finish(self::KEY, 1, 'failed', self::time(12));
        $this->invalid(static function () use ($evidence): void {
            $evidence->begin('scheduler', self::KEY, 3, self::time(13));
        });
        $this->invalid(static function () use ($evidence): void {
            $evidence->begin('scheduler', self::KEY, 2, self::time(9));
        });
        self::assertCount(1, $evidence->records());
    }

    public function testRetryCannotStartBeforePreviousFailureFinished(): void
    {
        $evidence = new JobRunEvidence();
        self::assertTrue($evidence->begin('scheduler', self::KEY, 1, self::time(10)));
        $evidence->finish(self::KEY, 1, 'failed', self::time(20));
        $this->invalid(static function () use ($evidence): void {
            $evidence->begin('scheduler', self::KEY, 2, self::time(19));
        });
        self::assertCount(1, $evidence->records());
        self::assertTrue($evidence->begin('scheduler', self::KEY, 2, self::time(20)));
        $evidence->finish(self::KEY, 2, 'succeeded', self::time(21));
        self::assertSame([1, 2], array_column($evidence->records(), 'attempt'));
        self::assertSame(['failed', 'succeeded'], array_column($evidence->records(), 'result'));
    }

    public function testSubsecondFinishAndRetryCannotPrecedeEarlierEvent(): void
    {
        $evidence = new JobRunEvidence();
        $begin = new DateTimeImmutable('2026-10-30T10:00:00.900000+00:00');
        $beforeBegin = new DateTimeImmutable('2026-10-30T10:00:00.100000+00:00');
        $finish = new DateTimeImmutable('2026-10-30T10:00:01.900000+00:00');
        $beforeFinish = new DateTimeImmutable('2026-10-30T10:00:01.100000+00:00');
        self::assertTrue($evidence->begin('scheduler', self::KEY, 1, $begin));
        self::assertSame('2026-10-30T10:00:00.900000Z', $evidence->records()[0]['started_at']);
        $this->invalid(static function () use ($evidence, $beforeBegin): void {
            $evidence->finish(self::KEY, 1, 'failed', $beforeBegin);
        });
        self::assertSame('running', $evidence->records()[0]['result']);
        $evidence->finish(self::KEY, 1, 'failed', $finish);
        self::assertSame('2026-10-30T10:00:01.900000Z', $evidence->records()[0]['finished_at']);
        $this->invalid(static function () use ($evidence, $beforeFinish): void {
            $evidence->begin('scheduler', self::KEY, 2, $beforeFinish);
        });
        self::assertCount(1, $evidence->records());
        self::assertTrue($evidence->begin('scheduler', self::KEY, 2, $finish));
        $evidence->finish(self::KEY, 2, 'succeeded', $finish);
        self::assertSame(['failed', 'succeeded'], array_column($evidence->records(), 'result'));
        self::assertSame('2026-10-30T10:00:01.900000Z', $evidence->records()[1]['started_at']);
        $localTime = new DateTimeImmutable('2026-10-30T13:00:02.250000+03:00');
        self::assertTrue($evidence->begin('scheduler', self::OTHER, 1, $localTime));
        self::assertSame('2026-10-30T10:00:02.250000Z', $evidence->records()[2]['started_at']);
    }

    public function testFailedRetriesStopAtFiftyAndTotalEvidenceStopsAtOneThousand(): void
    {
        $evidence = new JobRunEvidence();
        for ($attempt = 1; $attempt <= 50; ++$attempt) {
            self::assertTrue($evidence->begin('scheduler', self::KEY, $attempt, self::time($attempt * 2)));
            $evidence->finish(self::KEY, $attempt, 'failed', self::time($attempt * 2 + 1));
        }
        self::assertCount(50, $evidence->records());
        $this->invalid(static function () use ($evidence): void {
            $evidence->begin('scheduler', self::KEY, 51, self::time(102));
        });
        for ($index = 0; $index < 950; ++$index) {
            $key = hash('sha256', 'fixture-run-' . $index);
            self::assertTrue($evidence->begin('scheduler', $key, 1, self::time(200 + $index)));
        }
        self::assertCount(1000, $evidence->records());
        $this->invalid(static function () use ($evidence): void {
            $evidence->begin('scheduler', hash('sha256', 'fixture-overflow'), 1, self::time(1200));
        });
        self::assertCount(1000, $evidence->records());
    }

    public function testEvidenceIsSafeImmutableSnapshotAndNotDurablePersistence(): void
    {
        $evidence = new JobRunEvidence();
        self::assertSame([], $evidence->records());
        self::assertTrue($evidence->begin('media', self::OTHER, 1, self::time(20)));
        $snapshot = $evidence->records();
        $snapshot[0]['result'] = 'tampered';
        self::assertSame('running', $evidence->records()[0]['result']);
        $evidence->finish(self::OTHER, 1, 'failed', self::time(25));
        self::assertSame('failed', $evidence->records()[0]['result']);
        // Another process/object has no shared lock. Not production idempotency.
        self::assertTrue((new JobRunEvidence())->begin('media', self::OTHER, 1, self::time(20)));
        self::assertSame(['type', 'key_sha256', 'attempt', 'started_at', 'finished_at', 'result'],
            array_keys($evidence->records()[0]));
    }

    /** @param callable():void $action */
    private function invalid(callable $action): void
    {
        try {
            $action();
            self::fail('Invalid classified metadata must be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('INVALID_JOB_RUN_EVIDENCE', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString('@example.test', $e->getMessage());
        }
    }

    private static function time(int $unix, string $offset = '+00:00'): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $unix))->setTimezone(new \DateTimeZone($offset));
    }
}
