<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Connectors\ConnectorState;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectorStateTransitionsTest extends TestCase
{
    public function testStartsActiveWithSafeFixedMessageAndNoProviderFields(): void
    {
        $summary = (new ConnectorState())->summary();
        self::assertSame(['state', 'retry_at', 'attempts', 'can_retry', 'message'], array_keys($summary));
        self::assertSame(ConnectorState::ACTIVE, $summary['state']);
        self::assertSame(0, $summary['attempts']);
        self::assertNull($summary['retry_at']);
        self::assertFalse($summary['can_retry']);
        self::assertSame('Conector disponible.', $summary['message']);
    }

    public function testQuotaCannotRetryEarlyAndDoesNotInvokeFakeTransport(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'quota_exhausted', 'retry_after' => 120], 1000);
        self::assertSame(1120, $connector->summary()['retry_at']);
        $calls = 0;
        $fake = static function () use (&$calls): array { ++$calls; return ['status' => 'ok']; };
        self::assertFalse($connector->retry($fake, 1119));
        self::assertSame(0, $calls);
        self::assertSame(ConnectorState::QUOTA_EXHAUSTED, $connector->summary()['state']);
        self::assertTrue($connector->retry($fake, 1120));
        self::assertSame(1, $calls);
        self::assertSame(ConnectorState::ACTIVE, $connector->summary()['state']);
        self::assertSame(0, $connector->summary()['attempts']);
    }

    public function testCannotBypassQuotaWithInjectedOkObservation(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'quota_exhausted', 'retry_after' => 60], 100);
        $this->expectException(LogicException::class);
        $connector->observe(['status' => 'ok'], 101);
    }

    public function testCredentialFailureRequiresAuthorizedReconnectAndVerifiedProbe(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'invalid_credential'], 100);
        self::assertSame(ConnectorState::INVALID_CREDENTIAL, $connector->summary()['state']);
        self::assertFalse($connector->retry(static fn (): array => ['status' => 'ok'], 101));
        try {
            $connector->reconnect(false, 102);
            self::fail('Unapproved reconnect must not be permitted');
        } catch (LogicException) {
            self::assertSame(ConnectorState::INVALID_CREDENTIAL, $connector->summary()['state']);
        }
        $connector->reconnect(true, 103);
        self::assertSame(ConnectorState::RETRYING, $connector->summary()['state']);
        self::assertSame(103, $connector->summary()['retry_at']);
        self::assertTrue($connector->retry(static fn (): array => ['status' => 'ok'], 103));
        self::assertSame(ConnectorState::ACTIVE, $connector->summary()['state']);
    }

    public function testRevocationIsTerminalIncludingExplicitReconnect(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'revoked'], 200);
        $calls = 0;
        self::assertFalse($connector->retry(static function () use (&$calls): array {
            ++$calls;
            return ['status' => 'ok'];
        }, 201));
        self::assertSame(0, $calls);
        $this->expectException(LogicException::class);
        $connector->reconnect(true, 201);
    }

    public function testExplicitRevocationIsIdempotentAndCannotBeUndone(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'retryable_error'], 200);
        $connector->revoke(201);
        $connector->revoke(202);
        self::assertSame(ConnectorState::REVOKED, $connector->summary()['state']);
        self::assertNull($connector->summary()['retry_at']);
        self::assertFalse($connector->retry(static fn (): array => ['status' => 'ok'], 400));
        try {
            $connector->observe(['status' => 'ok'], 401);
            self::fail('Revoked connector cannot be made active');
        } catch (LogicException) {
            self::assertSame(ConnectorState::REVOKED, $connector->summary()['state']);
        }
    }

    public function testTransientFailuresUseBoundedBackoffAndStopAfterThreeAttempts(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'retryable_error'], 100);
        $calls = 0;
        $fake = static function () use (&$calls): array { ++$calls; return ['status' => 'retryable_error']; };
        self::assertSame(160, $connector->summary()['retry_at']);
        self::assertTrue($connector->retry($fake, 160));
        self::assertSame(460, $connector->summary()['retry_at']);
        self::assertTrue($connector->retry($fake, 460));
        self::assertSame(1360, $connector->summary()['retry_at']);
        self::assertTrue($connector->retry($fake, 1360));
        self::assertSame(3, $calls);
        self::assertNull($connector->summary()['retry_at']);
        self::assertFalse($connector->summary()['can_retry']);
        self::assertFalse($connector->retry($fake, 10000));
        self::assertSame(3, $calls);
        self::assertSame(ConnectorState::RETRYING, $connector->summary()['state']);
    }

    public function testQuotaRepeatedWithinRetryDoesNotGrantUnlimitedAttempts(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'quota_exhausted', 'retry_after' => 60], 100);
        $calls = 0;
        $fake = static function () use (&$calls): array { ++$calls; return ['status' => 'quota_exhausted', 'retry_after' => 60]; };
        self::assertTrue($connector->retry($fake, 160));
        self::assertTrue($connector->retry($fake, 220));
        self::assertTrue($connector->retry($fake, 280));
        self::assertSame(3, $calls);
        self::assertFalse($connector->retry($fake, 1000));
        self::assertNull($connector->summary()['retry_at']);
    }

    public function testInvalidProviderPayloadsAreRejectedWithoutStateMutation(): void
    {
        foreach ([
            ['status' => 'quota_exhausted', 'retry_after' => 59],
            ['status' => 'quota_exhausted', 'retry_after' => 3601],
            ['status' => 'quota_exhausted', 'retry_after' => '120'],
            ['status' => 'ok', 'provider_error' => 'private@example.invalid'],
            ['status' => 'revoked', 'access_token' => 'private-synthetic'],
            ['status' => 'unknown'],
            ['status' => null],
            [],
        ] as $signal) {
            $connector = new ConnectorState();
            try {
                $connector->observe($signal, 100);
                self::fail('Unknown or unsafe signal accepted');
            } catch (InvalidArgumentException) {
                self::assertSame(ConnectorState::ACTIVE, $connector->summary()['state']);
                self::assertSame(0, $connector->summary()['attempts']);
            }
        }
    }

    public function testFakeTransportExceptionIsSanitized(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'retryable_error'], 100);
        $fake = static function (): array { throw new RuntimeException('token=synthetic-secret; email=private@example.invalid'); };
        self::assertTrue($connector->retry($fake, 160));
        $summary = $connector->summary();
        self::assertSame(ConnectorState::RETRYING, $summary['state']);
        self::assertSame(460, $summary['retry_at']);
        self::assertStringNotContainsString('synthetic-secret', json_encode($summary, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private@example.invalid', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function testAdversarialFakeResponseIsSanitizedInsteadOfLeakingProviderData(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'retryable_error'], 100);
        $fake = static fn (): array => ['status' => 'ok', 'authorization' => 'Bearer synthetic-secret'];
        self::assertTrue($connector->retry($fake, 160));
        self::assertSame(ConnectorState::RETRYING, $connector->summary()['state']);
        self::assertStringNotContainsString('synthetic-secret', json_encode($connector->summary(), JSON_THROW_ON_ERROR));
    }

    public function testBackwardsClockFailsBeforeAnyTransportIsCalled(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'retryable_error'], 100);
        $calls = 0;
        try {
            $connector->retry(static function () use (&$calls): array { ++$calls; return ['status' => 'ok']; }, 99);
            self::fail('Clock rollback accepted');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $calls);
            self::assertSame(160, $connector->summary()['retry_at']);
        }
    }

    public function testCannotInjectOkWhileRetryingAndCannotRetryAheadOfWindow(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'retryable_error'], 100);
        try {
            $connector->observe(['status' => 'ok'], 101);
            self::fail('Observation bypass accepted');
        } catch (LogicException) {
            self::assertSame(ConnectorState::RETRYING, $connector->summary()['state']);
        }
        $calls = 0;
        self::assertFalse($connector->retry(static function () use (&$calls): array { ++$calls; return ['status' => 'ok']; }, 159));
        self::assertSame(0, $calls);
        self::assertSame(ConnectorState::RETRYING, $connector->summary()['state']);
    }

    public function testReconnectAfterExhaustedRetryNeedsExplicitOwnerAndProbe(): void
    {
        $connector = new ConnectorState();
        $connector->observe(['status' => 'retryable_error'], 100);
        $fake = static fn (): array => ['status' => 'retryable_error'];
        foreach ([160, 460, 1360] as $at) {
            self::assertTrue($connector->retry($fake, $at));
        }
        $this->expectException(LogicException::class);
        $connector->reconnect(false, 1361);
    }
}
