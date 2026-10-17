<?php

declare(strict_types=1);

namespace GrindFlow\Library;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Audited Library asset lifecycle (#44).
 *
 * Seven persisted states plus `expired`, which is always derived from the UTC
 * validity window (#41) and never stored. Only the transitions below are valid;
 * every attempt, applied or rejected, leaves an audit event. A state only
 * restricts scheduling: it is never a license or a publication grant.
 */
final readonly class AssetLifecycle
{
    public const STATES = ['new', 'ready', 'in_review', 'in_use', 'paused', 'blocked', 'archived'];
    public const DERIVED = 'expired';
    public const DEFAULT_STATE = 'new';

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'new' => ['ready', 'in_review', 'blocked', 'archived'],
        'ready' => ['in_review', 'in_use', 'paused', 'blocked', 'archived'],
        'in_review' => ['ready', 'blocked', 'archived'],
        'in_use' => ['ready', 'paused', 'blocked', 'archived'],
        'paused' => ['ready', 'in_use', 'blocked', 'archived'],
        'blocked' => ['ready', 'in_review', 'archived'],
        'archived' => ['ready'],
    ];

    private const EXCLUDING = [
        'paused' => 'lifecycle_paused',
        'blocked' => 'lifecycle_blocked',
        'archived' => 'lifecycle_archived',
    ];

    public function __construct(private Connection $db)
    {
    }

    /** Effective state: `expired` overrides everything except an archived asset; unknown persisted values fall back to the default. */
    public static function effectiveState(mixed $persisted, string $validityState): string
    {
        $state = is_string($persisted) && in_array($persisted, self::STATES, true) ? $persisted : self::DEFAULT_STATE;

        return $validityState === 'expired' && $state !== 'archived' ? self::DERIVED : $state;
    }

    /** @return list<string> stable scheduling-exclusion reasons for a persisted state */
    public static function blockingReasons(mixed $persisted): array
    {
        if (!is_string($persisted) || !in_array($persisted, self::STATES, true)) {
            // Unknown value: fail closed.
            return ['lifecycle_blocked'];
        }

        return isset(self::EXCLUDING[$persisted]) ? [self::EXCLUDING[$persisted]] : [];
    }

    /** @return list<string> */
    public static function allowedNext(mixed $persisted): array
    {
        return is_string($persisted) ? (self::TRANSITIONS[$persisted] ?? []) : [];
    }

    /**
     * Apply or reject one transition under the asset lock and audit it.
     *
     * @return array{status: 'applied'|'rejected'|'missing', from?: string, to?: string, reason?: string}
     */
    public function transition(string $organizationId, string $assetId, string $actorId, string $to): array
    {
        if (!in_array($to, [...self::STATES, self::DERIVED], true)) {
            return ['status' => 'rejected', 'reason' => 'unknown_state'];
        }
        $asset = $this->db->fetchAssociative(
            'SELECT lifecycle_state FROM gf_vault_assets WHERE id = :id AND organization_id = :organization AND deleted_at IS NULL AND purged_at IS NULL FOR UPDATE',
            ['id' => $assetId, 'organization' => $organizationId],
        );
        if ($asset === false) {
            return ['status' => 'missing'];
        }
        $from = in_array($asset['lifecycle_state'], self::STATES, true) ? (string) $asset['lifecycle_state'] : self::DEFAULT_STATE;
        $reason = match (true) {
            $to === self::DERIVED => 'derived_state',
            $to === $from => 'same_state',
            !in_array($to, self::TRANSITIONS[$from], true) => 'invalid_transition',
            default => null,
        };
        if ($reason === null) {
            $this->db->executeStatement(
                'UPDATE gf_vault_assets SET lifecycle_state = :to WHERE id = :id AND organization_id = :organization',
                ['to' => $to, 'id' => $assetId, 'organization' => $organizationId],
            );
        }
        $this->db->insert('gf_vault_asset_lifecycle_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $organizationId, 'asset_id' => $assetId,
            'actor_id' => $actorId, 'from_state' => $from, 'to_state' => $to,
            'outcome' => $reason === null ? 'applied' : 'rejected', 'reason' => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $reason === null
            ? ['status' => 'applied', 'from' => $from, 'to' => $to]
            : ['status' => 'rejected', 'from' => $from, 'to' => $to, 'reason' => $reason];
    }
}
