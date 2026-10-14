<?php

declare(strict_types=1);

namespace GrindFlow\Library;

use Doctrine\DBAL\Connection;

/**
 * Server-side Library capability matrix (#53).
 *
 * The effective matrix is the role matrix minus per-member deny-only
 * restrictions: a member can never hold more than their role allows. Anything
 * that cannot be proven (unknown capability, missing/inactive membership,
 * another tenant) is denied. A capability is never a license or a publication
 * grant by itself.
 */
final readonly class LibraryPermissions
{
    public const CAPABILITIES = [
        'view', 'upload', 'edit', 'approve', 'authorize_publication', 'archive', 'delete',
    ];

    private const ROLE_MATRIX = [
        'admin' => ['view', 'upload', 'edit', 'approve', 'authorize_publication', 'archive'],
        'studio' => ['view', 'upload', 'edit', 'approve', 'authorize_publication', 'archive'],
        'editor' => ['view', 'upload', 'edit', 'approve', 'archive'],
        'model' => ['view'],
    ];

    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, bool> every capability, false unless the role grants it */
    public function roleMatrix(string $role): array
    {
        $granted = self::ROLE_MATRIX[$role] ?? [];

        return array_combine(
            self::CAPABILITIES,
            array_map(static fn (string $capability): bool => in_array($capability, $granted, true), self::CAPABILITIES),
        );
    }

    /** @return array<string, bool> effective matrix of one member inside one organization */
    public function forMember(string $organizationId, string $userId): array
    {
        $none = array_fill_keys(self::CAPABILITIES, false);
        $role = $this->db->fetchOne(
            <<<'SQL'
                SELECT membership.role
                FROM gf_identity_memberships membership
                INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                WHERE membership.organization_id = :organization
                  AND membership.user_id = :user AND actor.is_active = 1
                SQL,
            ['organization' => $organizationId, 'user' => $userId],
        );
        if (!is_string($role) || !array_key_exists($role, self::ROLE_MATRIX)) {
            return $none;
        }
        $matrix = $this->roleMatrix($role);
        $denied = $this->db->fetchFirstColumn(
            'SELECT capability FROM gf_library_member_permissions WHERE organization_id = :organization AND user_id = :user',
            ['organization' => $organizationId, 'user' => $userId],
        );
        foreach ($denied as $capability) {
            if (is_string($capability) && array_key_exists($capability, $matrix)) {
                $matrix[$capability] = false;
            }
        }

        return $matrix;
    }

    public function allows(string $organizationId, string $userId, string $capability): bool
    {
        if (!in_array($capability, self::CAPABILITIES, true)) {
            return false;
        }

        return $this->forMember($organizationId, $userId)[$capability];
    }

    /** Restrict one member; idempotent and never able to grant a capability. */
    public function deny(string $organizationId, string $userId, string $capability, string $actorId): bool
    {
        if (!in_array($capability, self::CAPABILITIES, true)) {
            return false;
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO gf_library_member_permissions (organization_id, user_id, capability, created_by, created_at)
                SELECT membership.organization_id, membership.user_id, :capability, :actor, :created
                FROM gf_identity_memberships membership
                WHERE membership.organization_id = :organization AND membership.user_id = :user
                ON DUPLICATE KEY UPDATE capability = capability
                SQL,
            [
                'organization' => $organizationId, 'user' => $userId, 'capability' => $capability,
                'actor' => $actorId, 'created' => gmdate('Y-m-d H:i:s'),
            ],
        );

        // True only when the restriction really exists for a member of that organization.
        return $this->db->fetchOne(
            'SELECT 1 FROM gf_library_member_permissions WHERE organization_id = :organization AND user_id = :user AND capability = :capability',
            ['organization' => $organizationId, 'user' => $userId, 'capability' => $capability],
        ) !== false;
    }
}
