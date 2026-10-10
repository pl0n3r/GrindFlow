<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Studio;

/** Pure, tenant-bound Studio inheritance; callers authenticate the creator. */
final class InheritedRuleSet
{
    private const RULE_KEYS = ['approval_required', 'daily_cap', 'minimum_gap_minutes'];

    public function resolve(string $tenantId, string $creatorId, ?array $studio = null, ?array $override = null): array
    {
        if (!self::identifier($tenantId) || !self::identifier($creatorId)) {
            return self::blocked();
        }
        $rules = [];
        $source = 'independent';
        if ($studio !== null) {
            if (array_keys($studio) !== ['tenant_id', 'studio_id', 'rules']
                || $studio['tenant_id'] !== $tenantId
                || !self::identifier($studio['studio_id'])
                || !self::validRules($studio['rules'])) {
                return self::blocked();
            }
            $rules = $studio['rules'];
            $source = 'studio';
        }
        if ($override !== null) {
            if (array_keys($override) !== ['tenant_id', 'creator_id', 'changes']
                || $override['tenant_id'] !== $tenantId
                || $override['creator_id'] !== $creatorId
                || !is_array($override['changes']) || array_is_list($override['changes'])
                || $override['changes'] === []
                || array_diff(array_keys($override['changes']), self::RULE_KEYS) !== []) {
                return self::blocked();
            }
            $rules = array_replace($rules, $override['changes']);
            $source = $studio === null ? 'independent' : 'creator_override';
        }
        if (!self::validRules($rules)) {
            return self::blocked();
        }
        return ['status' => 'resolved', 'source' => $source, 'rules' => $rules,
            'execution_performed' => false];
    }

    private static function validRules(mixed $rules): bool
    {
        if (!is_array($rules) || array_is_list($rules)) {
            return false;
        }
        $keys = array_keys($rules);
        sort($keys);
        return $keys === self::RULE_KEYS
            && is_int($rules['daily_cap']) && $rules['daily_cap'] >= 1 && $rules['daily_cap'] <= 100
            && is_int($rules['minimum_gap_minutes'])
            && $rules['minimum_gap_minutes'] >= 0 && $rules['minimum_gap_minutes'] <= 1440
            && is_bool($rules['approval_required']);
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $value) === 1;
    }

    private static function blocked(): array
    {
        return ['status' => 'blocked', 'reason' => 'invalid_scope_or_rules',
            'execution_performed' => false];
    }
}
