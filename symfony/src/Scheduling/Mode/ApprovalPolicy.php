<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Mode;

/** Pure decision projection. The caller MUST verify approver membership and identity. */
final class ApprovalPolicy
{
    private const ROLES = ['owner', 'editor', 'producer', 'reviewer'];

    /** @return array{status: string, approved: bool, pending_roles: array, audit: array} */
    public function decide(array $request, array $responses, int $now): array
    {
        $shape = ['tenant_id', 'creator_id', 'profile', 'approval_required',
            'required_roles', 'expires_at'];
        if (!self::shape($request, $shape)
            || !self::id($request['tenant_id']) || !self::id($request['creator_id'])
            || !in_array($request['profile'], ['individual', 'studio'], true)
            || !is_bool($request['approval_required'])
            || !is_int($request['expires_at']) || $request['expires_at'] <= 0
            || $now < 0 || !is_array($responses) || !array_is_list($responses)
            || count($responses) > 16 || !is_array($request['required_roles'])
            || !array_is_list($request['required_roles'])
            || count($request['required_roles']) > 4) {
            return self::result('invalid');
        }
        $roles = $request['required_roles'];
        // Reject untrusted nested/non-string roles before array_diff string casts.
        // An array/object role must fail closed without PHP warnings or TypeErrors.
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, self::ROLES, true)) {
                return self::result('invalid');
            }
        }
        if (count(array_unique($roles, SORT_REGULAR)) !== count($roles)
            || array_diff($roles, self::ROLES) !== []
            || ($request['profile'] === 'individual' && ($roles !== [] && $roles !== ['owner']))
            || ($request['profile'] === 'studio' && (!$request['approval_required'] || $roles === []))
            || ($request['approval_required'] && $roles === [])
            || (!$request['approval_required'] && ($roles !== [] || $responses !== []))) {
            return self::result('invalid');
        }
        if ($request['expires_at'] <= $now) {
            return self::result('expired');
        }
        if (!$request['approval_required']) {
            return self::result('not_required', true);
        }

        $seen = [];
        $audit = [];
        foreach ($responses as $response) {
            if (!self::shape($response, ['tenant_id', 'creator_id', 'role',
                    'decision', 'role_verified', 'at'])
                || $response['tenant_id'] !== $request['tenant_id']
                || $response['creator_id'] !== $request['creator_id']
                || !in_array($response['role'], $roles, true)
                || !in_array($response['decision'], ['approve', 'reject'], true)
                || $response['role_verified'] !== true
                || !is_int($response['at']) || $response['at'] < 0
                || $response['at'] > $now || isset($seen[$response['role']])) {
                return self::result('invalid');
            }
            $seen[$response['role']] = $response['decision'];
            $audit[] = ['role' => $response['role'],
                'state' => $response['decision'] === 'approve' ? 'approved' : 'rejected',
                'at' => $response['at']];
        }
        if (in_array('reject', $seen, true)) {
            return self::result('rejected', false, [], $audit);
        }
        $pending = array_values(array_diff($roles, array_keys($seen)));
        sort($pending);
        return self::result($pending === [] ? 'approved' : 'pending',
            $pending === [], $pending, $audit);
    }

    private static function shape(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    private static function id(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $value) === 1;
    }

    /** @return array{status: string, approved: bool, pending_roles: array, audit: array} */
    private static function result(string $status, bool $approved = false,
        array $pending = [], array $audit = []): array
    {
        return ['status' => $status, 'approved' => $approved,
            'pending_roles' => $pending, 'audit' => $audit];
    }
}
