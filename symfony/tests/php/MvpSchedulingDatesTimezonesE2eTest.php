<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use GrindFlow\Scheduling\Preview\GlobalPauseState;
use GrindFlow\Scheduling\Recovery\RetryClassifier;
use GrindFlow\Scheduling\ScheduleDraftApplicationService;
use GrindFlow\Scheduling\WeeklySlotCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Offline scheduling regression using deterministic clocks and fake transport only. */
final class MvpSchedulingDatesTimezonesE2eTest extends TestCase
{
    private static function rule(string $timezone, string $weekday, string $time): array
    {
        return [
            'timezone' => $timezone,
            'weekdays' => $weekday,
            'local_time' => $time,
            'max_per_day' => 2,
        ];
    }

    private static function failure(): array
    {
        return [
            'tenant_id' => 'synthetic_tenant',
            'delivery_id' => 'synthetic_delivery',
            'delivery_state' => 'failed',
            'failure_code' => 'rate_limited',
            'idempotency_key' => 'synthetic_key',
            'idempotency_proven' => true,
            'attempt' => 1,
            'max_attempts' => 3,
        ];
    }

    public function testBogotaAndUtcRulesDescribeTheSameInstant(): void
    {
        $clock = new DateTimeImmutable('2026-11-01T12:00:00Z');
        $calculator = new WeeklySlotCalculator();
        $bogota = $calculator->upcoming(self::rule('America/Bogota', 'mon', '09:30'), $clock);
        $utc = $calculator->upcoming(self::rule('UTC', 'mon', '14:30'), $clock);

        self::assertCount(1, $bogota);
        self::assertCount(1, $utc);
        self::assertSame('2026-11-02T14:30:00Z', $bogota[0]['scheduled_at_utc']);
        self::assertSame($bogota[0]['scheduled_at_utc'], $utc[0]['scheduled_at_utc']);
        self::assertSame('America/Bogota', $bogota[0]['timezone']);
    }

    public function testPastAndExactClockSlotsAreExcluded(): void
    {
        $calculator = new WeeklySlotCalculator();
        $rule = self::rule('America/Bogota', 'mon', '09:30');
        $before = $calculator->upcoming($rule, new DateTimeImmutable('2026-11-02T14:29:59Z'));
        $onTime = $calculator->upcoming($rule, new DateTimeImmutable('2026-11-02T14:30:00Z'));
        $after = $calculator->upcoming($rule, new DateTimeImmutable('2026-11-02T14:30:01Z'));

        self::assertSame('2026-11-02T14:30:00Z', $before[0]['scheduled_at_utc']);
        self::assertSame('2026-11-09T14:30:00Z', $onTime[0]['scheduled_at_utc']);
        self::assertSame($onTime, $after);
        self::assertSame([], $calculator->upcoming(false, new DateTimeImmutable('2026-11-02T14:29:59Z')));
    }

    public function testBogotaSundayNightCrossesUtcWeekBoundary(): void
    {
        $clock = new DateTimeImmutable('2026-11-02T03:00:00Z');
        $calculator = new WeeklySlotCalculator();
        $bogota = $calculator->upcoming(self::rule('America/Bogota', 'sun', '23:30'), $clock);
        $utc = $calculator->upcoming(self::rule('UTC', 'mon', '04:30'), $clock);

        self::assertSame('2026-11-01', $bogota[0]['local_date']);
        self::assertSame('sun', $bogota[0]['weekday']);
        self::assertSame('2026-11-02T04:30:00Z', $bogota[0]['scheduled_at_utc']);
        self::assertSame($bogota[0]['scheduled_at_utc'], $utc[0]['scheduled_at_utc']);
    }

