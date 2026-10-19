<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Resolution;

use InvalidArgumentException;

/**
 * Deterministic, tenant-scoped arbitration of collisions at exact UTC instants.
 * No database access, transport, grants or publication side effects.
 */
final class PriorityConflictResolver
{
    private const PRIORITY = ['normal' => 1, 'alta' => 2, 'urgente' => 3];

    /**
     * @param list<array<string, mixed>> $items
     * @return array{kept: list<string>, move: list<string>}
     */
    public function resolve(array $items): array
    {
        if (!array_is_list($items) || count($items) > 100) {
            throw new InvalidArgumentException('invalid_schedule');
        }
        $windows = new FlexibleWindow();
        $seen = [];
        $tenant = null;
        $groups = [];

        foreach ($items as $item) {
            if (!is_array($item) || $this->keys($item) !==
                ['id', 'priority', 'scheduled_at_utc', 'tenant_id', 'window']) {
                throw new InvalidArgumentException('invalid_schedule');
            }
            $id = $item['id'];
            $scope = $item['tenant_id'];
            if (!is_string($id) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $id) !== 1 ||
                !is_string($scope) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $scope) !== 1 ||
                !is_string($item['priority']) || !isset(self::PRIORITY[$item['priority']]) ||
                isset($seen[$id]) || ($tenant !== null && $tenant !== $scope) ||
                !is_array($item['window'])) {
                throw new InvalidArgumentException('invalid_schedule');
            }
            $tenant = $scope;
            $seen[$id] = true;
            $at = FlexibleWindow::instant($item['scheduled_at_utc']);
            $windows->candidates($item['window'], $at);
            $groups[$at][] = ['id' => $id, 'priority' => self::PRIORITY[$item['priority']]];
        }

        $kept = [];
        $move = [];
        foreach ($groups as $group) {
            usort($group, static function (array $left, array $right): int {
                $priority = $right['priority'] <=> $left['priority'];
                return $priority !== 0 ? $priority : strcmp($left['id'], $right['id']);
            });
            $kept[] = $group[0]['id'];
            foreach (array_slice($group, 1) as $loser) {
                $move[] = $loser['id'];
            }
        }
        sort($kept, SORT_STRING);
        sort($move, SORT_STRING);

        return ['kept' => $kept, 'move' => $move];
    }

    /** @param array<string,mixed> $value @return list<string> */
    private function keys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);
        return $keys;
    }
}
