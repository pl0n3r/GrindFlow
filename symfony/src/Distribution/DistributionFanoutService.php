<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GrindFlow\Ops\Security\EmergencyStopStore;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * One logical draft publication, N durable destination identities. The ledger
 * alone records actual provider attempts; a queued destination is NOT sent.
 *
 * No credential, media payload or external response is stored in these rows.
 */
final readonly class DistributionFanoutService
{
    public function __construct(private Connection $db, private ExternalPublicationLedger $ledger) {}

    /**
     * @param list<array{provider:string,destination_id:string}> $destinations
     * @return array<string,mixed>
     */
    public function prepare(string $organizationId, string $actorId, string $draftId, string $requestKey, array $destinations, callable $ownsDestination): array
    {
        if (!Uuid::isValid($organizationId) || !Uuid::isValid($actorId) || !Uuid::isValid($draftId)
            || preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $requestKey) !== 1
            || count($destinations) < 1 || count($destinations) > 20) {
            throw new InvalidArgumentException('Invalid fan-out request.');
        }

        $seen = [];
        $normalized = [];
        foreach ($destinations as $destination) {
            $provider = $destination['provider'] ?? null;
            $id = $destination['destination_id'] ?? null;
            if (!is_string($provider) || !in_array($provider, ['facebook_page', 'instagram_reels'], true)
                || !is_string($id) || preg_match('/^\\d{1,32}$/D', $id) !== 1) {
                throw new InvalidArgumentException('Invalid destination.');
            }
            $identity = $provider.':'.$id;
            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('Duplicated destination.');
            }
            // Resolve ownership against trusted server-side connection state.
            // User-provided IDs alone never constitute authorization.
            if ($ownsDestination($organizationId, $provider, $id) !== true) {
                throw DistributionProviderException::authentication();
            }
            $seen[$identity] = true;
            $normalized[] = ['provider' => $provider, 'destination_id' => $id];
        }
        usort($normalized, static fn (array $a, array $b): int =>
            strcmp($a['provider'].':'.$a['destination_id'], $b['provider'].':'.$b['destination_id']));

        (new EmergencyStopStore($this->db))->assertRunning();
        $this->assertSchema();
        $groupId = $this->db->transactional(function (Connection $db) use (
            $organizationId, $actorId, $draftId, $requestKey, $normalized
        ): string {
            $this->assertMembership($organizationId, $actorId);
            $draft = $db->fetchAssociative(
                <<<'SQL'
                    SELECT id, asset_id, caption, status, delivery_locked_at
                    FROM gf_schedule_drafts
                    WHERE organization_id = :organization AND id = :draft
                    FOR UPDATE
                    SQL,
                ['organization' => $organizationId, 'draft' => $draftId],
            );
            if ($draft === false || $draft['status'] !== 'draft'
                || !is_string($draft['caption']) || trim($draft['caption']) === '') {
                throw new LogicException('Draft is unavailable or missing its explicit caption.');
            }
            $fingerprint = hash('sha256', json_encode([
                'draft' => $draftId,
                'asset' => $draft['asset_id'],
                'caption' => $draft['caption'],
                'destinations' => $normalized,
            ], JSON_THROW_ON_ERROR));
            // The draft lock serializes prepare with the legacy publish,
            // edit and cancellation paths. An identical request can replay
            // even after the draft has been locked by this same fan-out.
            $existing = $db->fetchAssociative(
                <<<'SQL'
                    SELECT id, request_key, request_fingerprint
                    FROM gf_distribution_fanout_groups
                    WHERE organization_id = :organization AND draft_id = :draft
                    FOR UPDATE
                    SQL,
                ['organization' => $organizationId, 'draft' => $draftId],
            );
            if ($existing !== false) {
                if (!hash_equals((string) $existing['request_key'], $requestKey)
                    || !hash_equals((string) $existing['request_fingerprint'], $fingerprint)) {
                    throw new LogicException('Idempotency key belongs to a different intent.');
                }
                return (string) $existing['id'];
            }
            if ($draft['delivery_locked_at'] !== null) {
                throw new LogicException('Draft is already locked by another delivery flow.');
            }
            $id = Uuid::v7()->toRfc4122();
            $inserted = true;
            try {
                $db->insert('gf_distribution_fanout_groups', [
                    'id' => $id,
                    'organization_id' => $organizationId,
                    'draft_id' => $draftId,
                    'request_key' => $requestKey,
                    'request_fingerprint' => $fingerprint,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            } catch (UniqueConstraintViolationException) {
                $inserted = false;
            }
            if (!$inserted) {
                $existing = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT id, request_key, request_fingerprint FROM gf_distribution_fanout_groups
                        WHERE organization_id = :organization AND draft_id = :draft
                        FOR UPDATE
                        SQL,
                    ['organization' => $organizationId, 'draft' => $draftId],
                );
                if ($existing === false || !hash_equals((string) $existing['request_key'], $requestKey)
                    || !hash_equals((string) $existing['request_fingerprint'], $fingerprint)) {
                    throw new LogicException('Idempotency key belongs to a different intent.');
                }
                return (string) $existing['id'];
            }
            foreach ($normalized as $destination) {
                $deliveryId = Uuid::v7()->toRfc4122();
                $db->insert('gf_distribution_fanout_deliveries', [
                    'id' => $deliveryId,
                    'group_id' => $id,
                    'organization_id' => $organizationId,
                    'provider' => $destination['provider'],
                    'destination_id' => $destination['destination_id'],
                    'idempotency_key' => 'fanout:'.$deliveryId.':v1',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $updated = $db->update('gf_schedule_drafts', [
                'delivery_locked_at' => gmdate('Y-m-d H:i:s'),
            ], [
                'id' => $draftId,
                'organization_id' => $organizationId,
            ]);
            if ($updated !== 1) {
                throw new LogicException('Draft lock was lost while preparing the group.');
            }
            return $id;
        });
        return $this->summary($organizationId, $actorId, $groupId);
    }

    /**
     * A single destination is dispatched only after an authoritative,
     * current per-account authorization and an emergency-stop check.
     * The callback is the existing provider adapter, not an alternate ledger.
     *
     * @param callable(string,string,string):bool $authorize
     * @param callable(DistributionCommand,string,string):DistributionOutcome $send
     * @return array<string,mixed>
     */
    public function deliver(
        string $organizationId, string $actorId, string $groupId, string $deliveryId,
        callable $authorize, callable $send
    ): array {
        (new EmergencyStopStore($this->db))->assertRunning();
        $this->assertSchema();
        $this->assertMembership($organizationId, $actorId);
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT delivery.provider, delivery.destination_id, delivery.idempotency_key,
                       draft.caption, draft.status AS draft_status
                FROM gf_distribution_fanout_deliveries delivery
                INNER JOIN gf_distribution_fanout_groups batch
                  ON batch.id = delivery.group_id AND batch.organization_id = delivery.organization_id
                INNER JOIN gf_schedule_drafts draft
                  ON draft.id = batch.draft_id AND draft.organization_id = batch.organization_id
                WHERE delivery.organization_id = :organization AND delivery.group_id = :batch
                  AND delivery.id = :delivery
                SQL,
            ['organization' => $organizationId, 'batch' => $groupId, 'delivery' => $deliveryId],
        );
        if ($row === false || $row['draft_status'] !== 'draft' || !is_string($row['caption'])
            || trim($row['caption']) === '') {
            throw new LogicException('Destination or source draft is unavailable.');
        }
        $provider = (string) $row['provider'];
        $destinationId = (string) $row['destination_id'];
        // The validator must use server-side connection identity and fresh
        // proof, not provider IDs asserted by the browser.
        if ($authorize($organizationId, $provider, $destinationId) !== true) {
            throw DistributionProviderException::authentication();
        }
        $command = new DistributionCommand(
            $organizationId, (string) $row['idempotency_key'], (string) $row['caption']
        );
        $key = $provider === 'facebook_page' ? 'page_id' : 'instagram_user_id';
        $this->ledger->publish(
            $command, $provider, $key, $destinationId,
            static fn (): DistributionOutcome => $send($command, $provider, $destinationId),
        );
        return $this->summary($organizationId, $actorId, $groupId);
    }

    /** @return array<string,mixed> */
    public function summary(string $organizationId, string $actorId, string $groupId): array
    {
        $this->assertSchema();
        $this->assertMembership($organizationId, $actorId);
        $group = $this->db->fetchAssociative(
            'SELECT id, draft_id FROM gf_distribution_fanout_groups WHERE id = :id AND organization_id = :organization',
            ['id' => $groupId, 'organization' => $organizationId],
        );
        if ($group === false) {
            throw new LogicException('Publication group does not belong to this organization.');
        }
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT delivery.id, delivery.provider, delivery.destination_id,
                       attempt.status, attempt.external_publication_id, attempt.updated_at
                FROM gf_distribution_fanout_deliveries delivery
                LEFT JOIN gf_external_publication_attempts attempt
                  ON attempt.organization_id = delivery.organization_id
                 AND attempt.provider = delivery.provider
                 AND attempt.idempotency_key = delivery.idempotency_key
                WHERE delivery.group_id = :batch AND delivery.organization_id = :organization
                ORDER BY delivery.provider, delivery.destination_id
                SQL,
            ['batch' => $groupId, 'organization' => $organizationId],
        );
        $counts = ['total' => count($rows), 'sent' => 0, 'pending' => 0, 'failed' => 0];
        $items = [];
        foreach ($rows as $row) {
            $status = $row['status'] === null ? 'pending' : (string) $row['status'];
            if ($status === 'published') {
                ++$counts['sent'];
            } elseif (in_array($status, ['pending', 'in_flight'], true)) {
                ++$counts['pending'];
            } else {
                ++$counts['failed'];
            }
            $items[] = [
                'id' => (string) $row['id'],
                'provider' => (string) $row['provider'],
                'destination_id' => (string) $row['destination_id'],
                'status' => $status,
                'external_publication_id' => $status === 'published'
                    ? (string) $row['external_publication_id'] : null,
                'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
                'automatic_retry' => false,
            ];
        }
        return ['id' => (string) $group['id'], 'draft_id' => (string) $group['draft_id'],
            ...$counts, 'deliveries' => $items, 'automatic_retry' => false];
    }


    /** @return list<array<string,mixed>> */
    public function groupsForDraft(string $organizationId, string $actorId, string $draftId): array
    {
        if (!$this->db->createSchemaManager()->tablesExist([
            'gf_distribution_fanout_groups', 'gf_distribution_fanout_deliveries',
        ])) {
            return [];
        }
        $this->assertMembership($organizationId, $actorId);
        $ids = $this->db->fetchFirstColumn(
            <<<'SQL'
                SELECT id FROM gf_distribution_fanout_groups
                WHERE organization_id = :organization AND draft_id = :draft
                ORDER BY created_at DESC, id DESC LIMIT 10
                SQL,
            ['organization' => $organizationId, 'draft' => $draftId],
        );
        return array_map(fn (string $id): array =>
            $this->summary($organizationId, $actorId, $id), $ids);
    }

    private function assertSchema(): void
    {
        if (!$this->db->createSchemaManager()->tablesExist([
            'gf_distribution_fanout_groups',
            'gf_distribution_fanout_deliveries',
            'gf_external_publication_attempts',
        ])) {
            throw new LogicException('Fan-out schema has not been migrated.');
        }
    }

    private function assertMembership(string $organizationId, string $actorId): void
    {
        $allowed = $this->db->fetchOne(
            <<<'SQL'
                SELECT membership.id FROM gf_identity_memberships membership
                INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                WHERE membership.organization_id = :organization AND membership.user_id = :actor
                  AND actor.is_active = 1 AND membership.role IN ('admin', 'studio', 'editor')
                LIMIT 1
                SQL,
            ['organization' => $organizationId, 'actor' => $actorId],
        );
        if ($allowed === false) {
            throw new LogicException('Membership revoked or insufficient role.');
        }
    }
}
