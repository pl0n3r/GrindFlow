<?php

declare(strict_types=1);

use GrindFlow\Scheduling\Resolution\FlexibleWindow;
use GrindFlow\Scheduling\Resolution\PriorityConflictResolver;
use GrindFlow\Scheduling\Resolution\MinimalReschedulePlanner;

require_once __DIR__ . '/../../src/Scheduling/Resolution/FlexibleWindow.php';
require_once __DIR__ . '/../../src/Scheduling/Resolution/PriorityConflictResolver.php';
require_once __DIR__ . '/../../src/Scheduling/Resolution/MinimalReschedulePlanner.php';

function verify(bool $value, string $reason): void
{
    if (!$value) {
        throw new RuntimeException('scheduling_contract_failed_' . $reason);
    }
}

function item(string $id, string $priority, string $at, array $window, string $tenant = 'synthetic'): array
{
    return [
        'id' => $id, 'tenant_id' => $tenant, 'priority' => $priority,
        'scheduled_at_utc' => $at, 'window' => $window
    ];
}

function exact(string $at): array
{
    return ['kind' => 'exact', 'at_utc' => $at];
}

function range(string $start, string $end, string $kind = 'range'): array
{
    return [
        'kind' => $kind, 'start_utc' => $start, 'end_utc' => $end,
        'step_minutes' => 60
    ];
}

function rejected(callable $operation): bool
{
    try {
        $operation();
    } catch (InvalidArgumentException|DomainException $error) {
        verify(!str_contains($error->getMessage(), 'private') &&
            !str_contains($error->getMessage(), 'token'), 'no_secret_echo');
        return true;
    }

    return false;
}

$scenario = $argv[1] ?? '';
$at = '2026-10-12T10:00:00Z';
$before = '2026-10-12T09:00:00Z';
$after = '2026-10-12T11:00:00Z';
$resolver = new PriorityConflictResolver();
$selector = new FlexibleWindow();
$planner = new MinimalReschedulePlanner();

switch ($scenario) {
    case 'AC-01':
        $items = [
            item('normal-1', 'normal', $at, range($before, $after)),
            item('urgent-1', 'urgente', $at, exact($at)),
            item('high-1', 'alta', $after, exact($after))
        ];
        verify($resolver->resolve($items) === [
            'kept' => ['high-1', 'urgent-1'], 'move' => ['normal-1']
        ], 'priority');
        $ties = [
            item('z-last', 'alta', $at, range($before, $after)),
            item('a-first', 'alta', $at, range($before, $after))
        ];
        verify($resolver->resolve($ties) === [
            'kept' => ['a-first'], 'move' => ['z-last']
        ], 'tie_break');
        break;

    case 'AC-02':
        $window = range($before, $after);
        verify($selector->choose($window, $at, []) === $at, 'prefer_original');
        verify($selector->choose($window, $at, [$at]) === $before, 'nearest_slot');
        verify($selector->choose(range($before, $after, 'suggested'), $at, [$at, $before]) ===
            $after, 'suggested');
        verify($selector->choose(exact($at), $at, []) === $at, 'exact');
        verify($selector->choose(exact($at), $at, [$at]) === null, 'exact_busy');
        verify(rejected(fn () => $selector->candidates(exact($at), $before)), 'exact_mismatch');
        verify(rejected(fn () => $selector->candidates(
            range('2026-02-31T09:00:00Z', $after), $at
        )), 'reject_normalized_date');
        verify(rejected(fn () => $selector->candidates(
            range($before, '2026-10-14T11:00:00Z'), $at
        )), 'reject_unbounded');
        break;

    case 'AC-03':
        $items = [
            item('a-to-move', 'normal', $at, range($before, $after)),
            item('b-winner', 'urgente', $at, exact($at)),
            item('c-stable', 'normal', '2026-10-12T12:00:00Z', exact('2026-10-12T12:00:00Z')),
            item('d-stable', 'alta', $after, exact($after))
        ];
        verify($planner->plan($items) === [
            ['id' => 'a-to-move', 'scheduled_at_utc' => $before, 'moved' => true],
            ['id' => 'b-winner', 'scheduled_at_utc' => $at, 'moved' => false],
            ['id' => 'c-stable', 'scheduled_at_utc' => '2026-10-12T12:00:00Z', 'moved' => false],
            ['id' => 'd-stable', 'scheduled_at_utc' => $after, 'moved' => false]
        ], 'minimum_changes');
        break;

    case 'AC-04':
        $items = [
            item('a', 'normal', $at, range($before, $after)),
            item('b', 'urgente', $at, exact($at)),
            item('c', 'alta', $after, exact($after))
        ];
        verify($planner->plan($items) === $planner->plan(array_reverse($items)), 'determinism');
        verify(rejected(fn () => $resolver->resolve([
            item('a', 'normal', $at, exact($at), 'tenant-1'),
            item('b', 'alta', $at, exact($at), 'tenant-2')
        ])), 'tenant_scope');
        verify(rejected(fn () => $resolver->resolve([
            item('a', 'normal', $at, exact($at)),
            item('a', 'alta', $after, exact($after))
        ])), 'duplicate_id');
        verify(rejected(fn () => $resolver->resolve([
            item('a', 'normal', $at, exact($at)) + ['private_token' => 'private']
        ])), 'unknown_fields');
        verify(rejected(fn () => $planner->plan([
            item('a', 'normal', $at, exact($at)),
            item('b', 'urgente', $at, exact($at))
        ])), 'no_safe_slot');
        verify(rejected(fn () => $resolver->resolve(array_fill(
            0, 101, item('a', 'normal', $at, exact($at))
        ))), 'bounded');
        break;

    default:
        throw new RuntimeException('unknown_test_scenario');
}

echo $scenario . " PASS\n";
