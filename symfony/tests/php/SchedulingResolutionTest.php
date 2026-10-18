<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DomainException;
use InvalidArgumentException;
use RuntimeException;
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

function rangeWindow(string $start, string $end, string $kind = 'range'): array
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

function runSchedulingScenario(string $scenario): void
{
$at = '2026-10-12T10:00:00Z';
$before = '2026-10-12T09:00:00Z';
$after = '2026-10-12T11:00:00Z';
$resolver = new PriorityConflictResolver();
$selector = new FlexibleWindow();
$planner = new MinimalReschedulePlanner();

switch ($scenario) {
    case 'AC-01':
        $items = [
            item('normal-1', 'normal', $at, rangeWindow($before, $after)),
            item('urgent-1', 'urgente', $at, exact($at)),
            item('high-1', 'alta', $after, exact($after))
        ];
        verify($resolver->resolve($items) === [
            'kept' => ['high-1', 'urgent-1'], 'move' => ['normal-1']
        ], 'priority');
        $ties = [
            item('z-last', 'alta', $at, rangeWindow($before, $after)),
            item('a-first', 'alta', $at, rangeWindow($before, $after))
        ];
        verify($resolver->resolve($ties) === [
            'kept' => ['a-first'], 'move' => ['z-last']
        ], 'tie_break');
        break;

    case 'AC-02':
        $window = rangeWindow($before, $after);
        verify($selector->choose($window, $at, []) === $at, 'prefer_original');
        verify($selector->choose($window, $at, [$at]) === $before, 'nearest_slot');
        verify($selector->choose(rangeWindow($before, $after, 'suggested'), $at, [$at, $before]) ===
            $after, 'suggested');
        verify($selector->choose(exact($at), $at, []) === $at, 'exact');
        verify($selector->choose(exact($at), $at, [$at]) === null, 'exact_busy');
        verify(rejected(fn () => $selector->candidates(exact($at), $before)), 'exact_mismatch');
        verify(rejected(fn () => $selector->candidates(
            rangeWindow('2026-02-31T09:00:00Z', $after), $at
        )), 'reject_normalized_date');
        verify(rejected(fn () => $selector->candidates(
            rangeWindow($before, '2026-10-14T11:00:00Z'), $at
        )), 'reject_unbounded');
        break;

    case 'AC-03':
        $items = [
            item('a-to-move', 'normal', $at, rangeWindow($before, $after)),
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

        // The high-priority loser prefers 09:00, but the normal loser can
        // use ONLY 09:00. A safe complete assignment exists at 11:00/09:00.
        $constrained = [
            item('a-flexible', 'alta', $at, rangeWindow($before, $after)),
            item('b-restricted', 'normal', $at, rangeWindow($before, $at)),
            item('c-winner', 'urgente', $at, exact($at))
        ];
        $matched = [
            ['id' => 'a-flexible', 'scheduled_at_utc' => $after, 'moved' => true],
            ['id' => 'b-restricted', 'scheduled_at_utc' => $before, 'moved' => true],
            ['id' => 'c-winner', 'scheduled_at_utc' => $at, 'moved' => false]
        ];
        verify($planner->plan($constrained) === $matched, 'matching_finds_safe_slot');
        verify($planner->plan(array_reverse($constrained)) === $matched, 'matching_deterministic');

        // A later loser with its own free option must not displace an
        // already-placed higher-priority loser from the nearest UTC slot.
        $bothFlexible = [
            item('a-high', 'alta', $at, rangeWindow($before, $after)),
            item('b-low', 'normal', $at, rangeWindow($before, $after)),
            item('c-winner', 'urgente', $at, exact($at))
        ];
        $minimalPreference = [
            ['id' => 'a-high', 'scheduled_at_utc' => $before, 'moved' => true],
            ['id' => 'b-low', 'scheduled_at_utc' => $after, 'moved' => true],
            ['id' => 'c-winner', 'scheduled_at_utc' => $at, 'moved' => false]
        ];
        verify($planner->plan($bothFlexible) === $minimalPreference, 'avoid_unneeded_reassignment');
        verify($planner->plan(array_reverse($bothFlexible)) === $minimalPreference, 'preference_deterministic');
        break;

    case 'AC-04':
        $items = [
            item('a', 'normal', $at, rangeWindow($before, $after)),
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
        verify(rejected(fn () => $selector->choose(
            rangeWindow($before, $after), $at, [(object)['private_token' => 'private']]
        )), 'occupied_object_rejected');
        verify(rejected(fn () => $selector->choose(
            rangeWindow($before, $after), $at, [[$before]]
        )), 'occupied_array_rejected');
        verify(rejected(fn () => $selector->choose(
            rangeWindow($before, $after), $at, [$before, $before]
        )), 'occupied_duplicates_rejected');
        verify(rejected(fn () => $planner->plan([
            item('a', 'normal', $at, exact($at)),
            item('b', 'urgente', $at, exact($at))
        ])), 'no_safe_slot');

        // All losers have only the same remaining slot; true infeasibility
        // must still fail closed, even with the augmenting-path matcher.
        $impossible = [
            item('a-first', 'normal', $at, rangeWindow($before, $at)),
            item('b-second', 'normal', $at, rangeWindow($before, $at)),
            item('c-winner', 'urgente', $at, exact($at))
        ];
        verify(rejected(fn () => $planner->plan($impossible)), 'matching_true_exhaustion');
        // Use distinct IDs so this probes the size bound, not duplicate-ID rejection.
        $distinct = static fn (int $count): array => array_map(
            static fn (int $i): array => item(sprintf('id-%03d', $i), 'normal', $at, exact($at)),
            range(1, $count)
        );
        verify(count($resolver->resolve($distinct(100))['move']) === 99, 'bounded_accepts_100');
        verify(rejected(fn () => $resolver->resolve($distinct(101))), 'bounded');
        break;

    default:
        throw new RuntimeException('unknown_test_scenario');
}

echo $scenario . " PASS\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    runSchedulingScenario($argv[1] ?? '');
}

if (class_exists(\PHPUnit\Framework\TestCase::class)) {
    final class SchedulingResolutionTest extends \PHPUnit\Framework\TestCase
    {
        public function testSyntheticScenariosArePureDeterministicAndFailClosed(): void
        {
            foreach (['AC-01', 'AC-02', 'AC-03', 'AC-04'] as $criterion) {
                ob_start();
                try {
                    runSchedulingScenario($criterion);
                    $output = ob_get_clean();
                } catch (\Throwable $error) {
                    ob_end_clean();
                    throw $error;
                }
                self::assertSame($criterion . " PASS\n", $output);
            }
        }
    }
}
