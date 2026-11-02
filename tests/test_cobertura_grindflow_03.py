#!/usr/bin/env python3
"""GrindFlow #756: portable offline probes of *real PHP* with synthetic input.

These exercises cover isolated, existing rules only. They do NOT attest the
end-to-end S4 agenda, account rotation, production hosting or authenticated DB.
Rows requiring those environments must be classified B/C in the PR and Issue.
Never reads/compares the coverage-matrix status and never calls the network.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

PHP_PROBE = r'''
declare(strict_types=1);
require_once 'symfony/src/Scheduling/WeeklySlotCalculator.php';
require_once 'symfony/src/Scheduling/Studio/InheritedRuleSet.php';
require_once 'symfony/src/Identity/Security/PasswordPolicy.php';
require_once 'symfony/src/Shared/Version/ProductVersion.php';
$assert = static function (bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('OFFLINE_ASSERT:' . $label); }
};
$mode = $argv[1] ?? '';
$slots = new \GrindFlow\Scheduling\WeeklySlotCalculator();
$studio = new \GrindFlow\Scheduling\Studio\InheritedRuleSet();
$policy = new \GrindFlow\Identity\Security\PasswordPolicy();
$version = new \GrindFlow\Shared\Version\ProductVersion(getcwd());
$source = [
    'tenant_id' => 'tenantA', 'studio_id' => 'studioA',
    'rules' => [
        'daily_cap' => 3, 'minimum_gap_minutes' => 15,
        'approval_required' => true,
    ],
];
$clock = new \DateTimeImmutable('2026-10-29T12:00:00-05:00');
$rule = [
    'timezone' => 'America/Bogota', 'weekdays' => 'thu',
    'local_times' => ['16:00','20:00'], 'max_per_day' => 2,
];
if ($mode === 'principal') {
    $upcoming = $slots->upcoming($rule, $clock);
    $assert(count($upcoming) === 2, 'two future S4 preview slots');
    $assert(array_column($upcoming, 'scheduled_at_utc') ===
        ['2026-10-29T21:00:00Z','2026-10-30T01:00:00Z'],
        'correct IANA-to-UTC timestamps');
    $assert(array_column($upcoming, 'capacity') === [2,2],
        'derived slots carry capacity but do not persist');
    $assert($studio->resolve('tenantA', 'creatorA', $source)['status'] === 'resolved',
        'tenant-specific valid Studio rules');
    $assert($studio->resolve('tenantA', 'creatorA', $source)['execution_performed'] === false,
        'Studio rules do not perform provider actions');
    $assert($policy->isAcceptable('LongSynthet1c-Passphrase'),
        'valid synthetic password passes real policy');
    $release = $version->human();
    $assert(preg_match('/^\d+\.\d+\.\d+$/D', $release) === 1,
        'valid human release version read from real source');
    $assert(strlen($release) < 24 && $release !== 'VALIDATED_IN_PRODUCTION',
        'human version cannot be treated as deployment proof');
    $covered = ['SPEC:018-preview-only','SPEC:021-Studio-only',
                'SPEC:025-policy-only','SPEC:030-human-version-only'];
} elseif ($mode === 'negative') {
    $bad = $rule;
    $bad['local_times'] = ['25:99'];
    $assert($slots->upcoming($bad, $clock) === [],
        'invalid S4 preview slot refuses to guess');
    $assert($slots->upcoming(false, $clock) === [],
        'missing rule is no permission to schedule');
    $foreign = $source;
    $foreign['tenant_id'] = 'tenantB';
    $assert($studio->resolve('tenantA','creatorA',$foreign)['status'] === 'blocked',
        'different-tenant Studio source rejected');
    $override = ['tenant_id'=>'tenantA','creator_id'=>'creatorA',
        'changes'=>['approval_required'=>false]];
    $assert($studio->resolve('tenantA','creatorA',$source,$override)['status'] === 'blocked',
        'approval rule cannot be relaxed');
    $assert(!$policy->isAcceptable('password1234'),
        'known weak synthetic password denied');
    $assert(!$policy->isAcceptable('short'), 'too-short password denied');
    $missing = new \GrindFlow\Shared\Version\ProductVersion('/nonexistent-synthetic-path');
    $assert($missing->human() === 'unavailable',
        'missing release identity is not invented');
    $covered = ['DENIED_WRONG_TENANT','DENIED_WEAK_PASSWORD',
                'DENIED_INVALID_SLOT','UNKNOWN_MISSING_VERSION'];
} else {
    throw new InvalidArgumentException('INVALID_PROBE_MODE');
}
echo json_encode(['assertions'=>'real-PHP', 'probes'=>$covered,
    'network'=>'disabled','database'=>'disabled','production'=>'UNKNOWN'],
    JSON_THROW_ON_ERROR);
'''


def run_php(mode: str) -> dict:
    """Run source functions in a PHP process: no mocks or outbound APIs."""
    if mode not in ('principal', 'negative'):
        raise ValueError('Unknown synthetic mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI is not installed')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', PHP_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError(f'UNKNOWN: real PHP probe failed: '
                             f'{(result.stderr + result.stdout)[-1200:]}')
    try:
        output = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid PHP output') from exc
    if (output.get('assertions') != 'real-PHP'
            or output.get('production') != 'UNKNOWN'
            or output.get('network') != 'disabled'
            or output.get('database') != 'disabled'):
        raise AssertionError('UNKNOWN: probe provenance and isolation unverified')
    return output




# SPEC:029 (partial): the real retry classifier only decides whether a retry
# would be safe; it does not append events, send a request, or prove a DB ledger.
# The ledger itself requires a separate Doctrine DBAL integration test.
RETRY_PROBE = r'''
declare(strict_types=1);
require_once 'symfony/src/Scheduling/Recovery/RetryClassifier.php';
$policy = new \GrindFlow\Scheduling\Recovery\RetryClassifier();
$base = [
    'tenant_id' => 'tenantSynthetic',
    'delivery_id' => 'deliverySynthetic',
    'delivery_state' => 'failed',
    'failure_code' => 'rate_limited',
    'idempotency_key' => 'idempotencySynthetic',
    'idempotency_proven' => true,
    'attempt' => 1,
    'max_attempts' => 3,
];
$check = static function (array $input, string $decision, string $reason) use ($policy): void {
    $actual = $policy->classify($input);
    if ($actual !== ['decision' => $decision, 'reason' => $reason]) {
        throw new \RuntimeException('OFFLINE_RETRY_EXPECTED:' . $reason);
    }
};
$modified = static function (array $overrides) use ($base): array {
    return array_replace($base, $overrides);
};
$mode = $argv[1] ?? '';
if ($mode === 'positive') {
    $check($base, 'retry_safe', 'idempotent_transient_failure');
    $check($modified(['failure_code' => 'transient_before_send']),
        'retry_safe', 'idempotent_transient_failure');
    $checks = 2;
} elseif ($mode === 'negative') {
    $cases = [
        [$modified(['idempotency_proven' => false]), 'retry_never', 'idempotency_unverified'],
        [$modified(['idempotency_key' => 'bad key!']), 'retry_never', 'idempotency_unverified'],
        [$modified(['attempt' => 3]), 'retry_never', 'attempt_budget_exhausted'],
        [$modified(['delivery_state' => 'ambiguous']), 'retry_never', 'external_state_ambiguous'],
        [$modified(['failure_code' => 'timeout_after_send']), 'retry_never', 'external_state_ambiguous'],
        [$modified(['failure_code' => 'authentication_failed']), 'needs_human', 'authorization_required'],
        [$modified(['failure_code' => 'permission_denied']), 'needs_human', 'authorization_required'],
        [$modified(['delivery_state' => 'succeeded']), 'retry_never', 'not_a_confirmed_failure'],
        [$modified(['failure_code' => 'unknown']), 'retry_never', 'not_retriable'],
        [$modified(['tenant_id' => '']), 'retry_never', 'invalid_input'],
        [$modified(['max_attempts' => 6]), 'retry_never', 'invalid_input'],
        [$modified(['idempotency_proven' => 'true']), 'retry_never', 'invalid_input'],
        [$modified(['unexpected' => 'data']), 'retry_never', 'invalid_input'],
    ];
    foreach ($cases as [$input, $decision, $reason]) {
        $check($input, $decision, $reason);
    }
    $checks = count($cases);
} elseif ($mode === 'boundaries') {
    // A retry can only be considered when every strict structural gate passes.
    $safe = [
        ['attempt' => 0, 'max_attempts' => 1],
        ['attempt' => 4, 'max_attempts' => 5],
        ['idempotency_key' => 'a'],
        ['idempotency_key' => str_repeat('a', 128)],
        ['idempotency_key' => 'key_X-09'],
        ['failure_code' => 'transient_before_send'],
    ];
    foreach ($safe as $overrides) {
        $check($modified($overrides), 'retry_safe', 'idempotent_transient_failure');
    }
    $denied = [
        [['attempt' => 1, 'max_attempts' => 1], 'attempt_budget_exhausted'],
        [['attempt' => 5, 'max_attempts' => 5], 'attempt_budget_exhausted'],
        [['idempotency_key' => '_bad'], 'idempotency_unverified'],
        [['idempotency_key' => str_repeat('a', 129)], 'idempotency_unverified'],
        [['idempotency_key' => 'with space'], 'idempotency_unverified'],
        [['idempotency_key' => "a\n"], 'idempotency_unverified'],
        [['idempotency_key' => 'ñ'], 'idempotency_unverified'],
        [['tenant_id' => '_bad'], 'invalid_input'],
        [['tenant_id' => 'a b'], 'invalid_input'],
        [['delivery_id' => ''], 'invalid_input'],
        [['delivery_id' => str_repeat('z', 129)], 'invalid_input'],
        [['attempt' => -1], 'invalid_input'],
        [['attempt' => true], 'invalid_input'],
        [['attempt' => 1.0], 'invalid_input'],
        [['attempt' => '1'], 'invalid_input'],
        [['max_attempts' => 0], 'invalid_input'],
        [['max_attempts' => 6], 'invalid_input'],
        [['max_attempts' => false], 'invalid_input'],
        [['idempotency_proven' => 1], 'invalid_input'],
        [['delivery_state' => 'pending'], 'invalid_input'],
        [['failure_code' => 'an_unknown_provider_error'], 'invalid_input'],
        [['failure_code' => 'provider_rejected'], 'not_retriable'],
        [['delivery_state' => 'in_flight'], 'not_a_confirmed_failure'],
    ];
    foreach ($denied as [$overrides, $reason]) {
        $check($modified($overrides), 'retry_never', $reason);
    }
    $missing = $base;
    unset($missing['tenant_id']);
    $check($missing, 'retry_never', 'invalid_input');
    $extra = $base;
    $extra['provider_access_token'] = 'NEVER_ACCEPT_UNKNOWN_FIELD';
    $check($extra, 'retry_never', 'invalid_input');
    $checks = count($safe) + count($denied) + 2;
} else {
    throw new \InvalidArgumentException('UNKNOWN_RETRY_PROBE_MODE');
}
echo json_encode(['checks' => $checks, 'source' => 'real-PHP',
    'ledger' => 'UNKNOWN', 'network' => 'disabled', 'production' => 'UNKNOWN'],
    JSON_THROW_ON_ERROR);
'''


def run_retry_php(mode: str) -> dict:
    """Exercise production RetryClassifier in PHP without any provider I/O."""
    if mode not in ('positive', 'negative', 'boundaries'):
        raise ValueError('Invalid retry mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI is unavailable')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', RETRY_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError('UNKNOWN: retry classifier rejected contract: '
                             + (result.stderr + result.stdout)[-1000:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid PHP output') from exc
    if (data.get('source') != 'real-PHP' or data.get('ledger') != 'UNKNOWN'
            or data.get('network') != 'disabled'
            or data.get('production') != 'UNKNOWN'):
        raise AssertionError('UNKNOWN: retry provenance cannot be established')
    return data


# SPEC:018 + SPEC:021 (partial): real pure-PHP tenant-isolated horizon slots.
# It does NOT test persisted reservations, HTTP, transactions or cancellation.
HORIZON_PROBE = r'''
declare(strict_types=1);
require_once 'symfony/src/Scheduling/Horizon/GapDetector.php';
$detector = new \GrindFlow\Scheduling\Horizon\GapDetector();
$assert = static function (bool $condition, string $reason): void {
    if (!$condition) { throw new \RuntimeException('HORIZON_ASSERT:' . $reason); }
};
$scheduled = [
    ['tenant_id'=>'tenantA','date'=>'2026-03-07','slot'=>1,'status'=>'draft'],
    ['tenant_id'=>'tenantB','date'=>'2026-03-07','slot'=>2,'status'=>'scheduled'],
    ['tenant_id'=>'tenantA','date'=>'2026-03-08','slot'=>1,'status'=>'scheduled'],
    ['tenant_id'=>'tenantA','date'=>'2026-03-07','slot'=>1,'status'=>'draft'],
];
$inspect = static function (array $entries, bool $permitted = true, array $blockedDates = ['2026-03-09'],
    array $blockedSlots = [['date'=>'2026-03-08','slot'=>2]]) use ($detector): array {
    return $detector->detect('tenantA', 'America/New_York', '2026-03-07', 3,
        2, $entries, $blockedDates, $blockedSlots, $permitted);
};
$mode = $argv[1] ?? '';
if ($mode === 'principal') {
    $result = $inspect($scheduled);
    $assert($result === [
        'status'=>'ok', 'reason'=>'observed',
        'gaps'=>[['date'=>'2026-03-07','slot'=>2]],
        'required'=>3, 'scheduled'=>2,
    ], 'foreign tenant cannot consume capacity; duplicates deduplicated');
    $foreign = $detector->detect('tenantB', 'America/New_York', '2026-03-07',
        3, 2, $scheduled, ['2026-03-09'], [['date'=>'2026-03-08','slot'=>2]]);
    $assert($foreign['status'] === 'ok' && $foreign['required'] === 3 &&
        $foreign['scheduled'] === 1 && $foreign['gaps'] === [
            ['date'=>'2026-03-07','slot'=>1],['date'=>'2026-03-08','slot'=>1],
        ], 'reverse-tenant scope and DST calendar');
    $checks = 2;
} elseif ($mode === 'negative') {
    $cases = [
        [$inspect($scheduled, false), 'invalid_or_unauthorized'],
        [$detector->detect('', 'UTC', '2026-03-07', 1, 1, []), 'invalid_or_unauthorized'],
        [$detector->detect('tenantA', 'UTC', '2026-03-07', 0, 1, []), 'invalid_or_unauthorized'],
        [$detector->detect('tenantA', 'Mars/Pluto', '2026-03-07', 1, 1, []), 'invalid_timezone'],
        [$detector->detect('tenantA', 'UTC', '2026-02-30', 1, 1, []), 'invalid_date'],
        [$inspect($scheduled, true, ['2026-02-30']), 'invalid_block'],
        [$inspect($scheduled, true, [], [['date'=>'2026-03-07','slot'=>3]]), 'invalid_block'],
        [$inspect([['tenant_id'=>'tenantB','date'=>'2026-03-07','slot'=>1,'status'=>'mystery']]),
            'uncertain_schedule'],
        [$inspect([['tenant_id'=>'tenantB','date'=>'2026-03-07','slot'=>'1']]),
            'invalid_schedule'],
        [$inspect(['sparse' => $scheduled[0]]), 'invalid_or_unauthorized'],
    ];
    foreach ($cases as [$actual, $reason]) {
        $assert($actual === ['status'=>'rejected', 'reason'=>$reason,
            'gaps'=>[], 'required'=>0, 'scheduled'=>0], $reason);
    }
    $checks = count($cases);
} else {
    throw new \InvalidArgumentException('UNKNOWN_HORIZON_PROBE_MODE');
}
echo json_encode(['checks'=>$checks, 'source'=>'real-PHP', 'database'=>'disabled',
    'network'=>'disabled', 'production'=>'UNKNOWN'], JSON_THROW_ON_ERROR);
'''


def run_horizon_php(mode: str) -> dict:
    # Exercise the actual GapDetector PHP class, with synthetic data only.
    if mode not in ('principal', 'negative'):
        raise ValueError('Invalid horizon mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI is unavailable')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', HORIZON_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError('UNKNOWN: horizon PHP rejected contract: '
                             + (result.stderr + result.stdout)[-1000:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid horizon output') from exc
    if (data.get('source') != 'real-PHP' or data.get('production') != 'UNKNOWN'
            or data.get('network') != 'disabled'
            or data.get('database') != 'disabled'):
        raise AssertionError('UNKNOWN: horizon provenance unverified')
    return data




# SPEC:018/021 (partial) — pure policy gating, never actual scheduler writes.
AUTOMATION_PROBE = r'''
declare(strict_types=1);
require_once 'symfony/src/Scheduling/Mode/AutomationMode.php';
$engine = new \GrindFlow\Scheduling\Mode\AutomationMode();
$context = ['tenant_id'=>'tenantSynthetic', 'account_id'=>'accountSynthetic',
    'permission_granted'=>true, 'content_eligible'=>true,
    'within_daily_cap'=>true, 'within_time_window'=>true,
    'minimum_separation_met'=>true, 'external_state_known'=>true,
    'approval_granted'=>true];
$check = static function (string $mode, array $c, bool $confirmed,
    string $status, string $reason) use ($engine): void {
    $actual = $engine->assess($mode, $c, $confirmed);
    if ($actual !== ['status'=>$status, 'reason'=>$reason,
                    'execution_performed'=>false]) {
        throw new \RuntimeException('MODE_MISMATCH:' . $mode . ':' . $reason);
    }
};
$mode = $argv[1] ?? '';
if ($mode === 'principal') {
    $check('manual', $context, true, 'manual_action_required', 'manual_mode');
    $check('assisted', $context, false, 'confirmation_required', 'assisted_mode');
    $check('assisted', $context, true, 'eligible', 'confirmation_verified');
    $check('autopilot', $context, false, 'eligible', 'pilot_rules_verified');
    $check('pilot', $context, false, 'eligible', 'pilot_rules_verified');
    $checks = 5;
} elseif ($mode === 'negative') {
    foreach (['permission_granted','content_eligible','within_daily_cap',
        'within_time_window','minimum_separation_met','external_state_known',
        'approval_granted'] as $gate) {
        $bad = $context;
        $bad[$gate] = false;
        $check('autopilot', $bad, true, 'blocked', 'hard_rule_denied');
        $bad[$gate] = 1;
        $check('assisted', $bad, true, 'blocked', 'hard_rule_denied');
    }
    $missing = $context;
    unset($missing['approval_granted']);
    $check('autopilot', $missing, true, 'blocked', 'invalid_context');
    $extra = $context;
    $extra['provider_key'] = 'SYNTHETIC_NOT_SECRET';
    $check('autopilot', $extra, true, 'blocked', 'invalid_context');
    $foreign = $context;
    $foreign['tenant_id'] = '';
    $check('autopilot', $foreign, true, 'blocked', 'invalid_context');
    $check('unknown', $context, true, 'blocked', 'invalid_context');
    $checks = 18;
} else {
    throw new \InvalidArgumentException('UNKNOWN_MODE_PROBE');
}
echo json_encode(['checks'=>$checks, 'source'=>'real-PHP',
    'execution_performed'=>false, 'network'=>'disabled',
    'production'=>'UNKNOWN'], JSON_THROW_ON_ERROR);
'''


# SPEC:018 (partial) — a pure journal/fingerprint guard, not persisted slots.
PAUSE_PROBE = r'''
declare(strict_types=1);
require_once 'symfony/src/Scheduling/Preview/GlobalPauseState.php';
use GrindFlow\Scheduling\Preview\GlobalPauseState as Pause;
$agenda = [['slot'=>'slotA','tenant_id'=>'tenantSynthetic']];
$check = static function (bool $ok, string $reason): void {
    if (!$ok) throw new \RuntimeException('PAUSE_MISMATCH:' . $reason);
};
$reject = static function (callable $run, string $reason) use ($check): void {
    try { $run(); } catch (\InvalidArgumentException) { return; }
    $check(false, $reason);
};
$state = Pause::initial($agenda);
$mode = $argv[1] ?? '';
if ($mode === 'principal') {
    $check(Pause::canRun($state), 'initial can run');
    $check(Pause::agendaUnchanged($state, $agenda), 'initial agenda digest');
    $paused = Pause::transition($state, 'pause', 'manual', 10);
    $check(!Pause::canRun($paused) && $paused['revision'] === 1, 'pause denies execution');
    $check(Pause::agendaUnchanged($paused, $agenda), 'pause cannot edit agenda');
    $resumed = Pause::transition($paused, 'resume', 'recovered', 20);
    $check(Pause::canRun($resumed) && $resumed['revision'] === 2, 'resume authorization');
    $check(Pause::agendaUnchanged($resumed, $agenda), 'resume unchanged agenda');
    $checks = 6;
} elseif ($mode === 'negative') {
    $paused = Pause::transition($state, 'pause', 'incident', 10);
    $check(!Pause::agendaUnchanged($paused, [['slot'=>'other']]), 'digest denies changed agenda');
    $reject(static fn () => Pause::transition($paused, 'pause', 'manual', 11), 'duplicate pause');
    $reject(static fn () => Pause::transition($paused, 'resume', 'recovered', 10), 'clock reversal');
    $reject(static fn () => Pause::transition($paused, 'resume', 'unknown', 11), 'unknown reason');
    $reject(static fn () => Pause::transition($paused, 'publish', 'manual', 11), 'invalid action');
    $corrupt = $paused;
    $corrupt['audit'][0]['reason_code'] = 'forged';
    $reject(static fn () => Pause::canRun($corrupt), 'invalid journal');
    $corrupt = $paused;
    $corrupt['agenda_sha256'] = str_repeat('0', 32);
    $reject(static fn () => Pause::canRun($corrupt), 'invalid fingerprint');
    $corrupt = $state;
    $corrupt['unknown_authority'] = true;
    $reject(static fn () => Pause::canRun($corrupt), 'unknown field');
    $reject(static fn () => Pause::initial([new \stdClass()]), 'invalid agenda payload');
    $checks = 9;
} else {
    throw new \InvalidArgumentException('UNKNOWN_PAUSE_PROBE');
}
echo json_encode(['checks'=>$checks, 'source'=>'real-PHP',
    'persistence'=>'UNKNOWN', 'network'=>'disabled',
    'production'=>'UNKNOWN'], JSON_THROW_ON_ERROR);
'''


def run_eligibility_php(probe: str, mode: str) -> dict:
    """Use authentic pure PHP code; enforce explicit UNKNOWN for production."""
    if probe not in ('automation', 'pause') or mode not in ('principal', 'negative'):
        raise ValueError('Unknown policy probe')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP is unavailable')
    source = AUTOMATION_PROBE if probe == 'automation' else PAUSE_PROBE
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', source, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError('UNKNOWN: policy PHP check failed: '
                             + (result.stderr + result.stdout)[-1200:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: malformed policy proof') from exc
    if (data.get('source') != 'real-PHP' or data.get('network') != 'disabled'
            or data.get('production') != 'UNKNOWN'
            or (probe == 'automation' and data.get('execution_performed') is not False)
            or (probe == 'pause' and data.get('persistence') != 'UNKNOWN')):
        raise AssertionError('UNKNOWN: provenance/side effects not established')
    return data

# SPEC:029 partial: validate the real DistributionCommand DTO in pure PHP.
# This does not publish, create a delivery, verify a provider or append a ledger.
# The local image lacks mbstring; an ASCII-only fallback is used for these
# strictly synthetic ASCII fixtures. This is not a multibyte validation claim.
DISTRIBUTION_PROBE = r'''
declare(strict_types=1);
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value): int { return strlen($value); }
}
require_once 'symfony/src/Distribution/DistributionCommand.php';
use GrindFlow\Distribution\DistributionCommand;
$base = [
    'organizationId' => '01234567-89ab-4cde-8fab-0123456789ab',
    'idempotencyKey' => 'synthetic-key-123',
    'message' => 'Synthetic prepared content',
    'link' => null, 'mediaPath' => null, 'mediaMime' => null,
    'mediaSha256' => null,
];
$media = [
    'mediaPath' => '/synthetic/asset.png',
    'mediaMime' => 'image/png',
    'mediaSha256' => str_repeat('a', 64),
];
$make = static fn (array $overrides): DistributionCommand =>
    new DistributionCommand(...array_replace($base, $overrides));
$assert = static function (bool $ok, string $case): void {
    if (!$ok) { throw new \RuntimeException('OFFLINE_DISTRIBUTION_ASSERT:' . $case); }
};
$mode = $argv[1] ?? '';
if ($mode === 'positive') {
    $assert(!$make([])->hasMedia(), 'default-no-media');
    $assert(!$make(['link'=>'https://safe.invalid/test'])->hasMedia(), 'link-no-media');
    $assert(!$make(['link'=>'http://safe.invalid/test'])->hasMedia(), 'http-accepted');
    $assert($make($media)->hasMedia(), 'all-media-fields');
    $assert($make(array_replace($media, ['mediaMime'=>'video/quicktime']))->hasMedia(), 'supported-mime');
    $assert($make(['idempotencyKey'=>str_repeat('k', 160)])->idempotencyKey !== '', 'key-at-boundary');
    $assert(strlen($make(['message'=>str_repeat('m', 5000)])->message) === 5000, 'message-at-boundary');
    $assert($make(['organizationId'=>'01234567-89AB-4CDE-8FAB-0123456789AB'])->organizationId !== '', 'uppercase-uuid');
    $checks = 8;
} elseif ($mode === 'negative') {
    $cases = [
        ['organizationId'=>'not-a-uuid'],
        ['organizationId'=>'01234567-89ab-0cde-8fab-0123456789ab'],
        ['idempotencyKey'=>''],
        ['idempotencyKey'=>str_repeat('k', 161)],
        ['message'=>'   '],
        ['message'=>str_repeat('m', 5001)],
        ['link'=>'ftp://safe.invalid/test'],
        ['link'=>'/relative/test'],
        ['link'=>'https://'],
        ['link'=>str_repeat('q', 2049)],
        ['mediaPath'=>'/synthetic/asset.png'],
        array_replace($media, ['mediaPath'=>'']),
        array_replace($media, ['mediaPath'=>"/synthetic/\0bad"]),
        array_replace($media, ['mediaMime'=>'application/octet-stream']),
        array_replace($media, ['mediaSha256'=>'invalid-sha']),
        array_replace($media, ['mediaSha256'=>str_repeat('g',64)]),
        array_replace($media, ['link'=>'https://safe.invalid/test']),
        array_replace($media, ['mediaMime'=>null]),
    ];
    foreach ($cases as $index => $fields) {
        try {
            $make($fields);
        } catch (\InvalidArgumentException $exception) {
            continue;
        }
        throw new \RuntimeException('OFFLINE_DISTRIBUTION_ACCEPTED_UNSAFE_CASE:' . $index);
    }
    $checks = count($cases);
} else {
    throw new \InvalidArgumentException('UNKNOWN_DISTRIBUTION_MODE');
}
echo json_encode(['checks'=>$checks, 'source'=>'real-PHP',
    'network'=>'disabled', 'database'=>'disabled', 'provider_calls'=>false,
    'production'=>'UNKNOWN', 'mbstring'=>'ASCII-only-fixtures'], JSON_THROW_ON_ERROR);
'''


def run_distribution_php(mode: str) -> dict:
    if mode not in ('positive', 'negative'):
        raise ValueError('Unknown distribution mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI unavailable')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', DISTRIBUTION_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError('UNKNOWN: distribution PHP failed: '
                             + (result.stderr + result.stdout)[-1000:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid distribution output') from exc
    if (data.get('source') != 'real-PHP'
            or data.get('network') != 'disabled'
            or data.get('database') != 'disabled'
            or data.get('provider_calls') is not False
            or data.get('production') != 'UNKNOWN'
            or data.get('mbstring') != 'ASCII-only-fixtures'):
        raise AssertionError('UNKNOWN: distribution provenance failed')
    return data




# SPEC:029 partial: real outcome DTO and provider *interface*, with a local
# synthetic adapter. No provider transport, external operation or ledger event.
DISTRIBUTION_OUTCOME_PROBE = r'''
declare(strict_types=1);
if (!function_exists('mb_strlen')) {
    // Only ASCII fixture strings; not evidence for production mbstring.
    function mb_strlen(string $value): int { return strlen($value); }
}
require_once 'symfony/src/Distribution/DistributionCommand.php';
require_once 'symfony/src/Distribution/DistributionOutcome.php';
require_once 'symfony/src/Distribution/DistributionProvider.php';
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionOutcome;
use GrindFlow\Distribution\DistributionProvider;
final class SyntheticNoNetworkProvider implements DistributionProvider {
    public int $calls = 0;
    public function publish(DistributionCommand $command): DistributionOutcome {
        ++$this->calls;
        return new DistributionOutcome('synthetic-' . $command->idempotencyKey);
    }
}
$assert = static function (bool $ok, string $name): void {
    if (!$ok) { throw new \RuntimeException('OFFLINE_OUTCOME_ASSERT:' . $name); }
};
$mode = $argv[1] ?? '';
if ($mode === 'positive') {
    $assert((new DistributionOutcome('synthetic-id'))->externalPublicationId === 'synthetic-id', 'id-preserved');
    $assert(strlen((new DistributionOutcome('x'))->externalPublicationId) === 1, 'lower-bound');
    $assert(strlen((new DistributionOutcome(str_repeat('y', 191)))->externalPublicationId) === 191, 'upper-bound');
    $fake = new SyntheticNoNetworkProvider();
    $cmd = new DistributionCommand(
        organizationId: '01234567-89ab-4cde-8fab-0123456789ab',
        idempotencyKey: 'synthetic-key', message: 'Synthetic payload'
    );
    $outcome = $fake->publish($cmd);
    $assert($fake->calls === 1 && $outcome->externalPublicationId === 'synthetic-synthetic-key', 'fake-protocol');
    $checks = 4;
} elseif ($mode === 'negative') {
    $bad = ['', ' ', "\t\n", str_repeat('z', 192), str_repeat(' ', 191), "  \t  "];
    foreach ($bad as $case => $identifier) {
        try { new DistributionOutcome($identifier); }
        catch (\InvalidArgumentException $e) { continue; }
        throw new \RuntimeException('OFFLINE_OUTCOME_ACCEPTED_UNSAFE_CASE:' . $case);
    }
    $rejectedNull = false;
    try { new DistributionOutcome(null); }
    catch (\TypeError $e) { $rejectedNull = true; }
    if (!$rejectedNull) { throw new \RuntimeException('OFFLINE_OUTCOME_ACCEPTED_NULL'); }
    $checks = count($bad) + 1;
} elseif ($mode === 'immutability') {
    // SPEC:029 partial: immutable input/result values, not a persisted event.
    $command = new DistributionCommand(
        organizationId: '01234567-89ab-4cde-8fab-0123456789ab',
        idempotencyKey: 'immutable-synthetic', message: 'Original payload'
    );
    $outcome = new DistributionOutcome('original-synthetic-id');
    $guards = [
        [static function () use ($command): void { $command->organizationId = 'other-tenant'; },
            static fn (): string => $command->organizationId,
            '01234567-89ab-4cde-8fab-0123456789ab', 'organization-id'],
        [static function () use ($command): void { $command->idempotencyKey = 'changed'; },
            static fn (): string => $command->idempotencyKey,
            'immutable-synthetic', 'idempotency-key'],
        [static function () use ($command): void { $command->message = 'changed'; },
            static fn (): string => $command->message,
            'Original payload', 'message'],
        [static function () use ($outcome): void { $outcome->externalPublicationId = 'forged'; },
            static fn (): string => $outcome->externalPublicationId,
            'original-synthetic-id', 'external-publication-id'],
    ];
    foreach ($guards as [$attempt, $read, $expected, $label]) {
        $blocked = false;
        try { $attempt(); } catch (\Error $e) { $blocked = true; }
        $assert($blocked && $read() === $expected, 'readonly-' . $label);
    }
    $checks = count($guards);
} else { throw new \InvalidArgumentException('UNKNOWN_OUTCOME_PROBE_MODE'); }
echo json_encode(['checks'=>$checks, 'source'=>'real-PHP',
    'network'=>'disabled', 'database'=>'disabled', 'provider'=>'synthetic-adapter',
    'external_evidence'=>false, 'production'=>'UNKNOWN'], JSON_THROW_ON_ERROR);
'''


def run_distribution_outcome_php(mode: str) -> dict:
    if mode not in ('positive', 'negative', 'immutability'):
        raise ValueError('Unknown outcome mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI unavailable')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', DISTRIBUTION_OUTCOME_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError('UNKNOWN: outcome real PHP failed: '
                             + (result.stderr + result.stdout)[-1000:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid outcome result') from exc
    if (data.get('source') != 'real-PHP'
            or data.get('network') != 'disabled'
            or data.get('database') != 'disabled'
            or data.get('provider') != 'synthetic-adapter'
            or data.get('external_evidence') is not False
            or data.get('production') != 'UNKNOWN'):
        raise AssertionError('UNKNOWN: outcome provenance not verified')
    return data


# SPEC:018/035 (PARTIAL): the genuine read-only scheduling preview and
# studio calendar aggregation, but NOT persistence, pagination or deployment.
SCHEDULING_BOUNDARY_PROBE = r'''
declare(strict_types=1);
require_once 'symfony/src/Scheduling/Preview/BulkSchedulePreview.php';
require_once 'symfony/src/Scheduling/Studio/StudioCalendarView.php';
use GrindFlow\Scheduling\Preview\BulkSchedulePreview as Preview;
use GrindFlow\Scheduling\Studio\StudioCalendarView as Calendar;
$assert = static function (bool $ok, string $label): void {
    if (!$ok) { throw new \RuntimeException('OFFLINE_SCHEDULE_ASSERT:' . $label); }
};
$mode = $argv[1] ?? '';
$existing = [['id'=>'slot_a', 'tenant_id'=>'tenanta', 'starts_at_utc'=>'2026-11-03T10:00:00Z']];
$operations = [['kind'=>'create', 'id'=>'slot_b', 'tenant_id'=>'tenanta', 'starts_at_utc'=>'2026-11-03T11:00:00Z']];
$slots = [
    ['tenant_id'=>'tenantA','creator_id'=>'creatorBeta','slot_id'=>'media_b','day'=>'2026-11-02','state'=>'approved'],
    ['tenant_id'=>'tenantA','creator_id'=>'creatorAlpha','slot_id'=>'media_a','day'=>'2026-11-01','state'=>'preparing'],
    ['tenant_id'=>'tenantA','creator_id'=>'creatorAlpha','slot_id'=>'media_c','day'=>'2026-11-02','state'=>'awaiting_approval'],
];
if ($mode === 'preview') {
    $ready = Preview::build('tenanta', $existing, $operations);
    $assert($ready['status'] === 'ready' && $ready['execution'] === false, 'preview_only');
    $assert($ready['creates'] === [['id'=>'slot_b','starts_at_utc'=>'2026-11-03T11:00:00Z']], 'created_slot');
    $assert($ready['moves'] === [] && $ready['conflicts'] === [], 'no_moves_or_collisions');
    $assert(Preview::matches('tenanta', $existing, $operations, $ready), 'exact_digest');
    $altered = $operations;
    $altered[0]['starts_at_utc'] = '2026-11-03T12:00:00Z';
    $assert(!Preview::matches('tenanta', $existing, $altered, $ready), 'digest_change_denied');
    $colliding = $operations;
    $colliding[0]['starts_at_utc'] = $existing[0]['starts_at_utc'];
    $conflict = Preview::build('tenanta', $existing, $colliding);
    $assert($conflict['status'] === 'conflict' && count($conflict['conflicts']) === 2, 'collision_detected');
    $assert(!Preview::matches('tenanta', $existing, $colliding, $conflict), 'conflict_not_authorization');
    $assert(strlen($ready['input_sha256']) === 64, 'sha256_fingerprint');
    $checks = 8;
} elseif ($mode === 'preview_negative') {
    $reject = static function (array $current, array $planned) use ($assert): void {
        try { Preview::build('tenanta', $current, $planned); }
        catch (\InvalidArgumentException) { return; }
        throw new \RuntimeException('OFFLINE_SCHEDULE_ACCEPTED_UNSAFE');
    };
    $otherTenant = $existing;
    $otherTenant[0]['tenant_id'] = 'tenantForeign';
    $reject($otherTenant, $operations);
    $foreignOperation = $operations;
    $foreignOperation[0]['tenant_id'] = 'tenantForeign';
    $reject($existing, $foreignOperation);
    $badTime = $operations;
    $badTime[0]['starts_at_utc'] = '2026-02-30T10:00:00Z';
    $reject($existing, $badTime);
    $reject([$existing[0],$existing[0]], $operations);
    $reject($existing, [$operations[0],$operations[0]]);
    $badKind=$operations;
    $badKind[0]['kind']='delete';
    $reject($existing,$badKind);
    $badTarget=$operations;
    $badTarget[0]['kind']='move';
    $reject($existing,$badTarget);
    $extra=$operations;
    $extra[0]['secret']='not_allowed';
    $reject($existing,$extra);
    $assert(!Preview::matches('tenantForeign',$existing,$operations,[]), 'unknown_tenant_not_authorized');
    $checks=9;
} elseif ($mode === 'calendar') {
    $view = new Calendar();
    $calendar = $view->aggregate('tenantA',['creatorAlpha','creatorBeta'],$slots);
    $assert($calendar['status']==='ok' && $calendar['total']===3, 'valid_authorized_calendar');
    $assert($calendar['execution_performed']===false, 'read_only');
    $assert(array_column($calendar['slots'],'slot_id')===['media_a','media_c','media_b'], 'stable_order');
    $assert($calendar['by_creator']['creatorAlpha']===['preparing'=>1,'awaiting_approval'=>1,'approved'=>0], 'author_count');
    $assert($calendar['by_creator']['creatorBeta']['approved']===1, 'other_creator_count');
    $assert($view->aggregate('tenantA',['creatorBeta','creatorAlpha'],array_reverse($slots))===$calendar, 'input_order_independent');
    $checks=6;
} elseif ($mode === 'calendar_negative') {
    $view = new Calendar();
    $reject=static function (string $tenant,array $allowed,array $items) use ($view): void {
        $out=$view->aggregate($tenant,$allowed,$items);
        if ($out!==['status'=>'blocked','reason'=>'invalid_or_unauthorized_evidence','execution_performed'=>false]) {
            throw new \RuntimeException('OFFLINE_CALENDAR_ACCEPTED_UNSAFE');
        }
    };
    $wrongTenant=$slots;
    $wrongTenant[0]['tenant_id']='tenantForeign';
    $reject('tenantA',['creatorAlpha','creatorBeta'],$wrongTenant);
    $wrongCreator=$slots;
    $wrongCreator[0]['creator_id']='creatorHidden';
    $reject('tenantA',['creatorAlpha','creatorBeta'],$wrongCreator);
    $badDay=$slots;
    $badDay[0]['day']='2026-02-30';
    $reject('tenantA',['creatorAlpha','creatorBeta'],$badDay);
    $badState=$slots;
    $badState[0]['state']='published';
    $reject('tenantA',['creatorAlpha','creatorBeta'],$badState);
    $extra=$slots;
    $extra[0]['customer_email']='not_allowed';
    $reject('tenantA',['creatorAlpha','creatorBeta'],$extra);
    $reject('tenantA',['creatorAlpha','creatorBeta'],[$slots[0],$slots[0]]);
    $reject('tenantA',['creatorAlpha','creatorAlpha'],$slots);
    $reject('tenantA',[],$slots);
    $reject('tenantA',['123'],$slots);
    $reject('tenantA',['creatorAlpha','creatorBeta'],array_fill(0,201,$slots[0]));
    $checks=10;
} else {
    throw new \InvalidArgumentException('UNKNOWN_SCHEDULING_PROBE_MODE');
}
echo json_encode(['checks'=>$checks,'source'=>'real-PHP','network'=>'disabled',
    'database'=>'disabled','execution'=>false,'production'=>'UNKNOWN'], JSON_THROW_ON_ERROR);
'''


def run_scheduling_php(mode: str) -> dict:
    """Probe authentic pure PHP source; missing code or invalid output stays UNKNOWN."""
    if mode not in ('preview', 'preview_negative', 'calendar', 'calendar_negative'):
        raise ValueError('Unknown scheduling mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI is absent')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', SCHEDULING_BOUNDARY_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False)
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError('UNKNOWN: scheduling source probe failed: '
                             + (result.stderr + result.stdout)[-1200:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid scheduling source response') from exc
    if (data.get('source') != 'real-PHP' or data.get('network') != 'disabled'
            or data.get('database') != 'disabled' or data.get('execution') is not False
            or data.get('production') != 'UNKNOWN'):
        raise AssertionError('UNKNOWN: scheduling source provenance invalid')
    return data




# Subcontract of SPEC:028. This imports the real FinanceLedgerManager, while
# replacing only its Laravel/Carbon collaborators with synthetic in-memory fakes.
# It is NOT proof of a real append-only ledger, database isolation or reporting.
FINANCE_LEDGER_PROBE = r'''
namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException {
        public static function withMessages(array $messages): self {
            return new self((string) array_key_first($messages));
        }
    }
}
namespace Illuminate\Auth\Access {
    class AuthorizationException extends \RuntimeException {}
}
namespace Carbon {
    class CarbonImmutable {
        public function __construct(private string $date) {}
        public function toDateString(): string { return $this->date; }
    }
}
namespace App\Support\Tenancy {
    class TenantContext {
        public function __construct(private ?string $tenant) {}
        public function organizationId(): ?string { return $this->tenant; }
    }
}
namespace App\Models {
    class User {
        public function __construct(private bool $allowed) {}
        public function getKey(): string { return 'actorA'; }
        public function canManageFinanceOrganization(string $tenant): bool {
            return $this->allowed && $tenant === 'tenantA';
        }
    }
    class RevenueAllocation {
        public static int $writes = 0;
        public function __construct(public array $fields) {}
        public static function query(): RevenueQuery { return new RevenueQuery(); }
    }
    class RevenueQuery {
        public function create(array $fields): RevenueAllocation {
            ++RevenueAllocation::$writes;
            return new RevenueAllocation($fields);
        }
    }
    class Membership {
        public static function query(): MembershipQuery { return new MembershipQuery(); }
    }
    class MembershipQuery {
        private array $filters = [];
        public function where(string $name, string $value): self {
            $this->filters[$name] = $value;
            return $this;
        }
        public function exists(): bool {
            return $this->filters === [
                'organization_id' => 'tenantA', 'user_id' => 'beneficiaryA',
            ];
        }
    }
}
namespace {
    require_once 'app/Services/Finance/FinanceLedgerManager.php';
    $mode = $argv[1] ?? '';
    $accepted = ['valid','valid_beneficiary'];
    $denied = ['no_tenant','no_permission','zero_amount',
        'empty_source','invalid_currency','foreign_beneficiary'];
    if (!in_array($mode, [...$accepted, ...$denied], true)) {
        throw new \InvalidArgumentException('UNKNOWN_FINANCE_MODE');
    }
    $tenant = $mode === 'no_tenant' ? null : 'tenantA';
    $allowed = $mode !== 'no_permission';
    $source = $mode === 'empty_source' ? '  ' : ' Campaign A ';
    $amount = $mode === 'zero_amount' ? 0 : 145;
    $currency = $mode === 'invalid_currency' ? 'US1' : ' usd ';
    $beneficiary = match ($mode) {
        'valid_beneficiary' => 'beneficiaryA',
        'foreign_beneficiary' => 'foreign-user',
        default => null,
    };
    $manager = new \App\Services\Finance\FinanceLedgerManager(
        new \App\Support\Tenancy\TenantContext($tenant));
    $exception = null;
    $result = null;
    try {
        $result = $manager->create(
            new \App\Models\User($allowed), $source, $amount, $currency,
            new \Carbon\CarbonImmutable('2031-03-01'), $beneficiary, ' test ',
        );
    } catch (\Illuminate\Validation\ValidationException|
        \Illuminate\Auth\Access\AuthorizationException $e) {
        $exception = $e;
    }
    if (in_array($mode, $accepted, true)) {
        if ($exception !== null || !($result instanceof \App\Models\RevenueAllocation)
            || \App\Models\RevenueAllocation::$writes !== 1) {
            throw new \RuntimeException('FINANCE_ASSERT:valid_writes');
        }
        $fields = $result->fields;
        if ($fields['source_label'] !== 'Campaign A'
            || $fields['amount_minor'] !== 145
            || $fields['currency'] !== 'USD'
            || $fields['occurred_on'] !== '2031-03-01'
            || $fields['created_by_user_id'] !== 'actorA'
            || $fields['note'] !== 'test'
            || $fields['beneficiary_user_id'] !== ($mode === 'valid_beneficiary'
                ? 'beneficiaryA' : null)) {
            throw new \RuntimeException('FINANCE_ASSERT:normalized_payload');
        }
    } else {
        $authorization = in_array($mode, ['no_tenant','no_permission'], true);
        if ($exception === null
            || ($authorization && !($exception instanceof
                \Illuminate\Auth\Access\AuthorizationException))
            || (!$authorization && !($exception instanceof
                \Illuminate\Validation\ValidationException))
            || \App\Models\RevenueAllocation::$writes !== 0) {
            throw new \RuntimeException('FINANCE_ASSERT:fail_closed:' . $mode);
        }
    }
    echo json_encode(['mode'=>$mode,'source'=>'real-PHP','database'=>'mock',
        'network'=>'disabled','ledger_persisted'=>false,'production'=>'UNKNOWN'],
        JSON_THROW_ON_ERROR);
}
'''


def run_finance_php(mode: str) -> dict:
    """Call real PHP FinanceLedgerManager with synthetic Laravel collaborators."""
    if mode not in {'valid','valid_beneficiary','no_tenant',
            'no_permission','zero_amount','empty_source','invalid_currency',
            'foreign_beneficiary'}:
        raise ValueError('Invalid finance probe mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI is unavailable')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', FINANCE_LEDGER_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError('UNKNOWN: finance probe failed: '
                             + (result.stderr + result.stdout)[-1600:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid finance probe response') from exc
    if (data.get('mode') != mode or data.get('source') != 'real-PHP'
            or data.get('database') != 'mock'
            or data.get('network') != 'disabled'
            or data.get('ledger_persisted') is not False
            or data.get('production') != 'UNKNOWN'):
        raise AssertionError('UNKNOWN: finance provenance invalid')
    return data



# SPEC:028 (PARTIAL): the actual FinanceLedgerManager::reverse PHP method,
# with Laravel/DB/model collaborators replaced by deterministic in-memory fakes.
# It checks denial and an append-shaped reversal payload, NOT genuine SQL scopes,
# concurrency, transactions, persistence, audit logs or real ledger guarantees.
FINANCE_REVERSAL_PROBE = r'''
namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException {
        public static function withMessages(array $messages): self {
            return new self((string) array_key_first($messages));
        }
    }
}
namespace Illuminate\Auth\Access {
    class AuthorizationException extends \RuntimeException {}
}
namespace Carbon {
    class CarbonImmutable {
        public static function now(string $timezone): self {
            if ($timezone !== 'UTC') { throw new \RuntimeException('FINANCE_REVERSAL_NOT_UTC'); }
            return new self();
        }
        public function toDateString(): string { return '2031-04-03'; }
    }
}
namespace App\Support\Tenancy {
    class TenantContext {
        public function __construct(private ?string $tenant) {}
        public function organizationId(): ?string { return $this->tenant; }
    }
}
namespace Illuminate\Support\Facades {
    class DB {
        public static int $transactions = 0;
        public static function transaction(callable $operation): mixed {
            ++self::$transactions;
            return $operation();
        }
    }
}
namespace App\Models {
    class User {
        public function __construct(private bool $authorized) {}
        public function getKey(): string { return 'actorA'; }
        public function canManageFinanceOrganization(string $tenant): bool {
            return $this->authorized && $tenant === 'tenantA';
        }
    }
    class RevenueAllocation {
        public static int $writes = 0;
        public static bool $isReversal = false;
        public static bool $alreadyReversed = false;
        public function __construct(private array $fields) {}
        public function __get(string $field): mixed { return $this->fields[$field] ?? null; }
        public function getKey(): string { return (string) ($this->fields['id'] ?? 'new_rev_1'); }
        public function toFields(): array { return $this->fields; }
        public static function query(): RevenueQuery { return new RevenueQuery(); }
    }
    class RevenueQuery {
        public static int $locks = 0;
        private bool $checkedPreviousReversal = false;
        private bool $locked = false;
        public function lockForUpdate(): self {
            $this->locked = true;
            ++self::$locks;
            return $this;
        }
        public function findOrFail(string $id): RevenueAllocation {
            if (!$this->locked) {
                throw new \RuntimeException('FINANCE_REVERSAL_ASSERT:missing_row_lock');
            }
            if ($id !== 'alloc_1') { throw new \RuntimeException('FAKE_NOT_FOUND'); }
            return new RevenueAllocation([
                'id'=>'alloc_1',
                'reversal_of_id' => RevenueAllocation::$isReversal ? 'older_1' : null,
                'beneficiary_user_id'=>'beneficiaryA',
                'source_label'=>'Campaign A',
                'amount_minor'=>145,
                'currency'=>'USD',
            ]);
        }
        public function where(string $field, string $value): self {
            if ($field !== 'reversal_of_id' || $value !== 'alloc_1') {
                throw new \RuntimeException('FINANCE_REVERSAL_INVALID_QUERY');
            }
            $this->checkedPreviousReversal = true;
            return $this;
        }
        public function exists(): bool {
            if (!$this->checkedPreviousReversal) {
                throw new \RuntimeException('FINANCE_REVERSAL_UNSCOPED_QUERY');
            }
            return RevenueAllocation::$alreadyReversed;
        }
        public function create(array $fields): RevenueAllocation {
            ++RevenueAllocation::$writes;
            return new RevenueAllocation($fields);
        }
    }
}
namespace {
    require_once 'app/Services/Finance/FinanceLedgerManager.php';
    $mode = $argv[1] ?? '';
    $modes = ['valid','no_tenant','no_permission','empty_reason',
        'reverse_a_reversal','already_reversed','missing_original'];
    if (!in_array($mode,$modes,true)) {
        throw new \InvalidArgumentException('UNKNOWN_REVERSAL_MODE');
    }
    \App\Models\RevenueAllocation::$isReversal = $mode === 'reverse_a_reversal';
    \App\Models\RevenueAllocation::$alreadyReversed = $mode === 'already_reversed';
    $tenant = $mode === 'no_tenant' ? null : 'tenantA';
    $manager = new \App\Services\Finance\FinanceLedgerManager(
        new \App\Support\Tenancy\TenantContext($tenant));
    $actor = new \App\Models\User($mode !== 'no_permission');
    $sourceId = $mode === 'missing_original' ? 'missing_1' : 'alloc_1';
    $reason = $mode === 'empty_reason' ? '  ' : ' Correction ';
    $failed = null;
    $entry = null;
    try {
        $entry = $manager->reverse($actor,$sourceId,$reason);
    } catch (\Illuminate\Validation\ValidationException|
             \Illuminate\Auth\Access\AuthorizationException|
             \RuntimeException $exc) {
        $failed = $exc;
    }
    if ($mode === 'valid') {
        if ($failed !== null || !($entry instanceof \App\Models\RevenueAllocation)
            || \App\Models\RevenueAllocation::$writes !== 1
            || \Illuminate\Support\Facades\DB::$transactions !== 1
            || \App\Models\RevenueQuery::$locks !== 1) {
            throw new \RuntimeException('FINANCE_REVERSAL_ASSERT:valid_write');
        }
        $fields = $entry->toFields();
        if ($fields !== [
            'created_by_user_id'=>'actorA',
            'beneficiary_user_id'=>'beneficiaryA',
            'reversal_of_id'=>'alloc_1',
            'source_label'=>'Campaign A',
            'amount_minor'=>145,
            'currency'=>'USD',
            'occurred_on'=>'2031-04-03',
            'note'=>'Correction',
        ]) {
            throw new \RuntimeException('FINANCE_REVERSAL_ASSERT:append_shape');
        }
    } else {
        $expectAuth = in_array($mode,['no_tenant','no_permission'],true);
        $expectValidation = in_array($mode,
            ['empty_reason','reverse_a_reversal','already_reversed'],true);
        if ($failed === null
            || ($expectAuth && !($failed instanceof
                \Illuminate\Auth\Access\AuthorizationException))
            || ($expectValidation && !($failed instanceof
                \Illuminate\Validation\ValidationException))
            || \App\Models\RevenueAllocation::$writes !== 0
            || \Illuminate\Support\Facades\DB::$transactions !==
                ($expectAuth || $mode === 'empty_reason' ? 0 : 1)) {
            throw new \RuntimeException('FINANCE_REVERSAL_ASSERT:closed:' . $mode);
        }
    }
    echo json_encode(['mode'=>$mode,'source'=>'real-PHP','database'=>'mock',
        'network'=>'disabled','ledger_persisted'=>false,'production'=>'UNKNOWN'],
        JSON_THROW_ON_ERROR);
}
'''


def run_finance_reversal_php(mode: str) -> dict:
    """Validate a real PHP method against fake DB collaborators, not a real DB."""
    if mode not in {'valid','no_tenant','no_permission','empty_reason',
                    'reverse_a_reversal','already_reversed','missing_original'}:
        raise ValueError('Invalid finance reversal mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI is unavailable')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', FINANCE_REVERSAL_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError('UNKNOWN: finance reversal probe failed: '
                             + (result.stderr + result.stdout)[-1500:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid finance reversal response') from exc
    if (data.get('mode') != mode or data.get('source') != 'real-PHP'
            or data.get('database') != 'mock'
            or data.get('network') != 'disabled'
            or data.get('ledger_persisted') is not False
            or data.get('production') != 'UNKNOWN'):
        raise AssertionError('UNKNOWN: unverified finance reversal provenance')
    return data

# SPEC:036 (PARTIAL): execute the genuine FinanceReconciliationReport PHP
# against an in-memory synthetic Eloquent-shaped builder/collection.
# The query builder below records filter/SQL expressions and emulates grouping;
# it does not prove SQL execution, tenant scopes or DB pagination.
FINANCE_RECONCILIATION_PROBE = r'''
namespace Illuminate\Support {
    class Collection {
        public function __construct(private array $records) {}
        public function map(callable $mapper): self {
            return new self(array_map($mapper, $this->records));
        }
        public function all(): array { return $this->records; }
    }
}
namespace App\Models {
    class RevenueAllocation {
        public function __construct(public array $attributes) {}
        public function getAttribute(string $name): mixed {
            return $this->attributes[$name] ?? null;
        }
        public function __get(string $name): mixed {
            return $this->getAttribute($name);
        }
        public static function query(): \Illuminate\Database\Eloquent\Builder {
            // Only synthetic data; independently constructed per query.
            return new \Illuminate\Database\Eloquent\Builder([
                ['currency'=>'USD','beneficiary_user_id'=>'alice','amount_minor'=>100,'reversal_of_id'=>null,'occurred_on'=>'2031-04-01'],
                ['currency'=>'USD','beneficiary_user_id'=>'alice','amount_minor'=>40,'reversal_of_id'=>'allocA','occurred_on'=>'2031-04-03'],
                ['currency'=>'USD','beneficiary_user_id'=>'bob','amount_minor'=>20,'reversal_of_id'=>null,'occurred_on'=>'2031-04-02'],
                ['currency'=>'EUR','beneficiary_user_id'=>null,'amount_minor'=>50,'reversal_of_id'=>null,'occurred_on'=>'2031-04-04'],
                ['currency'=>'USD','beneficiary_user_id'=>null,'amount_minor'=>25,'reversal_of_id'=>null,'occurred_on'=>'2031-04-04'],
            ]);
        }
    }
}
namespace Illuminate\Database\Eloquent {
    class Builder {
        public array $clauses = [];
        public array $groups = [];
        public array $orders = [];
        public string $sql = '';
        public static int $queryCount = 0;
        public static int $getCount = 0;
        public function __construct(public array $events) { ++self::$queryCount; }
        public function when(mixed $value, callable $callback): self {
            if ($value) { $callback($this, $value); }
            return $this;
        }
        public function where(string $field, string $value): self {
            $this->clauses[] = ['eq',$field,$value]; return $this;
        }
        public function whereNull(string $field): self {
            $this->clauses[] = ['null',$field]; return $this;
        }
        public function whereDate(string $field, string $operator, string $date): self {
            $this->clauses[] = ['date',$field,$operator,$date]; return $this;
        }
        public function selectRaw(string $sql): self { $this->sql = $sql; return $this; }
        public function groupBy(string ...$fields): self { array_push($this->groups,...$fields); return $this; }
        public function orderBy(string $field): self { $this->orders[]=$field; return $this; }
        public function get(): \Illuminate\Support\Collection {
            ++self::$getCount;
            $expects = ['SUM(CASE WHEN reversal_of_id IS NULL THEN amount_minor ELSE 0 END) AS allocated_minor',
                        'SUM(CASE WHEN reversal_of_id IS NOT NULL THEN amount_minor ELSE 0 END) AS reversed_minor'];
            foreach ($expects as $expression) {
                if (!str_contains($this->sql, $expression)) {
                    throw new \RuntimeException('REAL_REPORT_BAD_AGGREGATION_SQL');
                }
            }
            $isBeneficiary = $this->groups === ['beneficiary_user_id','currency'];
            if (!$isBeneficiary && $this->groups !== ['currency']) {
                throw new \RuntimeException('REAL_REPORT_BAD_GROUPING');
            }
            $expectedOrders = $isBeneficiary ? ['currency','beneficiary_user_id'] : ['currency'];
            if ($this->orders !== $expectedOrders ||
                ($isBeneficiary && !str_contains($this->sql,'COUNT(*) AS event_count'))) {
                throw new \RuntimeException('REAL_REPORT_BAD_ORDER_OR_COUNT');
            }
            $filtered = array_filter($this->events, function(array $event): bool {
                foreach ($this->clauses as $clause) {
                    [$kind,$field] = $clause;
                    if ($kind==='eq' && $event[$field] !== $clause[2]) { return false; }
                    if ($kind==='null' && $event[$field] !== null) { return false; }
                    if ($kind==='date' && ($clause[2] === '>=' ?
                        $event[$field] < $clause[3] : $event[$field] > $clause[3])) { return false; }
                }
                return true;
            });
            $grouped=[];
            foreach ($filtered as $event) {
                $beneficiary = $isBeneficiary ? $event['beneficiary_user_id'] : null;
                $key = $event['currency'].':'.($beneficiary??'null');
                if (!isset($grouped[$key])) {
                    $grouped[$key]=['currency'=>$event['currency'], 'beneficiary_user_id'=>$beneficiary,
                        'event_count'=>0,'allocated_minor'=>0,'reversed_minor'=>0];
                }
                ++$grouped[$key]['event_count'];
                $bucket=$event['reversal_of_id']===null?'allocated_minor':'reversed_minor';
                $grouped[$key][$bucket]+=$event['amount_minor'];
            }
            usort($grouped, static fn ($a,$b)=>[$a['currency'],$a['beneficiary_user_id']??'']
                <=> [$b['currency'],$b['beneficiary_user_id']??'']);
            return new \Illuminate\Support\Collection(array_map(
                static fn($attrs) => new \App\Models\RevenueAllocation($attrs), $grouped));
        }
    }
}
namespace {
    require_once 'app/Services/Finance/FinanceReconciliationReport.php';
    use App\Services\Finance\FinanceReconciliationReport as Report;
    use Illuminate\Database\Eloquent\Builder;
    $assert = static function(bool $ok,string $label):void {
        if (!$ok) { throw new \RuntimeException('REAL_RECONCILIATION_ASSERT:'.$label); }
    };
    $mode = $argv[1] ?? '';
    $report = new Report();
    $before = Builder::$getCount;
    if ($mode==='filters') {
        $query=$report->filtered(['currency'=>'USD','beneficiary'=>'alice',
            'from'=>'2031-04-02','to'=>'2031-04-03']);
        $assert($query->clauses === [
            ['eq','currency','USD'],['eq','beneficiary_user_id','alice'],
            ['date','occurred_on','>=','2031-04-02'],['date','occurred_on','<=','2031-04-03']],
            'exact_filters');
        $unassigned=$report->filtered(['beneficiary'=>'unassigned']);
        $assert($unassigned->clauses === [['null','beneficiary_user_id']], 'unassigned_is_null');
        $none=$report->filtered([]);
        $assert($none->clauses===[], 'no_unrequested_filter');
        $assert(Builder::$getCount===$before,'filtered_is_lazy');
        $checks=4;
    } elseif ($mode==='totals') {
        $byCurrency=$report->currencySummaries([])->all();
        $assert($byCurrency === [
            ['currency'=>'EUR','allocated_minor'=>50,'reversed_minor'=>0,'net_minor'=>50],
            ['currency'=>'USD','allocated_minor'=>145,'reversed_minor'=>40,'net_minor'=>105]],
            'currency_net_and_dates');
        $byBeneficiary=$report->beneficiarySummaries([])->all();
        $assert($byBeneficiary === [
            ['beneficiary_user_id'=>null,'currency'=>'EUR','events'=>1,'allocated_minor'=>50,'reversed_minor'=>0,'net_minor'=>50],
            ['beneficiary_user_id'=>null,'currency'=>'USD','events'=>1,'allocated_minor'=>25,'reversed_minor'=>0,'net_minor'=>25],
            ['beneficiary_user_id'=>'alice','currency'=>'USD','events'=>2,'allocated_minor'=>100,'reversed_minor'=>40,'net_minor'=>60],
            ['beneficiary_user_id'=>'bob','currency'=>'USD','events'=>1,'allocated_minor'=>20,'reversed_minor'=>0,'net_minor'=>20]],
            'all_beneficiary_groups_and_reversals');
        $assert(Builder::$getCount===$before+2, 'two_aggregates_only');
        $checks=3;
    } elseif ($mode==='dates') {
        $daily=$report->currencySummaries(['from'=>'2031-04-03','to'=>'2031-04-03'])->all();
        $assert($daily === [['currency'=>'USD','allocated_minor'=>0,'reversed_minor'=>40,'net_minor'=>-40]],
            'reversal_own_event_date_not_original_date');
        $none=$report->beneficiarySummaries(['currency'=>'EUR','beneficiary'=>'alice'])->all();
        $assert($none===[],'missing_group_not_fabricated');
        $unassigned=$report->beneficiarySummaries(['beneficiary'=>'unassigned'])->all();
        $assert(count($unassigned)===2 && $unassigned[0]['beneficiary_user_id']===null,
            'unassigned_groups_read_only');
        $assert(Builder::$getCount===$before+3,'no_hidden_data_writes');
        $checks=4;
    } else {
        throw new \InvalidArgumentException('UNKNOWN_REPORT_MODE');
    }
    echo json_encode(['mode'=>$mode,'checks'=>$checks,'source'=>'real-PHP',
        'database'=>'synthetic-builder','network'=>'disabled',
        'tenant_scope'=>'UNKNOWN','production'=>'UNKNOWN'], JSON_THROW_ON_ERROR);
}
'''


def run_finance_reconciliation_php(mode: str) -> dict:
    """Run the real report with synthetic Builder: no real SQL, HTTP or DB."""
    if mode not in {'filters', 'totals', 'dates'}:
        raise ValueError('Unknown synthetic report mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI unavailable for reconciliation')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', FINANCE_RECONCILIATION_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError('UNKNOWN: genuine PHP reconciliation failed: '
                             + (result.stderr + result.stdout)[-1600:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: malformed PHP reconciliation output') from exc
    if (data.get('mode') != mode or data.get('source') != 'real-PHP'
            or data.get('database') != 'synthetic-builder'
            or data.get('network') != 'disabled'
            or data.get('tenant_scope') != 'UNKNOWN'
            or data.get('production') != 'UNKNOWN'):
        raise AssertionError('UNKNOWN: reconciliation provenance unresolved')
    return data



# SPEC:032 and SPEC:037 (PARTIAL): exercise the authentic TrackedLinkManager
# methods with disposable synthetic Laravel-shaped dependencies. The fake
# scoped lookup does NOT establish actual Eloquent tenant scoping or SQL.
TRAFFIC_LINK_PROBE = r"""
namespace Illuminate\Auth\Access {
    class AuthorizationException extends \RuntimeException {}
}
namespace App\Support\Tenancy {
    class TenantContext {
        public function __construct(private ?string $id) {}
        public function organizationId(): ?string { return $this->id; }
    }
}
namespace App\Models {
    class User {
        public function __construct(private bool $allowed) {}
        public function canManageTrafficOrganization(string $id): bool {
            return $this->allowed && $id === 'tenantA';
        }
    }
    class TrackedLink {
        public const STATUS_ACTIVE = 'active';
        public const STATUS_DISABLED = 'disabled';
        public static int $writes = 0;
        public static int $locks = 0;
        public static int $queries = 0;
        public string $token = 'public-synthetic-token';
        public string $status = 'active';
        public string $label = 'Original label';
        public string $destination_url = 'https://example.test/old';
        public ?string $channel = 'social';
        public ?string $campaign = 'campaignA';
        public array $assignment = ['scheduleA'];
        public array $clicks = [8,4];
        public static function query(): LinkQuery {
            ++self::$queries;
            return new LinkQuery();
        }
        public function forceFill(array $fields): self {
            foreach ($fields as $key => $value) { $this->$key = $value; }
            return $this;
        }
        public function save(): bool { ++self::$writes; return true; }
    }
    class LinkQuery {
        private bool $locked = false;
        public function lockForUpdate(): self {
            $this->locked = true; ++TrackedLink::$locks;
            return $this;
        }
        public function findOrFail(string $id): TrackedLink {
            if (!$this->locked) { throw new \RuntimeException('UNLOCKED_QUERY'); }
            if ($id !== 'linkA') { throw new \RuntimeException('NOT_FOUND'); }
            return $GLOBALS['fake_link'];
        }
    }
}
namespace Illuminate\Support\Facades {
    class DB {
        public static int $transactions = 0;
        public static function transaction(callable $callback): mixed {
            ++self::$transactions;
            return $callback();
        }
    }
}
namespace {
    // Offline PHP CLI can lack mbstring. These test cases use ASCII strings
    // only; this local fallback is not an assertion about Unicode behavior.
    if (!function_exists('mb_strlen')) {
        function mb_strlen(string $value): int { return strlen($value); }
    }
    require_once 'app/Services/Traffic/TrackedLinkManager.php';
    $mode = $argv[1] ?? '';
    $positive = ['pause','resume','same','details','details_disabled'];
    $negative = ['no_tenant_status','no_permission_status','bad_status',
        'missing_status','no_tenant_details','no_permission_details',
        'bad_label','bad_scheme','bad_url','bad_channel',
        'bad_campaign','missing_details'];
    if (!in_array($mode, array_merge($positive, $negative), true)) {
        throw new \InvalidArgumentException('UNKNOWN_TRAFFIC_MODE');
    }
    $link = new \App\Models\TrackedLink();
    $GLOBALS['fake_link'] = $link;
    if (in_array($mode, ['resume','details_disabled'], true)) {
        $link->status = 'disabled';
    }
    $originalStatus = $link->status;
    $originalToken = $link->token;
    $originalAssignment = $link->assignment;
    $originalClicks = $link->clicks;
    $tenant = str_starts_with($mode,'no_tenant') ? null : 'tenantA';
    $actor = new \App\Models\User(!str_starts_with($mode,'no_permission'));
    $manager = new \App\Services\Traffic\TrackedLinkManager(
        new \App\Support\Tenancy\TenantContext($tenant));
    $isDetails = in_array($mode,['details','details_disabled',
        'no_tenant_details','no_permission_details','bad_label','bad_scheme',
        'bad_url','bad_channel','bad_campaign','missing_details'], true);
    $label = $mode === 'bad_label' ? '   ' : '  Updated label  ';
    $url = $mode === 'bad_scheme' ? 'ftp://example.test/f'
        : ($mode === 'bad_url' ? 'not a URL' : ' https://example.test/new ');
    $channel = $mode === 'bad_channel' ? str_repeat('x',65) : '  email  ';
    $campaign = $mode === 'bad_campaign' ? str_repeat('x',129) : '  March  ';
    $id = str_starts_with($mode,'missing_') ? 'missing' : 'linkA';
    $status = $mode === 'pause' ? 'disabled'
        : ($mode === 'bad_status' ? 'paused' : $originalStatus);
    if ($mode === 'resume') { $status = 'active'; }
    $failure = null;
    try {
        if ($isDetails) {
            $manager->updateDetails($actor,$id,$label,$url,$channel,$campaign);
        } else {
            $manager->setStatus($actor,$id,$status);
        }
    } catch (\Illuminate\Auth\Access\AuthorizationException|
             \InvalidArgumentException|\RuntimeException $exc) {
        $failure = $exc;
    }
    if ($link->token !== $originalToken
        || $link->assignment !== $originalAssignment
        || $link->clicks !== $originalClicks) {
        throw new \RuntimeException('TRAFFIC_ASSERT:token_or_history_modified');
    }
    if (in_array($mode,$positive,true)) {
        $expectWrites = $mode === 'same' ? 0 : 1;
        if ($failure !== null || \App\Models\TrackedLink::$writes !== $expectWrites
            || \App\Models\TrackedLink::$locks !== 1
            || \Illuminate\Support\Facades\DB::$transactions !== 1) {
            throw new \RuntimeException('TRAFFIC_ASSERT:positive:' . $mode);
        }
        if ($isDetails) {
            if ($link->status !== $originalStatus
                || $link->label !== 'Updated label'
                || $link->destination_url !== 'https://example.test/new'
                || $link->channel !== 'email' || $link->campaign !== 'March') {
                throw new \RuntimeException('TRAFFIC_ASSERT:details:' . $mode);
            }
        } elseif ($link->status !== $status) {
            throw new \RuntimeException('TRAFFIC_ASSERT:status:' . $mode);
        }
    } else {
        $shouldAuthorize = str_starts_with($mode,'no_tenant')
            || str_starts_with($mode,'no_permission');
        $shouldValidate = in_array($mode,
            ['bad_status','bad_label','bad_scheme','bad_url',
             'bad_channel','bad_campaign'],true);
        $missing = str_starts_with($mode,'missing_');
        if ($failure === null
            || ($shouldAuthorize && !($failure instanceof
                \Illuminate\Auth\Access\AuthorizationException))
            || ($shouldValidate && !($failure instanceof \InvalidArgumentException))
            || ($missing && $failure->getMessage() !== 'NOT_FOUND')
            || \App\Models\TrackedLink::$writes !== 0
            || \Illuminate\Support\Facades\DB::$transactions !== ($missing?1:0)
            || \App\Models\TrackedLink::$locks !== ($missing?1:0)
            || $link->status !== $originalStatus
            || $link->label !== 'Original label') {
            throw new \RuntimeException('TRAFFIC_ASSERT:fail_closed:' . $mode);
        }
    }
    echo json_encode(['mode'=>$mode,'source'=>'real-PHP',
        'database'=>'synthetic-query','network'=>'disabled',
        'tenant_scope'=>'UNKNOWN','production'=>'UNKNOWN'], JSON_THROW_ON_ERROR);
}
"""


def run_traffic_link_php(mode: str) -> dict:
    """Actual manager, simulated query; no HTTP/real tenant scope or database."""
    modes = {'pause','resume','same','details','details_disabled',
             'no_tenant_status','no_permission_status','bad_status',
             'missing_status','no_tenant_details','no_permission_details',
             'bad_label','bad_scheme','bad_url','bad_channel',
             'bad_campaign','missing_details'}
    if mode not in modes:
        raise ValueError('Unknown traffic test mode')
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI unavailable for tracked links')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', TRAFFIC_LINK_PROBE, mode],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError('UNKNOWN: tracked-link PHP probe failed: '
                             + (result.stderr + result.stdout)[-1300:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: malformed tracked-link response') from exc
    if (data.get('mode') != mode or data.get('source') != 'real-PHP'
            or data.get('database') != 'synthetic-query'
            or data.get('network') != 'disabled'
            or data.get('tenant_scope') != 'UNKNOWN'
            or data.get('production') != 'UNKNOWN'):
        raise AssertionError('UNKNOWN: tracked-link provenance unresolved')
    return data


MEDIA_ELIGIBILITY_PROBE = r'''
declare(strict_types=1);

namespace Illuminate\Database\Eloquent {
    class Builder {
        public array $calls = [];
        public function where($key, $value) {
            $this->calls[] = ['where', $key, $value];
            return $this;
        }
        public function whereNull($key) {
            $this->calls[] = ['whereNull', $key];
            return $this;
        }
    }
}
namespace App\Models {
    class MediaAsset {
        public const STATUS_READY = 'ready';
        public function __construct(
            public string $status,
            public ?string $duplicate_of,
            public mixed $metadata
        ) {}
        public static function query(): \Illuminate\Database\Eloquent\Builder {
            return new \Illuminate\Database\Eloquent\Builder();
        }
        public function getAttribute(string $name): mixed {
            return $name === 'metadata' ? $this->metadata : null;
        }
    }
}
namespace App\Support\Tenancy {
    class TenantContext {}
}
namespace App\Services\Media {
    class MediaAssetProcessor {
        public function currentVersion(): int { return 7; }
    }
}
namespace {
    require_once 'app/Services/Scheduling/ContentScheduler.php';
    $scheduler = new \App\Services\Scheduling\ContentScheduler(
        new \App\Support\Tenancy\TenantContext(),
        new \App\Services\Media\MediaAssetProcessor()
    );
    $ok = ['processing' => ['status' => 'completed', 'version' => 7]];
    $cases = [
        ['ready', null, $ok, true],
        ['pending', null, $ok, false],
        ['duplicate', null, $ok, false],
        ['ready', 'another-id', $ok, false],
        ['ready', null, null, false],
        ['ready', null, 'invalid', false],
        ['ready', null, [], false],
        ['ready', null, ['processing' => null], false],
        ['ready', null, ['processing' => ['status'=>'pending','version'=>7]], false],
        ['ready', null, ['processing' => ['status'=>'completed','version'=>6]], false],
        ['ready', null, ['processing' => ['status'=>'completed']], false],
        ['ready', null, ['processing' => ['version'=>7]], false],
    ];
    foreach ($cases as $index=>$case) {
        [$status,$duplicate,$metadata,$expected] = $case;
        $actual = $scheduler->isAssetEligible(new \App\Models\MediaAsset($status,$duplicate,$metadata));
        if ($actual !== $expected) {
            throw new \RuntimeException('ELIGIBILITY_FAIL:'.$index);
        }
    }
    $query = $scheduler->eligibleAssetsQuery();
    if ($query->calls !== [
        ['where','status','ready'],
        ['whereNull','duplicate_of'],
        ['where','metadata->processing->status','completed'],
        ['where','metadata->processing->version',7],
    ]) {
        throw new \RuntimeException('ELIGIBILITY_QUERY_FAIL');
    }
    echo json_encode(['cases'=>count($cases),'query_filters'=>count($query->calls),
        'source'=>'real-PHP','database'=>'synthetic-query','production'=>'UNKNOWN'],
        JSON_THROW_ON_ERROR);
}
'''


def run_media_eligibility_php() -> dict:
    """Call real ContentScheduler eligibility checks with isolated synthetic models."""
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI unavailable for eligibility')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', MEDIA_ELIGIBILITY_PROBE],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError('UNKNOWN: real PHP eligibility failed: '
                             + (result.stderr + result.stdout)[-1300:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: malformed eligibility output') from exc
    if data != {'cases': 12, 'query_filters': 4, 'source': 'real-PHP',
                'database': 'synthetic-query', 'production': 'UNKNOWN'}:
        raise AssertionError('UNKNOWN: eligibility provenance mismatch')
    return data


CSV_EXPORT_PROBE = r'''
declare(strict_types=1);
namespace App\Http\Controllers {
    class Controller {}
}
namespace {
    require_once 'app/Http/Controllers/Traffic/TrafficController.php';
    require_once 'app/Http/Controllers/Finance/FinanceController.php';
    $traffic = new \ReflectionMethod(
        \App\Http\Controllers\Traffic\TrafficController::class, 'csvCell');
    $finance = new \ReflectionMethod(
        \App\Http\Controllers\Finance\FinanceController::class, 'safeCsvText');
    $cases = [
        ['safe text', 'safe text'],
        ['=2+3', "'=2+3"],
        ['+cmd', "'+cmd"],
        ['-1+2', "'-1+2"],
        ['@now', "'@now"],
        [" \t=SUM(A1:A2)", "' \t=SUM(A1:A2)"],
        ["\n=HYPERLINK(0)", "'\n=HYPERLINK(0)"],
        ["\x00=secret", "'\x00=secret"],
        ['literal =not-first', 'literal =not-first'],
        ['', ''],
        ['8,9', '8,9']
    ];
    foreach ($cases as $idx=>[$input,$expected]) {
        foreach ([$traffic,$finance] as $method) {
            $actual = $method->invoke(null,$input);
            if ($actual !== $expected) {
                throw new \RuntimeException('CSV_FORMULA_FAIL:'.$idx.':'.$method->getName());
            }
        }
    }
    echo json_encode(['cases'=>count($cases),'helpers'=>2,
        'source'=>'real-PHP', 'database'=>'disabled', 'production'=>'UNKNOWN'],
        JSON_THROW_ON_ERROR);
}
'''


def run_csv_export_guards_php() -> dict:
    """Exercise real Traffic/Finance CSV escaping without real database/HTTP."""
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI unavailable for CSV safety')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', CSV_EXPORT_PROBE],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError('UNKNOWN: real PHP CSV protection failed: '
                             + (result.stderr + result.stdout)[-1300:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: malformed CSV safety result') from exc
    if data != {'cases': 11, 'helpers': 2, 'source': 'real-PHP',
                'database': 'disabled', 'production': 'UNKNOWN'}:
        raise AssertionError('UNKNOWN: CSV probe provenance mismatch')
    return data


MANUAL_WORKFLOW_PROBE = r'''
declare(strict_types=1);
namespace Symfony\Bundle\FrameworkBundle\Controller {
    class AbstractController {}
}
namespace {
    require_once 'symfony/src/Http/Controller/ManualHandoffController.php';
    require_once 'symfony/src/Http/Controller/ManualDestinationController.php';
    $handoff = new \GrindFlow\Http\Controller\ManualHandoffController();
    $destinations = new \GrindFlow\Http\Controller\ManualDestinationController();
    $status = new \ReflectionMethod($handoff, 'publicStatus');
    foreach ([
        'prepare' => 'prepared', 'complete' => 'completed',
        'fail' => 'failed', 'unknown' => 'none',
        '' => 'none',
    ] as $action=>$expected) {
        if ($status->invoke($handoff, $action) !== $expected) {
            throw new \RuntimeException('HANDOFF_STATUS_FAIL:'.$action);
        }
    }
    $normalize = new \ReflectionMethod($destinations, 'normalizeLabel');
    $labels = [
        ['  Stage   Ops  ', 'Stage Ops'],
        ['Main stage', 'Main stage'],
        [' x ', null], ['   ', null],
        [str_repeat('a',80), str_repeat('a',80)],
        [str_repeat('a',81), null],
        ["one\n  two", 'one two'],
        ["foo\x00bar", null],
    ];
    foreach ($labels as $i=>[$value,$expected]) {
        $actual = $normalize->invoke($destinations,$value);
        if ($actual !== $expected) {
            throw new \RuntimeException('DESTINATION_LABEL_FAIL:'.$i);
        }
    }
    $toPublic = new \ReflectionMethod($destinations, 'publicDestination');
    $source = [
        'id'=>'id-synthetic', 'label'=>'Stage Ops',
        'created_at'=>'2026-10-30 12:00:00', 'disabled_at'=>null,
        'secret'=>'never-expose', 'provider_id'=>'not-exposed'
    ];
    $a = $toPublic->invoke($destinations,$source);
    if ($a !== [
        'id'=>'id-synthetic','label'=>'Stage Ops','active'=>true,
        'created_at'=>'2026-10-30 12:00:00', 'disabled_at'=>null
    ]) {
        throw new \RuntimeException('DESTINATION_EXPOSURE_FAIL');
    }
    $source['disabled_at']='2026-10-31 12:00:00';
    $b = $toPublic->invoke($destinations,$source);
    if ($b['active'] !== false || $b['disabled_at'] !== '2026-10-31 12:00:00'
        || array_key_exists('secret',$b)) {
        throw new \RuntimeException('DESTINATION_DISABLED_FAIL');
    }
    echo json_encode([
       'handoff_states'=>5,'destination_labels'=>count($labels),
       'public_states'=>2,'source'=>'real-PHP',
       'database'=>'disabled','production'=>'UNKNOWN'
    ],JSON_THROW_ON_ERROR);
}
'''


def run_manual_workflow_php() -> dict:
    """Call real Symfony controller state/label/public-projection helpers."""
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI unavailable for manual handoff')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', MANUAL_WORKFLOW_PROBE],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError('UNKNOWN: real manual controllers failed: '
                             + (result.stderr + result.stdout)[-1300:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: malformed manual controller output') from exc
    if data != {'handoff_states': 5, 'destination_labels': 8,
                'public_states': 2, 'source': 'real-PHP',
                'database': 'disabled', 'production': 'UNKNOWN'}:
        raise AssertionError('UNKNOWN: manual controller provenance mismatch')
    return data


TENANT_SCOPE_PROBE = r'''
declare(strict_types=1);
namespace Illuminate\Database\Eloquent {
    interface Scope { public function apply(Builder $builder, Model $model): void; }
    class Builder {
        public array $conditions = [];
        public function whereRaw(string $raw): void {$this->conditions[]=['raw',$raw];}
        public function where(string $column, string $value): void {
            $this->conditions[]=['where',$column,$value];
        }
    }
    class Model {
        public function qualifyColumn(string $key): string {return 'synthetic_assets.'.$key;}
    }
}
namespace App\Support\Tenancy {
    class TenantContext {
        public ?string $active = null;
        public function organizationId(): ?string {return $this->active;}
    }
}
namespace {
    $GLOBALS['synthetic_context'] = new \App\Support\Tenancy\TenantContext();
    function resolve(string $class): object {
        if ($class !== \App\Support\Tenancy\TenantContext::class) {
            throw new \RuntimeException('UNEXPECTED_RESOLVE');
        }
        return $GLOBALS['synthetic_context'];
    }
    require_once 'app/Models/Scopes/TenantScope.php';
    $scope=new \App\Models\Scopes\TenantScope();
    $model=new \Illuminate\Database\Eloquent\Model();
    $cases=[
        [null,[['raw','1 = 0']]],
        ['org-a',[['where','synthetic_assets.organization_id','org-a']]],
        ['org-b',[['where','synthetic_assets.organization_id','org-b']]],
        [null,[['raw','1 = 0']]],
    ];
    foreach ($cases as $i=>[$tenant,$expected]) {
        $GLOBALS['synthetic_context']->active=$tenant;
        $q=new \Illuminate\Database\Eloquent\Builder();
        $scope->apply($q,$model);
        if ($q->conditions !== $expected) {
            throw new \RuntimeException('TENANT_SCOPE_FAIL:'.$i);
        }
    }
    echo json_encode(['cases'=>count($cases),'source'=>'real-PHP',
        'database'=>'synthetic-query','production'=>'UNKNOWN'],JSON_THROW_ON_ERROR);
}
'''


def run_tenant_scope_php() -> dict:
    """Exercise real Laravel TenantScope against synthetic query objects."""
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI unavailable for tenant scope')
    result = subprocess.run(
        [php, '-d', 'display_errors=stderr', '-r', TENANT_SCOPE_PROBE],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError('UNKNOWN: real TenantScope failed: '
                             + (result.stderr + result.stdout)[-1300:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: malformed TenantScope result') from exc
    if data != {'cases': 4, 'source': 'real-PHP',
                'database': 'synthetic-query', 'production': 'UNKNOWN'}:
        raise AssertionError('UNKNOWN: tenant scope provenance mismatch')
    return data


# SPEC:034 (PARTIAL): execute ContentScheduler::updateTrackedLink itself with
# synthetic scoped row locks. No Eloquent, SQL transaction or HTTP is inferred.
SCHEDULER_LINK_ASSIGNMENT_PROBE = r"""
namespace Illuminate\Auth\Access {
    class AuthorizationException extends \RuntimeException {}
}
namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException {
        public static function withMessages(array $messages): self { return new self('DENIED'); }
    }
}
namespace App\Support\Tenancy {
    class TenantContext {
        public function __construct(private ?string $tenant) {}
        public function organizationId(): ?string { return $this->tenant; }
    }
}
namespace App\Services\Media { class MediaAssetProcessor {} }
namespace App\Models\Scopes { class TenantScope {} }
namespace Illuminate\Support\Facades {
    class Schema {
        public static bool $ready = true;
        public static function hasTable(string $name): bool { return self::$ready; }
    }
    class DB {
        public static int $transactions = 0;
        public static function transaction(callable $fn): mixed {
            ++self::$transactions; return $fn();
        }
    }
}
namespace App\Models {
    class FakeFuture {
        public function __construct(private bool $future) {}
        public function isFuture(): bool { return $this->future; }
    }
    class User {
        public function __construct(private bool $permission = true) {}
        public function canScheduleOrganization(string $tenant): bool {
            return $this->permission && $tenant === 'tenantA';
        }
    }
    class ScheduledPublication {
        public const STATUS_SCHEDULED = 'scheduled';
        public string $organization_id = 'tenantA';
        public string $status = 'scheduled';
        public FakeFuture $scheduled_for_utc;
        public function __construct() { $this->scheduled_for_utc = new FakeFuture(true); }
        public function getKey(): string { return 'scheduleA'; }
        public function delivery(): object {
            return new class {
                public function exists(): bool { return $GLOBALS['delivered']; }
            };
        }
        public static function query(): ScheduleQuery { return new ScheduleQuery(); }
    }
    class ScheduleQuery {
        private bool $tenantScoped = false;
        private bool $locked = false;
        private string $id = '';
        public function withoutGlobalScope(string $scope): self { return $this; }
        public function where(string $col, mixed $val): self {
            if ($col !== 'organization_id' || $val !== 'tenantA') {
                throw new \RuntimeException('UNSCOPED_SCHEDULE');
            }
            $this->tenantScoped = true; return $this;
        }
        public function whereKey(string $key): self { $this->id = $key; return $this; }
        public function lockForUpdate(): self { $this->locked = true; return $this; }
        public function firstOrFail(): ScheduledPublication {
            if (!$this->tenantScoped || !$this->locked) {
                throw new \RuntimeException('MISSING_SCHEDULE_LOCK');
            }
            if ($this->id !== 'scheduleA') { throw new \RuntimeException('NOT_FOUND'); }
            return $GLOBALS['schedule'];
        }
    }
    class TrackedLink {
        public const STATUS_ACTIVE = 'active';
        public string $organization_id = 'tenantA';
        public string $status = 'active';
        public string $token = 'synthetic-public-token';
        public array $clicks = [3, 5];
        public function getKey(): string { return 'linkA'; }
        public static function query(): TrackedLinkQuery { return new TrackedLinkQuery(); }
    }
    class TrackedLinkQuery {
        private string $id = '';
        private bool $locked = false;
        public function whereKey(string $key): self { $this->id = $key; return $this; }
        public function lockForUpdate(): self { $this->locked = true; return $this; }
        public function firstOrFail(): TrackedLink {
            if (!$this->locked) { throw new \RuntimeException('MISSING_LINK_LOCK'); }
            if ($this->id !== 'linkA') { throw new \RuntimeException('NOT_FOUND'); }
            return $GLOBALS['link'];
        }
    }
    class FakeAssignment {
        public string $tracked_link_id = 'linkOld';
        public function delete(): void { ++ScheduledPublicationLink::$writes; }
        public function forceFill(array $changes): self {
            $this->tracked_link_id = $changes['tracked_link_id'];
            return $this;
        }
        public function save(): void { ++ScheduledPublicationLink::$writes; }
    }
    class ScheduledPublicationLink {
        public static int $writes = 0;
        public static function query(): AssignmentQuery { return new AssignmentQuery(); }
    }
    class AssignmentQuery {
        private bool $scoped = false;
        private bool $locked = false;
        public function where(string $col, mixed $val): self {
            $this->scoped = $col === 'scheduled_publication_id' && $val === 'scheduleA';
            return $this;
        }
        public function lockForUpdate(): self { $this->locked = true; return $this; }
        public function first(): ?FakeAssignment {
            if (!$this->scoped || !$this->locked) {
                throw new \RuntimeException('UNLOCKED_ASSIGNMENT');
            }
            return $GLOBALS['assignment'];
        }
        public function create(array $attrs): void {
            if ($attrs !== ['scheduled_publication_id'=>'scheduleA','tracked_link_id'=>'linkA']) {
                throw new \RuntimeException('UNSAFE_CREATION');
            }
            ++ScheduledPublicationLink::$writes;
        }
    }
}
namespace {
    function abort(int $code, string $message): never { throw new \RuntimeException('HTTP_'.$code); }
    require_once 'app/Services/Scheduling/ContentScheduler.php';
    use App\Services\Scheduling\ContentScheduler;
    use App\Services\Media\MediaAssetProcessor;
    use App\Support\Tenancy\TenantContext;
    use App\Models\User;
    use App\Models\ScheduledPublication;
    use App\Models\TrackedLink;
    use App\Models\FakeAssignment;
    use App\Models\ScheduledPublicationLink;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    $assert = static function(bool $ok, string $reason): void {
        if (!$ok) { throw new \RuntimeException('SCHED_ASSIGN:'.$reason); }
    };
    $cases = ['create','replace','same','detach','detach_empty',
        'no_tenant','wrong_tenant','no_permission','no_schema',
        'cancelled','due','delivered','foreign_link','disabled_link','missing_link'];
    $success = ['create','replace','same','detach','detach_empty'];
    foreach ($cases as $mode) {
        DB::$transactions=0; ScheduledPublicationLink::$writes=0; Schema::$ready=true;
        $GLOBALS['delivered'] = false;
        $GLOBALS['schedule'] = new ScheduledPublication();
        $GLOBALS['link'] = new TrackedLink();
        $GLOBALS['assignment'] = null;
        $permission = true;
        $tenant='tenantA';
        $linkId='linkA';
        if ($mode === 'replace') { $GLOBALS['assignment'] = new FakeAssignment(); }
        if ($mode === 'same') {
            $GLOBALS['assignment'] = new FakeAssignment();
            $GLOBALS['assignment']->tracked_link_id='linkA';
        }
        if ($mode === 'detach') {
            $GLOBALS['assignment'] = new FakeAssignment();
            $linkId = null;
        }
        if ($mode === 'detach_empty') { $linkId = null; }
        if ($mode === 'no_tenant') { $tenant = null; }
        if ($mode === 'wrong_tenant') { $GLOBALS['schedule']->organization_id = 'tenantB'; }
        if ($mode === 'no_permission') { $permission = false; }
        if ($mode === 'no_schema') { Schema::$ready = false; }
        if ($mode === 'cancelled') { $GLOBALS['schedule']->status = 'cancelled'; }
        if ($mode === 'due') { $GLOBALS['schedule']->scheduled_for_utc = new \App\Models\FakeFuture(false); }
        if ($mode === 'delivered') { $GLOBALS['delivered'] = true; }
        if ($mode === 'foreign_link') { $GLOBALS['link']->organization_id = 'tenantB'; }
        if ($mode === 'disabled_link') { $GLOBALS['link']->status = 'disabled'; }
        if ($mode === 'missing_link') { $linkId = 'missing-link'; }
        $manager = new ContentScheduler(new TenantContext($tenant), new MediaAssetProcessor());
        $rejected = false;
        try {
            $manager->updateTrackedLink($GLOBALS['schedule'],new User($permission),$linkId);
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Validation\ValidationException|\RuntimeException $e) {
            $rejected = true;
        }
        $assert($rejected === !in_array($mode,$success,true), 'acceptance_'.$mode);
        $expectedWrites = in_array($mode,['create','replace','detach'],true) ? 1 : 0;
        $assert(ScheduledPublicationLink::$writes === $expectedWrites, 'writes_'.$mode);
        $assert($GLOBALS['link']->token === 'synthetic-public-token'
            && $GLOBALS['link']->clicks === [3,5], 'link_history_'.$mode);
        if (in_array($mode,['no_tenant','wrong_tenant','no_permission','no_schema'],true)) {
            $assert(DB::$transactions === 0, 'preauth_must_not_transact_'.$mode);
        }
    }
    echo json_encode(['cases'=>count($cases),'source'=>'real-PHP',
        'database'=>'synthetic-queries','network'=>'disabled',
        'production'=>'UNKNOWN'],JSON_THROW_ON_ERROR);
}
"""

# SPEC:035 (PARTIAL): call real Laravel SchedulerController::index against
# disposable Eloquent-shaped queries; no genuine SQL, HTTP or auth middleware.
SCHEDULER_PAGINATION_PROBE = r"""
namespace Illuminate\View {
    class View {
        public function __construct(public array $data) {}
    }
}
namespace Illuminate\Support {
    class Collection {
        public function __construct(public array $items=[]) {}
        public function where(string $key, mixed $value): self {
            return new self(array_values(array_filter($this->items,
                static fn($x) => $x->$key === $value)));
        }
        public function values(): self { return new self(array_values($this->items)); }
        public function count(): int { return count($this->items); }
        public function groupBy(callable $key): self {
            $groups=[];
            foreach ($this->items as $item) { $groups[$key($item)][] = $item; }
            return new self($groups);
        }
    }
}
namespace Illuminate\Database\Eloquent { class Builder {} }
namespace Illuminate\Http {
    class Request {
        public object $attributes;
        public array $rules=[];
        public function __construct(public array $data, private object $org) {
            $this->attributes = new class($org) {
                public function __construct(private object $org) {}
                public function get(string $key): object { return $this->org; }
            };
        }
        public function validate(array $rules): array {
            $this->rules = $rules;
            return array_intersect_key($this->data,$rules);
        }
        public function user(): object { return new \App\Models\User(); }
    }
}
namespace Illuminate\Validation {
    class Rule {
        public static function in(array $opts): array { return $opts; }
    }
}
namespace Illuminate\Support\Facades {
    class Schema {
        public static function hasTable(string $table): bool {
            return in_array($table,['publishing_destinations','scheduled_publications'],true);
        }
    }
}
namespace App\Http\Controllers { abstract class Controller {} }
namespace App\Models {
    class Organization {}
    class User {
        public function canScheduleOrganization(object $organization): bool { return true; }
    }
    class PublishingDestination {
        public const STATUS_ACTIVE = 'active';
        public function __construct(public string $status) {}
        public static function query(): DestinationQuery { return new DestinationQuery(); }
    }
    class DestinationQuery {
        public function orderBy(string $column): self { return $this; }
        public function get(): \Illuminate\Support\Collection {
            return new \Illuminate\Support\Collection([
                new PublishingDestination('active'),
                new PublishingDestination('disabled'),
            ]);
        }
    }
    class ScheduledPublication {
        public \DateTimeImmutable $scheduled_for_utc;
        public function __construct(public string $id, public string $status='scheduled') {
            $this->scheduled_for_utc = new \DateTimeImmutable('2031-04-05T12:00:00Z');
        }
        public static function query(): ScheduleQuery { return new ScheduleQuery(); }
    }
    class SchedulePaginator {
        public array $appended=[];
        public function __construct(
            public \Illuminate\Support\Collection $page,
            public int $total,
            public int $perPage,
        ) {}
        public function appends(array $data): self { $this->appended=$data; return $this; }
        public function getCollection(): \Illuminate\Support\Collection { return $this->page; }
    }
    class ScheduleQuery {
        public array $filters=[];
        public array $ordering=[];
        public function with(array $relations): self {
            if (!in_array('delivery',$relations,true)) {
                throw new \RuntimeException('MISSING_EAGER_DELIVERY');
            }
            return $this;
        }
        public function when(mixed $value, callable $callback): self {
            if ($value !== null && $value !== '') { $callback($this,$value); }
            return $this;
        }
        public function where(string $name, mixed $value): self {
            $this->filters[$name]=$value; return $this;
        }
        public function whereDate(string $name, string $operator, string $value): self {
            $this->filters[$name.$operator]=$value; return $this;
        }
        public function orderBy(string $name): self { $this->ordering[]=$name; return $this; }
        public function paginate(int $size): SchedulePaginator {
            $GLOBALS['captured_query']=$this;
            if ($size !== 25 || $this->ordering !== ['scheduled_for_utc','id']) {
                throw new \RuntimeException('UNSTABLE_CALENDAR_PAGINATION');
            }
            $all=[];
            for($i=1;$i<=52;$i++) {
                $all[] = new ScheduledPublication(sprintf('schedule-%03d',$i));
            }
            $page=$GLOBALS['page'];
            return new SchedulePaginator(
                new \Illuminate\Support\Collection(array_slice($all,($page-1)*$size,$size)),
                count($all),$size,
            );
        }
    }
    class MediaAsset {}
    class TrackedLink {}
}
namespace App\Services\Scheduling {
    class AssetQuery extends \Illuminate\Database\Eloquent\Builder {
        public function count(): int { return 0; }
        public function with(string $relation): self { return $this; }
        public function latest(): self { return $this; }
        public function orderByDesc(string $column): self { return $this; }
        public function limit(int $size): self { return $this; }
        public function get(): \Illuminate\Support\Collection {
            return new \Illuminate\Support\Collection();
        }
    }
    class ContentScheduler {
        public function eligibleAssetsQuery(): \Illuminate\Database\Eloquent\Builder {
            return new AssetQuery();
        }
    }
}
namespace {
    function collect(array $items=[]): \Illuminate\Support\Collection {
        return new \Illuminate\Support\Collection($items);
    }
    function old(string $key): mixed { return null; }
    function config(string $name, mixed $fallback): mixed { return $fallback; }
    function abort_unless(bool $condition, int $code): void {
        if (!$condition) { throw new \RuntimeException('ABORT_'.$code); }
    }
    function view(string $name, array $data): \Illuminate\View\View {
        if ($name !== 'scheduling.index') { throw new \RuntimeException('WRONG_VIEW'); }
        return new \Illuminate\View\View($data);
    }
    require_once 'app/Http/Controllers/Scheduling/SchedulerController.php';
    $assert = static function (bool $good,string $message): void {
        if (!$good) { throw new \RuntimeException('CALENDAR_PAGINATION:'.$message); }
    };
    $pages=[];
    for($page=1;$page<=3;$page++) {
        $GLOBALS['page']=$page;
        $input=['status'=>'scheduled','destination_id'=>'destA',
            'from'=>'2031-04-01','to'=>'2031-04-30',
            'page'=>$page, 'irrelevant_actor'=>'synthetic-forged'];
        $request=new \Illuminate\Http\Request($input,new \App\Models\Organization());
        $controller=new \App\Http\Controllers\Scheduling\SchedulerController();
        $view=$controller->index($request,new \App\Services\Scheduling\ContentScheduler());
        $paginator=$view->data['publications'];
        $assert($paginator->total===52,'complete_total_'.$page);
        $assert($paginator->perPage===25,'fixed_page_size_'.$page);
        $ids=array_map(static fn($row)=>$row->id,$paginator->getCollection()->items);
        $pages[]=$ids;
        $assert(count($ids)===($page<3?25:2),'page_length_'.$page);
        $assert(!array_key_exists('page',$paginator->appended)
            && !array_key_exists('irrelevant_actor',$paginator->appended),
            'no_unvalidated_filter_leak_'.$page);
        $assert($paginator->appended===[
            'status'=>'scheduled','destination_id'=>'destA',
            'from'=>'2031-04-01','to'=>'2031-04-30'],
            'preserved_validated_filters_'.$page);
        $filters=$GLOBALS['captured_query']->filters;
        $assert($filters['status']==='scheduled'
            && $filters['publishing_destination_id']==='destA'
            && $filters['scheduled_for_utc>=']==='2031-04-01'
            && $filters['scheduled_for_utc<=']==='2031-04-30',
            'filter_semantics_'.$page);
        $assert($view->data['filterDestinations']->count()===2
            && $view->data['destinations']->count()===1,
            'disabled_destination_remains_filterable_'.$page);
        $assert($view->data['calendarDays']->count()===1,'current_page_grouping_'.$page);
        $assert($request->rules['page'] === ['nullable','integer','min:1','max:10000'],
            'bounded_page_validation_declared_'.$page);
    }
    $flat=array_merge(...$pages);
    $assert(count($flat)===52 && count(array_unique($flat))===52,
        'no_duplicates_or_missing_under_identical_timestamps');
    $assert($flat===array_map(
        static fn(int $i)=>sprintf('schedule-%03d',$i),range(1,52)),
        'ordered_by_utc_then_id_across_pages');
    echo json_encode(['pages'=>3,'total'=>52,'source'=>'real-Controller-index',
        'database'=>'synthetic-Eloquent','network'=>'disabled',
        'production'=>'UNKNOWN'],JSON_THROW_ON_ERROR);
}
"""

class CoberturaGrindflow03Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        probes = run_php('principal')['probes']
        self.assertEqual(len(probes), 4)
        self.assertEqual({p.split('-')[0] for p in probes},
                         {'SPEC:018', 'SPEC:021', 'SPEC:025', 'SPEC:030'})

    def test_falla_cerrado(self) -> None:
        data = run_php('negative')
        self.assertIn('DENIED_WRONG_TENANT', data['probes'])
        self.assertIn('DENIED_WEAK_PASSWORD', data['probes'])
        self.assertIn('DENIED_INVALID_SLOT', data['probes'])
        self.assertIn('UNKNOWN_MISSING_VERSION', data['probes'])
        with self.assertRaises(ValueError):
            run_php('invalid')

    def test_horizon_gap_detector_real(self) -> None:
        # Real tenant-isolated pure-PHP calculation, not a persisted agenda.
        self.assertEqual(run_horizon_php('principal')['checks'], 2)

    def test_horizon_gap_rechaza_evidencia_insegura(self) -> None:
        self.assertEqual(run_horizon_php('negative')['checks'], 10)
        with self.assertRaises(ValueError):
            run_horizon_php('invalid')

    def test_retry_classifier_real(self) -> None:
        # Provider retry safety is only a partial local SPEC:029 contract.
        self.assertEqual(run_retry_php('positive')['checks'], 2)

    def test_retry_classifier_falla_cerrado(self) -> None:
        self.assertEqual(run_retry_php('negative')['checks'], 13)
        with self.assertRaises(ValueError):
            run_retry_php('invalid')

    def test_retry_classifier_limits_and_identifiers(self) -> None:
        # 31 deterministic boundaries exercise the real fail-closed PHP class;
        # neither response is evidence of a persisted retry or provider I/O.
        self.assertEqual(run_retry_php('boundaries')['checks'], 31)

    def test_automation_mode_gates_reales(self) -> None:
        self.assertEqual(run_eligibility_php('automation', 'principal')['checks'], 5)
        self.assertEqual(run_eligibility_php('automation', 'negative')['checks'], 18)
        with self.assertRaises(ValueError):
            run_eligibility_php('automation', 'bogus')

    def test_global_pause_audit_integrity(self) -> None:
        self.assertEqual(run_eligibility_php('pause', 'principal')['checks'], 6)
        self.assertEqual(run_eligibility_php('pause', 'negative')['checks'], 9)
        with self.assertRaises(ValueError):
            run_eligibility_php('pause', 'bogus')

    def test_distribution_command_validation_real(self) -> None:
        # Exercising the real, pure DistributionCommand DTO is NOT a provider attempt.
        self.assertEqual(run_distribution_php('positive')['checks'], 8)

    def test_distribution_command_validation_rejects_unsafe(self) -> None:
        self.assertEqual(run_distribution_php('negative')['checks'], 18)
        with self.assertRaises(ValueError):
            run_distribution_php('untrusted')

    def test_distribution_outcome_real_and_synthetic_protocol(self) -> None:
        # DTO + local synthetic adapter only; no external publication happened.
        self.assertEqual(run_distribution_outcome_php('positive')['checks'], 4)

    def test_distribution_outcome_rejects_invalid_identifiers(self) -> None:
        self.assertEqual(run_distribution_outcome_php('negative')['checks'], 7)
        with self.assertRaises(ValueError):
            run_distribution_outcome_php('untrusted')

    def test_distribution_command_and_outcome_are_immutable(self) -> None:
        # A synthetic DTO cannot be rewritten after validation, including
        # tenant identity and the external result. Not a persisted audit test.
        self.assertEqual(run_distribution_outcome_php('immutability')['checks'], 4)

    def test_preview_agenda_real_y_falla_cerrado(self) -> None:
        # Pure preview, not persistence, authorization, reservations or DB.
        self.assertEqual(run_scheduling_php('preview')['checks'], 8)
        self.assertEqual(run_scheduling_php('preview_negative')['checks'], 9)
        with self.assertRaises(ValueError):
            run_scheduling_php('unknown')

    def test_calendario_tenant_real_y_falla_cerrado(self) -> None:
        # Read-only, already-authorized calendar aggregate; not UI pagination.
        self.assertEqual(run_scheduling_php('calendar')['checks'], 6)
        self.assertEqual(run_scheduling_php('calendar_negative')['checks'], 10)

    def test_finance_create_real_y_falla_cerrado(self) -> None:
        # SPEC:028: only create-path permission and input invariants. This does
        # not prove tenant Eloquent scopes, DB transactions or append-only ledger.
        for mode in ('valid','valid_beneficiary','no_tenant','no_permission',
                     'zero_amount','empty_source','invalid_currency',
                     'foreign_beneficiary'):
            with self.subTest(mode=mode):
                self.assertEqual(run_finance_php(mode)['mode'], mode)
        with self.assertRaises(ValueError):
            run_finance_php('unknown')

    def test_finance_reverse_real_y_falla_cerrado(self) -> None:
        # SPEC:028 append-shaped reversal; fake DB does NOT prove atomicity.
        for mode in ('valid','no_tenant','no_permission','empty_reason',
                     'reverse_a_reversal','already_reversed','missing_original'):
            with self.subTest(mode=mode):
                self.assertEqual(run_finance_reversal_php(mode)['mode'], mode)
        with self.assertRaises(ValueError):
            run_finance_reversal_php('unknown')

    def test_finance_reconciliation_real_y_falla_cerrado(self) -> None:
        # SPEC:036: filters, all groups, date-of-reversal and net values.
        # The fake Builder never exercises real SQL or Laravel tenant scopes.
        for mode, expected in [('filters', 4), ('totals', 3), ('dates', 4)]:
            with self.subTest(mode=mode):
                self.assertEqual(run_finance_reconciliation_php(mode)['checks'], expected)
        with self.assertRaises(ValueError):
            run_finance_reconciliation_php('unknown')

    def test_tracked_link_real_y_falla_cerrado(self) -> None:
        # SPEC:032/037 partial: status, metadata, token/history guards.
        # The fake query does NOT exercise a genuine Eloquent tenant scope.
        for mode in ('pause','resume','same','details','details_disabled',
                     'no_tenant_status','no_permission_status','bad_status',
                     'missing_status','no_tenant_details','no_permission_details',
                     'bad_label','bad_scheme','bad_url','bad_channel',
                     'bad_campaign','missing_details'):
            with self.subTest(mode=mode):
                self.assertEqual(run_traffic_link_php(mode)['mode'], mode)
        with self.assertRaises(ValueError):
            run_traffic_link_php('unknown')

    def test_media_eligibility_real_y_falla_cerrado(self) -> None:
        # SPEC:038 partial: real eligibility predicate and query filters;
        # synthetic query, no real Eloquent scopes or pagination.
        self.assertEqual(run_media_eligibility_php()['cases'], 12)

    def test_csv_traffic_finance_formula_guards_reales(self) -> None:
        # SPEC:031/036 partial: real CSV formula sanitizers in both controllers.
        # Does not attest filters, query completeness, streaming or HTTP headers.
        self.assertEqual(run_csv_export_guards_php()['cases'], 11)

    def test_handoff_y_destino_manual_reales_y_seguridad(self) -> None:
        # SPEC:019/020 partial: real state representation, destination labels,
        # public projection and omission of secrets. Does not attest ledger,
        # transaction locking, tenant SQL or external publication.
        self.assertEqual(run_manual_workflow_php()['destination_labels'], 8)

    def test_laravel_tenant_scope_real_y_falla_cerrado(self) -> None:
        # SPEC:021/022 partial: real TenantScope fail-closed without context;
        # tests organization predicates, not actual Eloquent SQL/DB isolation.
        self.assertEqual(run_tenant_scope_php()['cases'], 4)

    def test_vault_manifest_private_note_restore_integrity_real(self) -> None:
        # SPEC:024 partial: genuine VaultManifest canonicalization/digest
        # includes a private working note even for retained trash. This is a
        # synthetic catalog check, not authorization/CSRF/DB/restore I/O.
        php = shutil.which('php')
        if php is None:
            raise AssertionError('UNKNOWN: PHP CLI is not installed')
        script = r"""
