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
        foreach ($losers as $id) {
            $item = $byId[$id];
            $candidate = $windows->choose(
                $item['window'], $item['scheduled_at_utc'], $occupied
            );
            if ($candidate === null) {
                throw new DomainException('no_safe_slot');
            }
            $occupied[] = $candidate;
            $output[$id] = [
                'id' => $id, 'scheduled_at_utc' => $candidate, 'moved' => true
            ];
        }

        ksort($output, SORT_STRING);
        return array_values($output);
    }
}
