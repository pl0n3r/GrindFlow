<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Scheduling/Studio/InheritedRuleSet.php';
require dirname(__DIR__, 2) . '/src/Scheduling/Studio/StudioCalendarView.php';
require dirname(__DIR__, 2) . '/src/Scheduling/Studio/TeamCapacityAnalyzer.php';

use GrindFlow\Scheduling\Studio\InheritedRuleSet;
use GrindFlow\Scheduling\Studio\StudioCalendarView;
use GrindFlow\Scheduling\Studio\TeamCapacityAnalyzer;

function check(bool $ok, string $name): void
{
    if (!$ok) { throw new RuntimeException('test_failed:' . $name); }
}
function rules(): array
{
    return ['daily_cap' => 5, 'minimum_gap_minutes' => 30, 'approval_required' => true];
}
function studio(): array
{
    return ['tenant_id' => 'tenant_1', 'studio_id' => 'studio_1', 'rules' => rules()];
}
function override(array $changes): array
{
    return ['tenant_id' => 'tenant_1', 'creator_id' => 'creator_1', 'changes' => $changes];
}
function slot(string $id, string $creator = 'creator_1'): array
{
    return ['tenant_id' => 'tenant_1', 'creator_id' => $creator,
        'slot_id' => $id, 'day' => '2026-10-12', 'state' => 'awaiting_approval'];
}
function task(string $member, string $stage): array
{
    return ['tenant_id' => 'tenant_1', 'creator_id' => 'creator_1',
        'member_id' => $member, 'stage' => $stage, 'day' => '2026-10-12'];
}
$tests = [
    'inheritance' => static function (): void {
        $r = new InheritedRuleSet();
        $original = studio();
        $base = $r->resolve('tenant_1', 'creator_1', $original);
        check($base['status'] === 'resolved' && $base['source'] === 'studio'
            && $base['rules'] === rules(), 'inherited_without_mutation');
        $new = $r->resolve('tenant_1', 'creator_1', $original, override(['daily_cap' => 2]));
        check($new['status'] === 'resolved' && $new['source'] === 'creator_override'
            && $new['rules']['daily_cap'] === 2 && $new['rules']['approval_required'] === true,
            'explicit_override');
        check($original['rules'] === rules(), 'studio_unmodified');
        // Associative input maps may have any field order without losing validation.
        $shuffledStudio = array_reverse(studio(), true);
        $shuffledOverride = array_reverse(override(['daily_cap' => 2]), true);
        check($r->resolve('tenant_1', 'creator_1', $shuffledStudio, $shuffledOverride)['status']
            === 'resolved', 'reordered_studio_and_override');
        $extraStudio = studio(); $extraStudio['unexpected'] = true;
        check($r->resolve('tenant_1', 'creator_1', $extraStudio)['status'] === 'blocked',
            'additional_studio_key_rejected');
        $wrong = override(['daily_cap' => 2]); $wrong['creator_id'] = 'creator_2';
        check($r->resolve('tenant_1', 'creator_1', studio(), $wrong)['status'] === 'blocked', 'creator_scope');
        $wrong = override(['daily_cap' => 2]); $wrong['tenant_id'] = 'tenant_2';
        check($r->resolve('tenant_1', 'creator_1', studio(), $wrong)['status'] === 'blocked', 'tenant_scope');
        check($r->resolve('tenant_1', 'creator_1', studio(), override(['secret' => 'x']))['status'] === 'blocked', 'unknown_override');
        check($r->resolve('tenant_1', 'creator_1', studio(), override(['daily_cap' => '2']))['status'] === 'blocked', 'strict_types');
        check($r->resolve('tenant_1', 'creator_1', null)['status'] === 'blocked', 'no_implicit_rules');
    },
    'calendar' => static function (): void {
        $v = new StudioCalendarView();
        $ok = $v->aggregate('tenant_1', ['creator_1', 'creator_2'], [slot('s1'), slot('s2', 'creator_2')]);
        check($ok['status'] === 'ok' && $ok['total'] === 2
            && $ok['by_creator']['creator_1']['awaiting_approval'] === 1
            && $ok['by_creator']['creator_2']['awaiting_approval'] === 1,
            'authorized_aggregation');
        check($v->aggregate('tenant_1', ['creator_1'], [array_reverse(slot('s6'), true)])['status']
            === 'ok', 'reordered_slot_fields');
        $foreign = slot('s3'); $foreign['tenant_id'] = 'tenant_2';
        check($v->aggregate('tenant_1', ['creator_1'], [$foreign])['status'] === 'blocked', 'foreign_tenant');
        check($v->aggregate('tenant_1', ['creator_1'], [slot('s4', 'creator_2')])['status'] === 'blocked', 'foreign_creator');
        check($v->aggregate('tenant_1', ['creator_1'], [slot('same'), slot('same')])['status'] === 'blocked', 'duplicate_slot');
        check($v->aggregate('tenant_1', ['creator_1'], [slot('s5'), ['secret' => 'x']])['status'] === 'blocked', 'unknown_row');
    },
    'capacity' => static function (): void {
        $a = new TeamCapacityAnalyzer();
        $members = [['tenant_id' => 'tenant_1', 'member_id' => 'editor_1', 'daily_capacity' => 2]];
        $tasks = [task('editor_1', 'preparation'), task('editor_1', 'approval'), task('editor_1', 'approval')];
        $v = $a->analyze('tenant_1', $members, $tasks);
        check($v['status'] === 'ok' && $v['task_count'] === 3
            && count($v['bottlenecks']) === 1 && $v['bottlenecks'][0]['preparation'] === 1
            && $v['bottlenecks'][0]['approval'] === 2
            && $v['bottlenecks'][0]['total'] === 3, 'stage_specific_bottleneck');
        // AC-03: the editor/day total must also identify per-creator pressure.
        $mixed = [task('editor_1', 'preparation'), task('editor_1', 'approval'),
            task('editor_1', 'approval')];
        $mixed[1]['creator_id'] = 'creator_2';
        $mixed[2]['creator_id'] = 'creator_2';
        $multi = $a->analyze('tenant_1', $members, $mixed);
        $overload = $multi['bottlenecks'][0] ?? [];
        check($multi['status'] === 'ok'
            && isset($overload['by_creator']['creator_1'], $overload['by_creator']['creator_2'])
            && $overload['by_creator']['creator_1'] === ['preparation' => 1, 'approval' => 0]
            && $overload['by_creator']['creator_2'] === ['preparation' => 0, 'approval' => 2]
            && $overload['total'] === 3, 'creator_breakdown_of_daily_bottleneck');
        $reversed = $a->analyze('tenant_1', $members, array_reverse($mixed));
        check($reversed['bottlenecks'] === $multi['bottlenecks'],
            'stable_breakdown_for_reordered_tasks');
        $tomorrow = task('editor_1', 'approval');
        $tomorrow['day'] = '2026-10-13';
        check($a->analyze('tenant_1', $members, [$mixed[0], $mixed[1], $tomorrow])['bottlenecks'] === [],
            'daily_capacity_not_combined_across_days');
        check($a->analyze('tenant_1', $members, array_slice($tasks, 0, 2))['bottlenecks'] === [], 'within_capacity');
        check($a->analyze('tenant_1', [array_reverse($members[0], true)],
            [array_reverse(task('editor_1', 'approval'), true)])['status'] === 'ok',
            'reordered_member_and_task');
        $bad = task('editor_1', 'approval'); $bad['tenant_id'] = 'tenant_2';
        check($a->analyze('tenant_1', $members, [$bad])['status'] === 'blocked', 'tenant_scoped');
        check($a->analyze('tenant_1', [], [task('editor_1', 'preparation')])['status'] === 'blocked', 'unknown_assignment');
        $zero = [['tenant_id' => 'tenant_1', 'member_id' => 'editor_1', 'daily_capacity' => 0]];
        check(count($a->analyze('tenant_1', $zero, [task('editor_1', 'approval')])['bottlenecks']) === 1, 'no_capacity');
    },
    'independent' => static function (): void {
        $r = new InheritedRuleSet();
        $independent = $r->resolve('tenant_1', 'creator_1', null, override(rules()));
        check($independent['status'] === 'resolved' && $independent['source'] === 'independent'
            && $independent['execution_performed'] === false, 'independent_no_studio');
        $view = (new StudioCalendarView())->aggregate('tenant_1', ['creator_1'], [slot('s1')]);
        check($view['status'] === 'ok' && count($view['by_creator']) === 1, 'independent_calendar');
        $cap = (new TeamCapacityAnalyzer())->analyze('tenant_1', [], []);
        check($cap['status'] === 'ok' && $cap['bottlenecks'] === []
            && $cap['execution_performed'] === false, 'independent_no_members');
    },
];
$name = $argv[1] ?? null;
if ($name !== null && !isset($tests[$name])) { fwrite(STDERR, "unknown_case\n"); exit(2); }
foreach ($tests as $id => $test) {
    if ($name === null || $name === $id) { $test(); echo "PASS {$id}\n"; }
}