declare(strict_types=1);
require_once 'symfony/src/Infrastructure/Storage/VaultManifest.php';
use GrindFlow\Infrastructure\Storage\VaultManifest;
$assert = static function (bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('VAULT_MANIFEST:' . $label); }
};
$asset = [
    'id'=>'synthetic-asset-1', 'uploaded_by'=>'synthetic-user',
    'original_name'=>'synthetic-image.webp', 'mime_type'=>'image/webp',
    'size_bytes'=>123, 'sha256'=>str_repeat('a', 64),
    'storage_key'=>'synthetic/asset', 'created_at'=>'2026-01-01T00:00:00Z',
    'deleted_at'=>null, 'deleted_by'=>null,
    'private_note'=>'synthetic-private-note', 'usage_scope'=>'organization',
];
$canonical = VaultManifest::canonical($asset);
$assert($canonical['private_note'] === 'synthetic-private-note',
    'private note preserved in canonical catalog');
$base = VaultManifest::digest([$asset]);
$changed = $asset;
$changed['private_note'] = 'synthetic-altered-note';
$assert($base !== VaultManifest::digest([$changed]),
    'note tampering must alter catalog fingerprint');
$cleared = $asset;
$cleared['private_note'] = null;
$assert(VaultManifest::canonical($cleared)['private_note'] === null,
    'null note stays null');
