<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Mode;

/** Pure scheduling eligibility. An eligible result never performs publication. */
final class AutomationMode
{
    private const MODES = ['manual', 'assisted', 'pilot'];
    private const GATES = [
        'permission_granted', 'content_eligible', 'within_daily_cap',
        'within_time_window', 'minimum_separation_met', 'external_state_known',
        'approval_granted',
    ];

    /** @return array{status: string, reason: string, execution_performed: false} */
    public function assess(string $mode, array $context, bool $confirmed = false): array
    {
        if (!in_array($mode, self::MODES, true)
            || !self::identifier($context['tenant_id'] ?? null)
            || !self::identifier($context['account_id'] ?? null)
            || array_diff(array_keys($context), ['tenant_id', 'account_id', ...self::GATES]) !== []
            || count($context) !== count(self::GATES) + 2) {
            return self::result('blocked', 'invalid_context');
        }

        // All modes run the identical hard-gate loop before autonomy is considered.
        foreach (self::GATES as $gate) {
            if (!array_key_exists($gate, $context) || $context[$gate] !== true) {
                return self::result('blocked', 'hard_rule_denied');
            }
        }

        return match ($mode) {
            'manual' => self::result('manual_action_required', 'manual_mode'),
            'assisted' => $confirmed
                ? self::result('eligible', 'confirmation_verified')
                : self::result('confirmation_required', 'assisted_mode'),
            'pilot' => self::result('eligible', 'pilot_rules_verified'),
        };
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $value) === 1;
    }

    /** @return array{status: string, reason: string, execution_performed: false} */
    private static function result(string $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason, 'execution_performed' => false];
    }
}