    public function testValidityIsUtcAndHalfOpenAtBothLimits(): void
    {
        $from = '2026-11-02 14:30:00';
        $until = '2026-11-02 15:00:00';
        self::assertSame('not_yet_valid', ScheduleDraftApplicationService::validityState($from, $until, '2026-11-02 14:29:59'));
        self::assertSame('valid', ScheduleDraftApplicationService::validityState($from, $until, $from));
        self::assertSame('valid', ScheduleDraftApplicationService::validityState($from, $until, '2026-11-02 14:59:59'));
        self::assertSame('expired', ScheduleDraftApplicationService::validityState($from, $until, $until));
        self::assertSame('valid', ScheduleDraftApplicationService::validityState(null, null, '2026-11-02 14:30:00'));
    }

    public function testPauseResumePreservesAgendaAndRequiresIncreasingAuditClock(): void
    {
        $agenda = [['id' => 'draft_1', 'scheduled_at_utc' => '2026-11-02T14:30:00Z']];
        $unchanged = serialize($agenda);
        $initial = GlobalPauseState::initial($agenda);
        $paused = GlobalPauseState::transition($initial, 'pause', 'maintenance', 101);
        $resumed = GlobalPauseState::transition($paused, 'resume', 'recovered', 102);

        self::assertTrue(GlobalPauseState::canRun($initial));
        self::assertFalse(GlobalPauseState::canRun($paused));
        self::assertTrue(GlobalPauseState::canRun($resumed));
        self::assertSame(2, $resumed['revision']);
        self::assertSame($initial['agenda_sha256'], $resumed['agenda_sha256']);
        self::assertTrue(GlobalPauseState::agendaUnchanged($resumed, $agenda));
        self::assertSame($unchanged, serialize($agenda));

        try {
            GlobalPauseState::transition($paused, 'resume', 'recovered', 101);
            self::fail('A repeated audit timestamp must not resume the schedule.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('out_of_order_pause_transition', $e->getMessage());
        }
        $changed = $agenda;
        $changed[0]['scheduled_at_utc'] = '2026-11-02T15:00:00Z';
        self::assertFalse(GlobalPauseState::agendaUnchanged($resumed, $changed));
    }

    public function testFakeTransportRetriesOnceWithoutReplayingTerminalDelivery(): void
    {
        $classifier = new RetryClassifier();
        $failure = self::failure();
        $attempts = [];
        $transport = static function (string $key) use (&$attempts): void {
            $attempts[$key] = ($attempts[$key] ?? 0) + 1;
        };
        self::assertSame('retry_safe', $classifier->classify($failure)['decision']);
        if ($classifier->classify($failure)['decision'] === 'retry_safe') {
            $transport($failure['idempotency_key']);
        }
        $failure['delivery_state'] = 'succeeded';
        self::assertSame('retry_never', $classifier->classify($failure)['decision']);
        if ($classifier->classify($failure)['decision'] === 'retry_safe') {
            $transport($failure['idempotency_key']);
        }
        self::assertSame(['synthetic_key' => 1], $attempts);
    }

    public function testUnsafeAmbiguousAndExhaustedRetriesFailClosed(): void
    {
        $classifier = new RetryClassifier();
        foreach ([
            ['delivery_state', 'ambiguous', 'retry_never'],
            ['delivery_state', 'in_flight', 'retry_never'],
            ['failure_code', 'timeout_after_send', 'retry_never'],
            ['failure_code', 'authentication_failed', 'needs_human'],
            ['idempotency_proven', false, 'retry_never'],
            ['attempt', 3, 'retry_never'],
            ['max_attempts', 0, 'retry_never'],
            ['tenant_id', 'invalid tenant', 'retry_never'],
        ] as [$field, $value, $decision]) {
            $failure = self::failure();
            $failure[$field] = $value;
            self::assertSame($decision, $classifier->classify($failure)['decision'], $field.'='.var_export($value, true));
        }
        $unknown = self::failure();
        $unknown['provider_payload'] = 'unexpected';
        self::assertSame('invalid_input', $classifier->classify($unknown)['reason']);
        $beforeSend = self::failure();
        $beforeSend['failure_code'] = 'transient_before_send';
        self::assertSame('retry_safe', $classifier->classify($beforeSend)['decision']);
    }
}
