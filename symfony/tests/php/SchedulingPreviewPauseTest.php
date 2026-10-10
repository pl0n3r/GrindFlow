<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/src/Scheduling/Preview/BulkSchedulePreview.php';
require_once dirname(__DIR__, 2).'/src/Scheduling/Preview/GlobalPauseState.php';

use GrindFlow\Scheduling\Preview\BulkSchedulePreview;
use GrindFlow\Scheduling\Preview\GlobalPauseState;

final class PreviewPauseScenario
{
    private static function check(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('scheduling_preview_contract_failure');
        }
    }

    private static function denies(callable $operation): void
    {
        try {
            $operation();
        } catch (InvalidArgumentException) {
            return;
        }
        throw new RuntimeException('unsafe_schedule_operation_accepted');
    }

    public static function run(string $scenario): array
    {
        $base = [
            ['tenant_id' => 'tenant_one', 'id' => 'slot_one', 'starts_at_utc' => '2026-10-11T08:00:00Z'],
            ['tenant_id' => 'tenant_one', 'id' => 'slot_two', 'starts_at_utc' => '2026-10-11T09:00:00Z'],
        ];
        $changes = [
            ['kind' => 'move', 'tenant_id' => 'tenant_one', 'id' => 'slot_one', 'starts_at_utc' => '2026-10-11T10:00:00Z'],
            ['kind' => 'create', 'tenant_id' => 'tenant_one', 'id' => 'slot_three', 'starts_at_utc' => '2026-10-11T08:00:00Z'],
        ];
        if ($scenario === 'preview') {
            $original = serialize([$base, $changes]);
            $ready = BulkSchedulePreview::build('tenant_one', $base, $changes);
            self::check($ready['status'] === 'ready' && $ready['execution'] === false);
            self::check(count($ready['creates']) === 1 && count($ready['moves']) === 1);
            self::check($ready['conflicts'] === [] && serialize([$base, $changes]) === $original);
            $conflicted = $changes;
            $conflicted[] = ['kind' => 'create', 'tenant_id' => 'tenant_one', 'id' => 'slot_four', 'starts_at_utc' => '2026-10-11T09:00:00Z'];
            $result = BulkSchedulePreview::build('tenant_one', $base, $conflicted);
            self::check($result['status'] === 'conflict' && count($result['conflicts']) === 1);
            return ['case' => $scenario, 'status' => 'pass', 'conflicts' => count($result['conflicts'])];
        }
        if ($scenario === 'mismatch') {
            $preview = BulkSchedulePreview::build('tenant_one', $base, $changes);
            self::check(BulkSchedulePreview::matches('tenant_one', $base, $changes, $preview));
            $different = $changes;
            $different[0]['starts_at_utc'] = '2026-10-11T11:00:00Z';
            self::check(!BulkSchedulePreview::matches('tenant_one', $base, $different, $preview));
            self::check(!BulkSchedulePreview::matches('tenant_two', $base, $changes, $preview));
            $tampered = $preview;
            $tampered['execution'] = true;
            self::check(!BulkSchedulePreview::matches('tenant_one', $base, $changes, $tampered));
            $foreign = $base;
            $foreign[0]['tenant_id'] = 'tenant_two';
            self::denies(static fn() => BulkSchedulePreview::build('tenant_one', $foreign, $changes));
            return ['case' => $scenario, 'status' => 'pass'];
        }
        if ($scenario === 'pause') {
            $original = serialize($base);
            $initial = GlobalPauseState::initial($base);
            self::check(GlobalPauseState::canRun($initial));
            $paused = GlobalPauseState::transition($initial, 'pause', 'maintenance', 100);
            self::check(!GlobalPauseState::canRun($paused) && GlobalPauseState::canRun($initial));
            $resumed = GlobalPauseState::transition($paused, 'resume', 'recovered', 101);
            self::check(GlobalPauseState::canRun($resumed) && $resumed['revision'] === 2);
            self::check(GlobalPauseState::agendaUnchanged($resumed, $base));
            self::check($resumed['agenda_sha256'] === $initial['agenda_sha256'] && serialize($base) === $original);
            self::denies(static fn() => GlobalPauseState::transition($paused, 'pause', 'manual', 102));
            return ['case' => $scenario, 'status' => 'pass'];
        }
        if ($scenario === 'audit') {
            $paused = GlobalPauseState::transition(GlobalPauseState::initial($base), 'pause', 'incident', 900);
            self::check(array_keys($paused['audit'][0]) === ['revision', 'action', 'reason_code', 'at']);
            self::denies(static fn() => GlobalPauseState::transition($paused, 'resume', 'mail@private.test', 901));
            self::denies(static fn() => GlobalPauseState::transition($paused, 'resume', 'recovered', 899));
            $forged = $paused;
            $forged['audit'][0]['secret'] = 'private';
            self::denies(static fn() => GlobalPauseState::canRun($forged));
            $brokenSequence = $paused;
            $brokenSequence['revision'] = 2;
            $brokenSequence['audit'][] = ['revision' => 2, 'action' => 'pause', 'reason_code' => 'manual', 'at' => 901];
            self::denies(static fn() => GlobalPauseState::canRun($brokenSequence));
            $invalidInitial = GlobalPauseState::initial($base);
            $invalidInitial['paused'] = true;
            self::denies(static fn() => GlobalPauseState::canRun($invalidInitial));
            self::check(!str_contains(json_encode($paused, JSON_THROW_ON_ERROR), 'private'));
            return ['case' => $scenario, 'status' => 'pass'];
        }
        throw new InvalidArgumentException('unknown_scenario');
    }
}

if (class_exists(\PHPUnit\Framework\TestCase::class)) {
    final class SchedulingPreviewPauseTest extends \PHPUnit\Framework\TestCase
    {
        public function testPreview(): void { self::assertSame('pass', PreviewPauseScenario::run('preview')['status']); }
        public function testMismatch(): void { self::assertSame('pass', PreviewPauseScenario::run('mismatch')['status']); }
        public function testPause(): void { self::assertSame('pass', PreviewPauseScenario::run('pause')['status']); }
        public function testAudit(): void { self::assertSame('pass', PreviewPauseScenario::run('audit')['status']); }
    }
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        echo json_encode(PreviewPauseScenario::run((string) ($argv[1] ?? '')), JSON_THROW_ON_ERROR), PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, 'scheduling_preview_contract_failure'.PHP_EOL);
        exit(1);
    }
}
