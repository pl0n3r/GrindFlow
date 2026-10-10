<?php
declare(strict_types=1);

namespace GrindFlow\Scheduling\Preview;

use InvalidArgumentException;

final class GlobalPauseState
{
    /** The agenda stays outside the state; only a stable digest is retained. */
    public static function initial(array $agenda): array
    {
        return [
            'version' => 1, 'paused' => false, 'revision' => 0,
            'agenda_sha256' => self::digest($agenda), 'audit' => [],
        ];
    }

    public static function transition(array $state, string $action, string $reasonCode, int $at): array
    {
        self::validate($state);
        if (!in_array($action, ['pause', 'resume'], true)
            || !in_array($reasonCode, ['manual', 'maintenance', 'incident', 'recovered'], true)
            || $at < 1 || count($state['audit']) >= 32) {
            throw new InvalidArgumentException('invalid_pause_transition');
        }
        $nextPaused = $action === 'pause';
        if ($state['paused'] === $nextPaused) {
            throw new InvalidArgumentException('duplicate_pause_transition');
        }
        $last = $state['audit'] === [] ? 0 : $state['audit'][count($state['audit']) - 1]['at'];
        if ($at <= $last) {
            throw new InvalidArgumentException('out_of_order_pause_transition');
        }
        $copy = $state;
        $copy['paused'] = $nextPaused;
        ++$copy['revision'];
        $copy['audit'][] = [
            'revision' => $copy['revision'], 'action' => $action,
            'reason_code' => $reasonCode, 'at' => $at,
        ];
        return $copy;
    }

    public static function canRun(array $state): bool
    {
        self::validate($state);
        return !$state['paused'];
    }

    /** Only verifies equality of snapshots, never authorizes scheduling. */
    public static function agendaUnchanged(array $state, array $agenda): bool
    {
        self::validate($state);
        return hash_equals($state['agenda_sha256'], self::digest($agenda));
    }

    private static function validate(array $state): void
    {
        $fields = ['version', 'paused', 'revision', 'agenda_sha256', 'audit'];
        if (array_keys($state) !== $fields || $state['version'] !== 1
            || !is_bool($state['paused']) || !is_int($state['revision'])
            || $state['revision'] < 0 || !is_string($state['agenda_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $state['agenda_sha256']) !== 1
            || !is_array($state['audit']) || !array_is_list($state['audit'])
            || count($state['audit']) !== $state['revision'] || count($state['audit']) > 32
            || ($state['revision'] === 0 && $state['paused'])) {
            throw new InvalidArgumentException('invalid_pause_state');
        }
        $last = 0;
        foreach ($state['audit'] as $index => $event) {
            if (!is_array($event)
                || array_keys($event) !== ['revision', 'action', 'reason_code', 'at']
                || $event['revision'] !== $index + 1
                || $event['action'] !== ($index % 2 === 0 ? 'pause' : 'resume')
                || !in_array($event['action'], ['pause', 'resume'], true)
                || !in_array($event['reason_code'], ['manual', 'maintenance', 'incident', 'recovered'], true)
                || !is_int($event['at']) || $event['at'] <= $last) {
                throw new InvalidArgumentException('invalid_pause_audit');
            }
            $last = $event['at'];
        }
        if ($state['audit'] !== [] && $state['paused'] !== (end($state['audit'])['action'] === 'pause')) {
            throw new InvalidArgumentException('invalid_pause_state');
        }
    }

    private static function digest(array $agenda): string
    {
        if (count($agenda) > 1000) {
            throw new InvalidArgumentException('agenda_too_large');
        }
        try {
            return hash('sha256', json_encode($agenda, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new InvalidArgumentException('invalid_agenda');
        }
    }
}
