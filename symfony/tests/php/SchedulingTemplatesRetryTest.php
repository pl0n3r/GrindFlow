<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Scheduling/Templates/CalendarTemplate.php';
require_once dirname(__DIR__, 2) . '/src/Scheduling/Templates/CampaignCalendar.php';
require_once dirname(__DIR__, 2) . '/src/Scheduling/Recovery/RetryClassifier.php';

use GrindFlow\Scheduling\Templates\CalendarTemplate;
use GrindFlow\Scheduling\Templates\CampaignCalendar;
use GrindFlow\Scheduling\Recovery\RetryClassifier;

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('test_failed: ' . $message);
    }
}

function rejects(callable $call): void
{
    try {
        $call();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('test_failed: expected_invalid_argument');
}

function pattern(string $scope, string $target, string $tenant = 't1'): array
{
    return [
        'tenant_id' => $tenant, 'template_id' => 'template_1',
        'scope' => $scope, 'target_id' => $target,
        'timezone' => 'America/Bogota', 'local_time' => '09:30',
        'weekdays' => [5, 1], 'frequency_days' => 7,
        'start_date' => '2026-10-10', 'end_date' => '2026-11-10',
    ];
}

function ownership(array $collectionIds): array
{
    return [
        'account_id' => 'account_A',
        'account_tenant_id' => 't1',
        'collection_tenant_by_id' => array_fill_keys($collectionIds, 't1'),
    ];
}

function failure(): array
{
    return [
        'tenant_id' => 't1', 'delivery_id' => 'delivery_1',
        'delivery_state' => 'failed', 'failure_code' => 'rate_limited',
        'idempotency_key' => 'idem_1', 'idempotency_proven' => true,
        'attempt' => 1, 'max_attempts' => 4,
    ];
}

$cases = [
    'template' => static function (): void {
        $template = new CalendarTemplate();
        foreach (['account' => 'account_id', 'campaign' => 'campaign_id',
                  'period' => 'period_id'] as $scope => $key) {
            $applied = $template->apply(pattern($scope, 'bound_1'),
                ['tenant_id' => 't1', $key => 'bound_1']);
            ensure($applied['scope'] === $scope && $applied['scope_id'] === 'bound_1',
                'bound_scope_' . $scope);
            ensure($applied['weekdays'] === [1, 5], 'canonical_weekdays');
            rejects(static fn () => $template->apply(pattern($scope, 'bound_1'),
                ['tenant_id' => 't2', $key => 'bound_1']));
            rejects(static fn () => $template->apply(pattern($scope, 'bound_1'),
                ['tenant_id' => 't1', $key => 'other']));
        }
        rejects(static fn () => $template->apply(pattern('account', 'a'),
            ['tenant_id' => 't1', 'campaign_id' => 'a']));
        foreach ([
            ['weekdays', [1, 1]], ['weekdays', [0]], ['weekdays', [8]],
            ['local_time', '24:00'], ['frequency_days', 0],
            ['frequency_days', 91], ['end_date', '2026-10-09'],
        ] as [$field, $invalid]) {
            $bad = pattern('account', 'a');
            $bad[$field] = $invalid;
            rejects(static fn () => $template->apply($bad, ['tenant_id' => 't1', 'account_id' => 'a']));
        }
        $bad = pattern('account', 'a');
        $bad['timezone'] = 'Not/AZone';
        rejects(static fn () => $template->apply($bad, ['tenant_id' => 't1', 'account_id' => 'a']));
        $bad = pattern('account', 'a');
        $bad['start_date'] = '2026-02-30';
        rejects(static fn () => $template->apply($bad, ['tenant_id' => 't1', 'account_id' => 'a']));
    },
    'campaign' => static function (): void {
        $calendar = new CampaignCalendar();
        $first = [
            'tenant_id' => 't1', 'campaign_id' => 'campaign_A',
            'account_id' => 'account_A', 'collection_ids' => ['one', 'two'],
        ];
        $a = $calendar->define($first, pattern('campaign', 'campaign_A'), ownership(['one', 'two']));
        ensure($a['rule']['scope'] === 'campaign', 'campaign_scope');
        ensure($a['collection_ids'] === ['one', 'two'], 'campaign_collections');
        ensure($a['activation_allowed'] === false, 'activation_denied');
        $second = [
            'tenant_id' => 't1', 'campaign_id' => 'campaign_B',
            'account_id' => 'account_A', 'collection_ids' => ['three'],
        ];
        $b = $calendar->define($second, pattern('campaign', 'campaign_B'), ownership(['three']));
        $a['rule']['weekdays'][0] = 3;
        ensure($b['rule']['weekdays'] === [1, 5], 'independent_schedule');
        ensure($b['collection_ids'] === ['three'], 'independent_collections');
        rejects(static fn () => $calendar->define($second, pattern('campaign', 'campaign_B', 't2'), ownership(['three'])));
        // Client-selected IDs are insufficient: evidence must come from a
        // trusted server-side catalog for both account and every collection.
        rejects(static fn () => $calendar->define($first, pattern('campaign', 'campaign_A')));
        $foreign = ownership(['one', 'two']);
        $foreign['collection_tenant_by_id']['two'] = 't2';
        rejects(static fn () => $calendar->define($first, pattern('campaign', 'campaign_A'), $foreign));
        $foreignAccount = ownership(['one', 'two']);
        $foreignAccount['account_tenant_id'] = 't2';
        rejects(static fn () => $calendar->define($first, pattern('campaign', 'campaign_A'), $foreignAccount));
        $wrongAccount = ownership(['one', 'two']);
        $wrongAccount['account_id'] = 'account_B';
        rejects(static fn () => $calendar->define($first, pattern('campaign', 'campaign_A'), $wrongAccount));
        rejects(static fn () => $calendar->define($first, pattern('campaign', 'campaign_A'), ownership(['one'])));
        $extra = ownership(['one', 'two', 'extra']);
        rejects(static fn () => $calendar->define($first, pattern('campaign', 'campaign_A'), $extra));
        rejects(static fn () => $calendar->define($first, pattern('account', 'campaign_A'), ownership(['one', 'two'])));
        $duplicate = $first;
        $duplicate['collection_ids'] = ['one', 'one'];
        rejects(static fn () => $calendar->define($duplicate, pattern('campaign', 'campaign_A'), ownership(['one'])));
    },
    'ambiguous' => static function (): void {
        $classifier = new RetryClassifier();
        $case = failure();
        $case['delivery_state'] = 'ambiguous';
        ensure($classifier->classify($case)['decision'] === 'retry_never', 'ambiguous');
        $case = failure();
        $case['failure_code'] = 'timeout_after_send';
        ensure($classifier->classify($case)['decision'] === 'retry_never', 'after_send');
        $case = failure();
        $case['delivery_state'] = 'in_flight';
        ensure($classifier->classify($case)['decision'] === 'retry_never', 'in_flight');
        $case = failure();
        unset($case['delivery_state']);
        ensure($classifier->classify($case)['reason'] === 'invalid_input', 'missing_evidence');
        $case = failure();
        $case['delivery_state'] = 'succeeded';
        ensure($classifier->classify($case) === [
            'decision' => 'retry_never', 'reason' => 'not_a_confirmed_failure',
        ], 'succeeded_is_not_failure');
        $case = failure();
        $case['failure_code'] = 'permission_denied';
        ensure($classifier->classify($case) === [
            'decision' => 'needs_human', 'reason' => 'authorization_required',
        ], 'permission_needs_human');
        $case = failure();
        $case['max_attempts'] = 6;
        ensure($classifier->classify($case) === [
            'decision' => 'retry_never', 'reason' => 'invalid_input',
        ], 'max_attempts_hard_limit');
        $case = failure();
        $case['unexpected'] = 'extra';
        ensure($classifier->classify($case) === [
            'decision' => 'retry_never', 'reason' => 'invalid_input',
        ], 'unexpected_keys_fail_closed');
    },
    'idempotent' => static function (): void {
        $classifier = new RetryClassifier();
        $case = failure();
        ensure($classifier->classify($case)['decision'] === 'retry_safe', 'bounded_rate_limit');
        $case['failure_code'] = 'transient_before_send';
        ensure($classifier->classify($case)['decision'] === 'retry_safe', 'before_send');
        $case['idempotency_proven'] = false;
        ensure($classifier->classify($case)['decision'] === 'retry_never', 'unverified');
        $case['idempotency_proven'] = true;
        $case['idempotency_key'] = '';
        ensure($classifier->classify($case)['decision'] === 'retry_never', 'missing_key');
        $case = failure();
        $case['attempt'] = 4;
        ensure($classifier->classify($case)['decision'] === 'retry_never', 'exhausted');
        $case = failure();
        $case['failure_code'] = 'authentication_failed';
        ensure($classifier->classify($case)['decision'] === 'needs_human', 'auth');
        $case['failure_code'] = 'unknown';
        ensure($classifier->classify($case)['decision'] === 'retry_never', 'unknown');
    },
];

$choice = $argv[1] ?? null;
if ($choice !== null && !array_key_exists($choice, $cases)) {
    fwrite(STDERR, "invalid_case\n");
    exit(2);
}
foreach ($cases as $name => $run) {
    if ($choice === null || $choice === $name) {
        $run();
        echo "PASS {$name}\n";
    }
}