$assert($base !== VaultManifest::digest([$cleared]),
    'note clearing must alter fingerprint');
$trashed = $asset;
$trashed['deleted_at'] = '2026-02-01T00:00:00Z';
$trashed['deleted_by'] = 'synthetic-user';
$assert(VaultManifest::canonical($trashed)['private_note'] === 'synthetic-private-note',
    'trash still retains private note');
$assert(VaultManifest::digest([$trashed]) !== VaultManifest::digest([$changed] ),
    'distinct note and trash metadata must remain distinguishable');
$restored = $trashed;
$restored['deleted_at'] = null;
$restored['deleted_by'] = null;
$assert(VaultManifest::digest([$restored]) === $base,
    'restoring unchanged source returns identical fingerprint');
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
$incomplete = $asset;
unset($incomplete['private_note']);
$denied = false;
try { VaultManifest::canonical($incomplete); }
catch (Throwable $e) { $denied = true; }
restore_error_handler();
$assert($denied, 'missing private note must not be treated as empty');
echo json_encode(['checks'=>8,'network'=>'disabled','production'=>'UNKNOWN'], JSON_THROW_ON_ERROR);
"""
        result = subprocess.run(
            [php, '-d', 'display_errors=stderr', '-r', script],
            cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
        )
        self.assertEqual(
            result.returncode, 0,
            f'UNKNOWN: VaultManifest real PHP failed: {result.stderr}',
        )
        self.assertEqual(json.loads(result.stdout)['checks'], 8)

    def test_scheduler_tracked_link_assignment_real_y_falla_cerrado(self) -> None:
        # SPEC:034 partial: calls the actual ContentScheduler manager for
        # add, replace, detach and negative gates under synthetic locks.
        php = shutil.which('php')
        if php is None:
            raise AssertionError('UNKNOWN: PHP CLI is not installed')
        result = subprocess.run(
            [php, '-d', 'display_errors=stderr', '-r', SCHEDULER_LINK_ASSIGNMENT_PROBE],
            cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
        )
        self.assertEqual(
            result.returncode, 0,
            f'UNKNOWN: real Scheduler assignment probe failed: {result.stderr}',
        )
        response = json.loads(result.stdout)
        self.assertEqual(response['cases'], 15)
        self.assertEqual(response['source'], 'real-PHP')
        self.assertEqual(response['database'], 'synthetic-queries')
        self.assertEqual(response['production'], 'UNKNOWN')

    def test_scheduler_calendar_paginacion_real_y_filtros(self) -> None:
        # SPEC:035 partial: actual Laravel SchedulerController::index method,
        # with synthetic query/paginator. SQL/HTTP and middleware not executed.
        php = shutil.which('php')
        if php is None:
            raise AssertionError('UNKNOWN: PHP CLI missing')
        result = subprocess.run(
            [php, '-d', 'display_errors=stderr', '-r', SCHEDULER_PAGINATION_PROBE],
            cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
        )
        self.assertEqual(
            result.returncode, 0,
            f'UNKNOWN: Scheduler calendar index failed: {result.stderr}',
        )
        data = json.loads(result.stdout)
        self.assertEqual((data['pages'], data['total']), (3, 52))
        self.assertEqual(data['source'], 'real-Controller-index')
        self.assertEqual(data['database'], 'synthetic-Eloquent')
        self.assertEqual(data['production'], 'UNKNOWN')

    def test_sin_datos_reales(self) -> None:
        self.assertNotIn('matriz-cobertura', SCHEDULER_PAGINATION_PROBE)
        self.assertNotIn('curl_exec(', SCHEDULER_PAGINATION_PROBE)

        self.assertNotIn('matriz-cobertura', SCHEDULER_LINK_ASSIGNMENT_PROBE)
        self.assertNotIn('curl_exec(', SCHEDULER_LINK_ASSIGNMENT_PROBE)
        self.assertNotIn('file_get_contents(', SCHEDULER_LINK_ASSIGNMENT_PROBE)
        self.assertNotIn('matriz-cobertura', TENANT_SCOPE_PROBE)
        self.assertNotIn('curl_exec(', TENANT_SCOPE_PROBE)
        self.assertNotIn('file_get_contents(', TENANT_SCOPE_PROBE)
        self.assertNotIn('matriz-cobertura', MANUAL_WORKFLOW_PROBE)
        self.assertNotIn('curl_exec(', MANUAL_WORKFLOW_PROBE)
        self.assertNotIn('file_get_contents(', MANUAL_WORKFLOW_PROBE)
        self.assertNotIn('matriz-cobertura', CSV_EXPORT_PROBE)
        self.assertNotIn('curl_exec(', CSV_EXPORT_PROBE)
        self.assertNotIn('file_get_contents(', CSV_EXPORT_PROBE)
        self.assertNotIn('matriz-cobertura', MEDIA_ELIGIBILITY_PROBE)
        self.assertNotIn('curl_exec(', MEDIA_ELIGIBILITY_PROBE)
        self.assertNotIn('file_get_contents(', MEDIA_ELIGIBILITY_PROBE)
        self.assertNotIn('matriz-cobertura', TRAFFIC_LINK_PROBE)
        self.assertNotIn('curl_exec(', TRAFFIC_LINK_PROBE)
        self.assertNotIn('file_get_contents(', TRAFFIC_LINK_PROBE)
        self.assertNotIn('matriz-cobertura', FINANCE_RECONCILIATION_PROBE)
        self.assertNotIn('curl_exec(', FINANCE_RECONCILIATION_PROBE)
        self.assertNotIn('matriz-cobertura', FINANCE_REVERSAL_PROBE)
        self.assertNotIn('curl_exec(', FINANCE_REVERSAL_PROBE)
        self.assertNotIn('file_get_contents(', FINANCE_REVERSAL_PROBE)
        self.assertNotIn('matriz-cobertura', FINANCE_LEDGER_PROBE)
        self.assertNotIn('curl_exec(', FINANCE_LEDGER_PROBE)
        self.assertNotIn('file_get_contents(', FINANCE_LEDGER_PROBE)
        self.assertNotIn('db()', FINANCE_LEDGER_PROBE)
        self.assertNotIn('matriz-cobertura', PHP_PROBE)
        self.assertNotIn('matriz-cobertura', RETRY_PROBE)
        self.assertNotIn('matriz-cobertura', HORIZON_PROBE)
        self.assertNotIn('matriz-cobertura', AUTOMATION_PROBE)
        self.assertNotIn('matriz-cobertura', PAUSE_PROBE)
        self.assertNotIn('matriz-cobertura', DISTRIBUTION_PROBE)
        self.assertNotIn('matriz-cobertura', DISTRIBUTION_OUTCOME_PROBE)
        self.assertNotIn('matriz-cobertura', SCHEDULING_BOUNDARY_PROBE)
        self.assertNotIn('curl_exec(', SCHEDULING_BOUNDARY_PROBE)
        self.assertNotIn('file_get_contents(', SCHEDULING_BOUNDARY_PROBE)
        self.assertNotIn('curl_exec(', DISTRIBUTION_OUTCOME_PROBE)
        self.assertNotIn('file_get_contents(', DISTRIBUTION_OUTCOME_PROBE)
        self.assertNotIn('curl_exec(', DISTRIBUTION_PROBE)
        self.assertNotIn('file_get_contents(', DISTRIBUTION_PROBE)
        self.assertNotIn('curl_exec(', HORIZON_PROBE)
        self.assertNotIn('file_get_contents(', HORIZON_PROBE)
        self.assertNotIn('curl_exec(', RETRY_PROBE)
        self.assertNotIn('file_get_contents(', RETRY_PROBE)
        self.assertNotIn('curl_exec(', PHP_PROBE)
        self.assertNotIn('file_get_contents(', PHP_PROBE)
        self.assertNotIn('password_hash(', PHP_PROBE)
        self.assertNotIn('db()', PHP_PROBE)
        self.assertNotIn('Illuminate', PHP_PROBE)
        self.assertEqual(run_php('principal')['production'], 'UNKNOWN')
        for path in (
            'symfony/src/Scheduling/WeeklySlotCalculator.php',
            'symfony/src/Scheduling/Studio/InheritedRuleSet.php',
            'symfony/src/Identity/Security/PasswordPolicy.php',
            'symfony/src/Shared/Version/ProductVersion.php',
            'config/version.php',
            'symfony/src/Scheduling/Recovery/RetryClassifier.php',
            'symfony/src/Scheduling/Horizon/GapDetector.php',
            'symfony/src/Scheduling/Mode/AutomationMode.php',
            'symfony/src/Scheduling/Preview/GlobalPauseState.php',
            'symfony/src/Distribution/DistributionCommand.php',
            'symfony/src/Distribution/DistributionOutcome.php',
            'symfony/src/Distribution/DistributionProvider.php',
            'app/Services/Finance/FinanceLedgerManager.php',
            'app/Services/Traffic/TrackedLinkManager.php',
        ):
            self.assertTrue((ROOT / path).is_file(), path)


if __name__ == '__main__':
    unittest.main()
