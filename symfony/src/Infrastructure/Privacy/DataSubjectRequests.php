<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Privacy;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Isolated, pre-live DSR boundary. Never enumerates tenant-owned resources.
 * No live deletion occurs: legal holds, ownership and shared data need review.
 */
final class DataSubjectRequests
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.secret%')] private readonly string $auditSecret,
    ) {
    }

    /** @return array{id:string,display_name:string,email:string}|null */
    public function exportOwn(string $authenticatedUserId): ?array
    {
        $actorRef = $this->actorRef($authenticatedUserId);

        return $this->db->transactional(function (Connection $db) use ($authenticatedUserId, $actorRef): ?array {
            // A partial identity-only export is NOT fulfillment of a full DSR.
            // Lock the identity during the scoped read and audit write. Never
            // accept a target user or organization ID from an HTTP payload.
            $user = $db->fetchAssociative(
                'SELECT id, name, email FROM gf_identity_users WHERE id = :id AND is_active = 1 FOR UPDATE',
                ['id' => $authenticatedUserId],
            );
            if ($user === false) {
                return null;
            }

            $this->record($db, $actorRef, 'export', 'partial');

            return [
                'id' => (string) $user['id'],
                'display_name' => (string) $user['name'],
                'email' => (string) $user['email'],
            ];
        });
    }

    /** @return 'pending'|'revoked' */
    public function requestDeletion(string $authenticatedUserId): string
    {
        $actorRef = $this->actorRef($authenticatedUserId);

        return $this->db->transactional(function (Connection $db) use ($authenticatedUserId, $actorRef): string {
            // This row lock serializes repeated requests for the same account.
            // No account or tenant records are deleted in this build-ahead slice.
            $active = $db->fetchOne(
                'SELECT is_active FROM gf_identity_users WHERE id = :id FOR UPDATE',
                ['id' => $authenticatedUserId],
            );
            if ($active === false || (int) $active !== 1) {
                return 'revoked';
            }
            $existing = $db->fetchOne(
                "SELECT id FROM gf_data_subject_requests WHERE actor_ref = :actor_ref AND kind = 'deletion' AND status = 'pending' LIMIT 1",
                ['actor_ref' => $actorRef],
            );
            if ($existing === false) {
                $this->record($db, $actorRef, 'deletion', 'pending');
            }

            // Always fail closed until a human-authorized erasure workflow can
            // prove ownership, hold status, tenant isolation and backups.
            return 'pending';
        });
    }

    private function actorRef(string $id): string
    {
        if (strlen($this->auditSecret) < 16 || !Uuid::isValid($id)) {
            throw new \LogicException('DSR audit identity unavailable');
        }

        return hash_hmac('sha256', 'grindflow:dsr:v1:'.$id, $this->auditSecret);
    }

    private function record(Connection $db, string $actorRef, string $kind, string $status): void
    {
        // Store no names, emails, IPs, payloads, tenant IDs or raw user UUIDs.
        $db->insert('gf_data_subject_requests', [
            'id' => Uuid::v7()->toRfc4122(),
            'actor_ref' => $actorRef,
            'kind' => $kind,
            'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
