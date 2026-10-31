<?php

declare(strict_types=1);

namespace GrindFlow\Plans;

use InvalidArgumentException;

/** A per-plan read-only catalog. Values are owner-configured, never invented. */
final class PlanCatalog
{
    public const DIMENSIONS = ['storage_bytes', 'traffic_bytes', 'queued_jobs', 'api_calls'];

    /** @var array<string, array<string, ?int>> */
    private array $plans = [];

    /** @param array<string, array<string, ?int>> $plans */
    public function __construct(array $plans = [])
    {
        foreach ($plans as $plan => $limits) {
            $this->configure($plan, $limits);
        }
    }

    /**
     * Configure a full or partial plan; absent dimensions are UNKNOWN.
     *
     * @param array<string, ?int> $limits
     */
    public function configure(string $plan, array $limits): void
    {
        self::validateIdentifier($plan);
        $normalized = array_fill_keys(self::DIMENSIONS, null);
        foreach ($limits as $dimension => $limit) {
            if (!is_string($dimension) || !array_key_exists($dimension, $normalized)) {
                throw new InvalidArgumentException('Unknown plan limit dimension.');
            }
            if ($limit !== null && (!is_int($limit) || $limit < 0)) {
                throw new InvalidArgumentException('Plan limit must be a non-negative integer or null.');
            }
            $normalized[$dimension] = $limit;
        }
        $this->plans[$plan] = $normalized;
    }

    /**
     * Evaluate caller-supplied usage for one organization. The caller must obtain
     * usage and plan from the same authorized tenant; no enforcement or I/O here.
     *
     * @param array<string, ?int> $consumption
     * @return array{organization_id: string, plan: string, status: string, within_limits: ?bool, limits: array<string, array<string, mixed>>}
     */
    public function evaluate(string $organizationId, string $plan, array $consumption): array
    {
        self::validateIdentifier($organizationId);
        self::validateIdentifier($plan);
        $usage = array_fill_keys(self::DIMENSIONS, null);
        foreach ($consumption as $dimension => $used) {
            if (!is_string($dimension) || !array_key_exists($dimension, $usage)) {
                throw new InvalidArgumentException('Unknown plan consumption dimension.');
            }
            if ($used !== null && (!is_int($used) || $used < 0)) {
                throw new InvalidArgumentException('Plan consumption must be a non-negative integer or null.');
            }
            $usage[$dimension] = $used;
        }

        $limits = [];
        $hasUnknown = false;
        $exceeded = false;
        foreach (self::DIMENSIONS as $dimension) {
            $quota = new PlanLimit($dimension, $this->plans[$plan][$dimension] ?? null);
            $result = $quota->evaluate($usage[$dimension]);
            $limits[$dimension] = $result;
            $hasUnknown = $hasUnknown || $result['within_limit'] === null;
            $exceeded = $exceeded || $result['within_limit'] === false;
        }

        return [
            'organization_id' => $organizationId,
            'plan' => $plan,
            'status' => $exceeded ? 'exceeded' : ($hasUnknown ? 'unknown' : 'within'),
            'within_limits' => $exceeded ? false : ($hasUnknown ? null : true),
            'limits' => $limits,
        ];
    }

    private static function validateIdentifier(string $identifier): void
    {
        if ($identifier === '' || trim($identifier) !== $identifier || strlen($identifier) > 128) {
            throw new InvalidArgumentException('Plan and organization identifiers must be non-empty and canonical.');
        }
    }
}
