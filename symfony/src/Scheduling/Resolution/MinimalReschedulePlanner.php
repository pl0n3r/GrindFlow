<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Resolution;

use DomainException;

/**
 * Minimal-change proposal: lock every initially collision-free/winning slot
 * before relocating losers. Never changes already unique reservations.
 */
final class MinimalReschedulePlanner
{
    /** @param list<array<string,mixed>> $items
     *  @return list<array{id:string,scheduled_at_utc:string,moved:bool}>
     */
    public function plan(array $items): array
    {
        $decision = (new PriorityConflictResolver())->resolve($items);
        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }

        $output = [];
        $occupied = [];
        foreach ($decision['kept'] as $id) {
            $at = $byId[$id]['scheduled_at_utc'];
            $occupied[] = $at;
            $output[$id] = ['id' => $id, 'scheduled_at_utc' => $at, 'moved' => false];
        }
        $losers = $decision['move'];
        $priority = ['normal' => 1, 'alta' => 2, 'urgente' => 3];
        usort($losers, static function (string $a, string $b) use ($byId, $priority): int {
            $rank = $priority[$byId[$b]['priority']] <=> $priority[$byId[$a]['priority']];
            return $rank !== 0 ? $rank : strcmp($a, $b);
        });

        $windows = new FlexibleWindow();
        // Winners and already-unique slots are immutable. Compute all loser
        // options first so a flexible loser cannot steal another's only slot.
        $locked = array_fill_keys($occupied, true);
        $choices = [];
        foreach ($losers as $id) {
            $item = $byId[$id];
            $choices[$id] = array_values(array_filter(
                $windows->candidates($item['window'], $item['scheduled_at_utc']),
                static fn (string $instant): bool => !isset($locked[$instant])
            ));
            if ($choices[$id] === []) {
                throw new DomainException('no_safe_slot');
            }
        }
        // Maximum bipartite matching via bounded augmenting paths. Each
        // loser moves once; no winner or initially unique slot is modified.
        $slotOwners = [];
        $assignments = [];
        foreach ($losers as $id) {
            $seen = [];
            if (!self::assignSlot($id, $choices, $slotOwners, $assignments, $seen)) {
                throw new DomainException('no_safe_slot');
            }
        }
        foreach ($losers as $id) {
            $output[$id] = [
                'id' => $id, 'scheduled_at_utc' => $assignments[$id], 'moved' => true
            ];
        }

        ksort($output, SORT_STRING);
        return array_values($output);
    }

    /**
     * Bounded augmenting path: if a candidate is owned by an earlier loser,
     * move that loser to another allowed slot before declaring no_safe_slot.
     * @param array<string, list<string>> $choices
     * @param array<string, string> $slotOwners
     * @param array<string, string> $assignments
     * @param array<string, bool> $seen
     */
    private static function assignSlot(
        string $id,
        array $choices,
        array &$slotOwners,
        array &$assignments,
        array &$seen
    ): bool {
        // An available slot of the current loser is always preferable to
        // needlessly relocating an already placed (possibly higher-priority)
        // loser. Only attempt an augmenting displacement when none is free.
        foreach ($choices[$id] as $instant) {
            if (!isset($seen[$instant]) && !isset($slotOwners[$instant])) {
                $seen[$instant] = true;
                $slotOwners[$instant] = $id;
                $assignments[$id] = $instant;
                return true;
            }
        }
        foreach ($choices[$id] as $instant) {
            if (isset($seen[$instant])) {
                continue;
            }
            $seen[$instant] = true;
            if (isset($slotOwners[$instant])
                && self::assignSlot($slotOwners[$instant], $choices, $slotOwners, $assignments, $seen)) {
                $slotOwners[$instant] = $id;
                $assignments[$id] = $instant;
                return true;
            }
        }
        return false;
    }
}
