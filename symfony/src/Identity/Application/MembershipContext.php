<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Application;

use Doctrine\DBAL\Connection;

final readonly class MembershipContext
{
    private const ROLES = ['admin', 'studio', 'editor', 'model'];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{id: string, name: string, role: string}|null */
    public function find(string $userId, string $organizationId): ?array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT organization.id, organization.name, membership.role
                FROM gf_identity_memberships membership
                INNER JOIN gf_identity_organizations organization
                    ON organization.id = membership.organization_id
                INNER JOIN gf_identity_users actor
                    ON actor.id = membership.user_id
                WHERE membership.user_id = :user AND membership.organization_id = :organization
                  AND actor.is_active = 1
                SQL,
            ['user' => $userId, 'organization' => $organizationId],
        );

        if ($row === false || !in_array($row['role'] ?? null, self::ROLES, true)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'role' => (string) $row['role'],
        ];
    }

    /** @return array{workspace_view: bool, organization_manage: bool, content_prepare: bool, content_review: bool} */
    public function permissions(string $role): array
    {
        return [
            'workspace_view' => in_array($role, self::ROLES, true),
            'organization_manage' => in_array($role, ['admin', 'studio'], true),
            'content_prepare' => in_array($role, ['admin', 'studio', 'editor'], true),
            'content_review' => in_array($role, self::ROLES, true),
        ];
    }
}
