<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Templates;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Pure account/campaign/period calendar binding; never schedules externally. */
final class CalendarTemplate
{
    public function apply(array $pattern, array $target): array
    {
        $fields = ['tenant_id', 'template_id', 'scope', 'target_id', 'timezone',
            'local_time', 'weekdays', 'frequency_days', 'start_date', 'end_date'];
        $keys = array_keys($pattern);
        sort($keys);
        sort($fields);
        if ($keys !== $fields) {
            throw new InvalidArgumentException('invalid_template_shape');
        }

        $targetKey = match ($pattern['scope']) {
            'account' => 'account_id',
            'campaign' => 'campaign_id',
            'period' => 'period_id',
            default => null,
        };
        if ($targetKey === null || count($target) !== 2
            || !array_key_exists('tenant_id', $target)
            || !array_key_exists($targetKey, $target)
            || !self::id($pattern['tenant_id'])
            || !self::id($pattern['template_id'])
            || !self::id($pattern['target_id'])
            || !self::id($target['tenant_id'])
            || !self::id($target[$targetKey])
            || $target['tenant_id'] !== $pattern['tenant_id']
            || $target[$targetKey] !== $pattern['target_id']) {
            throw new InvalidArgumentException('invalid_template_scope');
        }

        if (!is_string($pattern['timezone'])
            || !in_array($pattern['timezone'], timezone_identifiers_list(), true)
            || !is_string($pattern['local_time'])
            || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $pattern['local_time']) !== 1
            || !is_array($pattern['weekdays']) || !array_is_list($pattern['weekdays'])
            || count($pattern['weekdays']) < 1 || count($pattern['weekdays']) > 7
            || count(array_unique($pattern['weekdays'], SORT_REGULAR)) !== count($pattern['weekdays'])
            || !is_int($pattern['frequency_days']) || $pattern['frequency_days'] < 1
            || $pattern['frequency_days'] > 90) {
            throw new InvalidArgumentException('invalid_template_schedule');
        }
        foreach ($pattern['weekdays'] as $day) {
            if (!is_int($day) || $day < 1 || $day > 7) {
                throw new InvalidArgumentException('invalid_template_weekdays');
            }
        }
        if (!self::date($pattern['start_date'])
            || ($pattern['end_date'] !== null && (!self::date($pattern['end_date'])
                || $pattern['end_date'] < $pattern['start_date']))) {
            throw new InvalidArgumentException('invalid_template_dates');
        }

        $days = $pattern['weekdays'];
        sort($days, SORT_NUMERIC);
        return [
            'tenant_id' => $pattern['tenant_id'],
            'template_id' => $pattern['template_id'],
            'scope' => $pattern['scope'],
            'scope_id' => $pattern['target_id'],
            'timezone' => $pattern['timezone'],
            'local_time' => $pattern['local_time'],
            'weekdays' => $days,
            'frequency_days' => $pattern['frequency_days'],
            'start_date' => $pattern['start_date'],
            'end_date' => $pattern['end_date'],
        ];
    }

    public static function id(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $value) === 1;
    }

    public static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
