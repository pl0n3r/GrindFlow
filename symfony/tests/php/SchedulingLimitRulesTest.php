<?php

declare(strict_types=1);

use GrindFlow\Scheduling\Limits\{BlockedWindowRule, DailyCapRule, LimitDecision, MinimumSeparationRule};

foreach (['LimitDecision', 'MinimumSeparationRule', 'DailyCapRule', 'BlockedWindowRule'] as $class) {
    require_once dirname(__DIR__, 2) . '/src/Scheduling/Limits/' . $class . '.php';
}

function check(bool $ok, string $case): void {
    if (!$ok) { throw new RuntimeException('Test failed: ' . $case); }
}
function slot(string $utc, string $tenant = 'tenant-1', string $network = 'x', string $account = 'account-1', string $tz = 'UTC', string $campaign = 'one'): array {
    return ['tenant_id' => $tenant, 'network_id' => $network, 'account_id' => $account,
        'timezone' => $tz, 'scheduled_at_utc' => $utc, 'campaign_id' => $campaign, 'status' => 'scheduled'];
}
$cases = [
    'separation' => static function (): void {
        $rule = new MinimumSeparationRule();
        $time = '2026-10-10T12:00:00Z';
        check($rule->evaluate(slot($time), [slot('2026-10-10T11:45:00Z')], 30)->reason === 'min_separation', 'same account');
        check($rule->evaluate(slot($time), [slot('2026-10-10T11:45:00Z', account: 'account-2')], 30)->permitted, 'different account');
        check($rule->evaluate(slot($time), [slot('2026-10-10T11:45:00Z', tenant: 'tenant-2')], 30)->permitted, 'different tenant');
        $foreignBad = slot('invalid', tenant: 'tenant-2'); $foreignBad['status'] = 'unknown';
        check($rule->evaluate(slot($time), [$foreignBad], 30)->permitted, 'foreign tenant bad time/status ignored');
        $foreignBad['tenant_id'] = '';
        check($rule->evaluate(slot($time), [$foreignBad], 30)->reason === 'invalid_input', 'unknown foreign scope rejected');
        check($rule->evaluate(slot($time), [slot('2026-10-10T11:45:00Z', network: 'instagram')], 30)->permitted, 'different network');
        $cancelled = slot('2026-10-10T11:45:00Z'); $cancelled['status'] = 'cancelled';
        check($rule->evaluate(slot($time), [$cancelled], 30)->permitted, 'cancelled does not block');
        $invalid = slot('2026-10-10T11:45:00Z'); $invalid['status'] = 'unknown';
        check($rule->evaluate(slot($time), [$invalid], 30)->reason === 'invalid_input', 'unrecognized status fails closed');
        $nullStatus = slot('2026-10-10T11:45:00Z');
        $nullStatus['status'] = null;
        check($rule->evaluate(slot($time), [$nullStatus], 30)->reason === 'invalid_input', 'explicit null separation status denied');
        unset($nullStatus['status']);
        check($rule->evaluate(slot($time), [$nullStatus], 30)->reason === 'min_separation', 'missing separation status preserves scheduled default');
        check($rule->evaluate(slot($time), [slot('2026-10-10T11:30:00Z')], 30)->permitted, 'exact boundary');
        // Zero turns off separation, never input validation or tenant checks.
        check($rule->evaluate(slot($time), [slot($time)], 0)->permitted, 'zero separation allows coincident UTC slots');
        check($rule->evaluate(slot($time), [slot($time)], -1)->reason === 'invalid_input', 'negative minimum rejected');
        check($rule->evaluate(slot($time), [slot($time)], 10081)->reason === 'invalid_input', 'maximum exceeded');
        $invalidAtZero = slot('invalid');
        check($rule->evaluate(slot($time), [$invalidAtZero], 0)->reason === 'invalid_input', 'zero never bypasses bad timestamps');
        $unknownAtZero = slot($time); $unknownAtZero['status'] = 'unknown';
        check($rule->evaluate(slot($time), [$unknownAtZero], 0)->reason === 'invalid_input', 'zero never bypasses unknown statuses');
        check($rule->evaluate(slot($time), [slot('invalid')], 30)->reason === 'invalid_input', 'invalid existing slot');
    },
    'windows' => static function (): void {
        $rule = new BlockedWindowRule();
        $candidate = slot('2026-10-12T12:00:00Z');
        $windows = [['weekday' => 1, 'start' => '12:00', 'end' => '12:30']];
        $decision = $rule->evaluate($candidate, $windows, []);
        check(!$decision->permitted && $decision->reason === 'blocked_window', 'blocked');
        check($decision->suggestedAtUtc === '2026-10-12T12:30:00Z', 'minimal relocation');
        $seconds = $rule->evaluate(slot('2026-10-12T12:15:59Z'), $windows, []);
        check($seconds->reason === 'blocked_window'
            && $seconds->suggestedAtUtc === '2026-10-12T12:30:00Z',
            'earliest exact minute regardless of candidate seconds');
        check($rule->evaluate($candidate, [], ['2026-10-12'])->suggestedAtUtc === '2026-10-13T00:00:00Z', 'blocked date');
        $fullDay = [['weekday' => 1, 'start' => '00:00', 'end' => '24:00']];
        check($rule->evaluate(slot('2026-10-12T23:59:00Z'), $fullDay, [])->suggestedAtUtc === '2026-10-13T00:00:00Z', '23:59 blocked until midnight');
        check($rule->evaluate($candidate, [['weekday' => 1, 'start' => '24:00', 'end' => '24:00']], [])->reason === 'invalid_input', '24:00 never valid as start');
        $overflow = $rule->evaluate(slot('9999-12-31T23:59:00Z'), [], ['9999-12-31']);
        check($overflow->reason === 'blocked_no_safe_relocation' && $overflow->suggestedAtUtc === null, 'rejected year 10000 suggestion');
        check($rule->evaluate($candidate, [['weekday' => 8, 'start' => '12:00', 'end' => '13:00']], [])->reason === 'invalid_input', 'invalid window');
    },
    'daily' => static function (): void {
        $rule = new DailyCapRule();
        $candidate = slot('2026-10-10T19:00:00Z', tz: 'America/Bogota');
        $scheduled = [slot('2026-10-10T11:00:00Z', campaign: 'one'), slot('2026-10-10T18:00:00Z', campaign: 'two')];
        check($rule->evaluate($candidate, $scheduled, 2)->reason === 'daily_cap', 'all campaigns');
        check($rule->evaluate($candidate, $scheduled, 3)->permitted, 'under cap');
        check($rule->evaluate($candidate, [slot('2026-10-10T18:00:00Z', tenant: 'other')], 1)->permitted, 'tenant isolated');
        $foreignBad = slot('invalid', tenant: 'other'); $foreignBad['status'] = 'unknown';
        check($rule->evaluate($candidate, [$foreignBad], 1)->permitted, 'foreign tenant bad time/status ignored');
        $foreignBad['tenant_id'] = '';
        check($rule->evaluate($candidate, [$foreignBad], 1)->reason === 'invalid_input', 'unknown foreign scope rejected');
        $cancelled = slot('2026-10-10T18:00:00Z'); $cancelled['status'] = 'cancelled';
        check($rule->evaluate($candidate, [$cancelled], 1)->permitted, 'cancelled ignored');
        $nullStatus = slot('2026-10-10T18:00:00Z');
        $nullStatus['status'] = null;
        check($rule->evaluate($candidate, [$nullStatus], 1)->reason === 'invalid_input', 'explicit null daily status denied');
        unset($nullStatus['status']);
        check($rule->evaluate($candidate, [$nullStatus], 1)->reason === 'daily_cap', 'missing daily status preserves scheduled default');
    },
    'timezone' => static function (): void {
        $rule = new BlockedWindowRule();
        $windows = [['weekday' => 7, 'start' => '01:00', 'end' => '02:00']];
        $spring = $rule->evaluate(slot('2026-03-08T06:30:00Z', tz: 'America/New_York'), $windows, []);
        check($spring->suggestedAtUtc === '2026-03-08T07:00:00Z', 'spring gap jumps from 01:59 to 03:00');
        $springSeconds = $rule->evaluate(
            slot('2026-03-08T06:30:59Z', tz: 'America/New_York'), $windows, []);
        check($springSeconds->suggestedAtUtc === '2026-03-08T07:00:00Z',
            'spring gap relocation rounds to exact first valid UTC minute');
        foreach (['2026-11-01T05:30:00Z', '2026-11-01T06:30:00Z'] as $utc) {
            check($rule->evaluate(slot($utc, tz: 'America/New_York'), $windows, [])->reason === 'blocked_window', 'fall fold');
        }
        $day = new DailyCapRule();
        check($day->evaluate(slot('2026-11-01T06:30:00Z', tz: 'America/New_York'), [slot('2026-11-01T05:30:00Z')], 1)->reason === 'daily_cap', 'fold same local day');
        check($day->evaluate(slot('2026-11-01T06:30:00Z', tz: 'Not/AZone'), [], 1)->reason === 'invalid_input', 'unknown tz');
    },
];
$choice = $argv[1] ?? null;
if ($choice !== null && !isset($cases[$choice])) { fwrite(STDERR, "Unknown case\n"); exit(2); }
foreach ($cases as $name => $test) {
    if ($choice === null || $choice === $name) { $test(); echo "PASS {$name}\n"; }
}
