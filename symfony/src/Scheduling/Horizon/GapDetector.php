<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Horizon;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Pure, tenant-scoped inspection of calendar capacity; no writes or provider I/O. */
final class GapDetector
{
    /**
     * Slots are ordinal positions (1..slotsPerDay), not wall-clock hours.
     *
     * @param list<array{tenant_id:string,date:string,slot:int,status?:string}> $scheduled
     * @param list<string> $blockedDates
     * @param list<array{date:string,slot:int}> $blockedSlots
     * @return array{status:string,reason:string,gaps:list<array{date:string,slot:int}>,required:int,scheduled:int}
     */
    public function detect(
        string $tenantId,
        string $timezone,
        string $startDate,
        int $days,
        int $slotsPerDay,
        array $scheduled,
        array $blockedDates = [],
        array $blockedSlots = [],
        bool $permitted = true,
    ): array {
        if (!$permitted || trim($tenantId) === '' || strlen($tenantId) > 128
            || $days < 1 || $days > 365 || $slotsPerDay < 1 || $slotsPerDay > 24
            || !array_is_list($scheduled) || count($scheduled) > 10000
            || !array_is_list($blockedDates) || count($blockedDates) > 365
            || !array_is_list($blockedSlots) || count($blockedSlots) > 8760) {
            return self::rejected('invalid_or_unauthorized');
        }

        try {
            $zone = new DateTimeZone($timezone);
            $start = self::parseDate($startDate, $zone);
            if ($start === null) {
                return self::rejected('invalid_date');
            }
        } catch (Throwable) {
            return self::rejected('invalid_timezone');
        }

        $blocked = [];
        foreach ($blockedDates as $date) {
            if (!is_string($date) || self::parseDate($date, $zone) === null) {
                return self::rejected('invalid_block');
            }
            $blocked[$date] = true;
        }
        $blockedPositions = [];
        foreach ($blockedSlots as $slot) {
            if (!is_array($slot) || !isset($slot['date'], $slot['slot'])
                || !is_string($slot['date']) || self::parseDate($slot['date'], $zone) === null
                || !is_int($slot['slot']) || $slot['slot'] < 1 || $slot['slot'] > $slotsPerDay) {
                return self::rejected('invalid_block');
            }
            $blockedPositions[$slot['date'] . ':' . $slot['slot']] = true;
        }

        $occupancy = [];
        foreach ($scheduled as $entry) {
            if (!is_array($entry) || !isset($entry['tenant_id'], $entry['date'], $entry['slot'])
                || !is_string($entry['tenant_id']) || trim($entry['tenant_id']) === ''
                || !is_string($entry['date']) || self::parseDate($entry['date'], $zone) === null
                || !is_int($entry['slot']) || $entry['slot'] < 1 || $entry['slot'] > $slotsPerDay) {
                return self::rejected('invalid_schedule');
            }
            $status = $entry['status'] ?? 'scheduled';
            if (!in_array($status, ['scheduled', 'draft'], true)) {
                return self::rejected('uncertain_schedule');
            }
            if ($entry['tenant_id'] !== $tenantId) {
                continue;
            }
            $occupancy[$entry['date'] . ':' . $entry['slot']] = true;
        }

        $gaps = [];
        $required = 0;
        $filled = 0;
        for ($offset = 0; $offset < $days; ++$offset) {
            // Calendar arithmetic in the supplied IANA zone stays correct across DST.
            $date = $start->modify('+' . $offset . ' days')->format('Y-m-d');
            if (isset($blocked[$date])) {
                continue;
            }
            for ($slot = 1; $slot <= $slotsPerDay; ++$slot) {
                $key = $date . ':' . $slot;
                if (isset($blockedPositions[$key])) {
                    continue;
                }
                ++$required;
                if (isset($occupancy[$key])) {
                    ++$filled;
                } else {
                    $gaps[] = ['date' => $date, 'slot' => $slot];
                }
            }
        }

        return ['status' => 'ok', 'reason' => 'observed', 'gaps' => $gaps,
            'required' => $required, 'scheduled' => $filled];
    }

    private static function parseDate(string $date, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    /** @return array{status:string,reason:string,gaps:list<never>,required:int,scheduled:int} */
    private static function rejected(string $reason): array
    {
        return ['status' => 'rejected', 'reason' => $reason, 'gaps' => [],
            'required' => 0, 'scheduled' => 0];
    }
}
