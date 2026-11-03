<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Durable, fail-closed global emergency fence. An absent row is STOP, never RUNNING.
 * This service does no network I/O; every transition and audit uses the same DB.
 */
final readonly class EmergencyStopStore
{
    public function __construct(private Connection $db) {}

    public function runningRevision(): int
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT state, revision, revocation_complete FROM gf_emergency_stop WHERE id = 1'
            );
            if ($row === false || $row['state'] !== 'RUNNING'
                || (int) $row['revocation_complete'] !== 1
                || (int) $row['revision'] < 1) {
                throw new \RuntimeException('emergency_stop');
            }
            return (int) $row['revision'];
        } catch (\Throwable $exception) {
            throw new \RuntimeException('emergency_stop', 0, $exception);
        }
    }

    public function assertRunning(?int $expectedRevision = null): int
    {
        $revision = $this->runningRevision();
        if ($expectedRevision !== null && $expectedRevision !== $revision) {
            throw new \RuntimeException('emergency_stop_stale_revision');
        }
        return $revision;
    }

    /** STOP is committed before any revocation begins, even if revocation fails. */
    public function stop(string $actor, string $reason): int
    {
        $actor = $this->validateActor($actor);
        $reason = $this->validateReason($reason);
        return $this->db->transactional(function (Connection $db) use ($actor, $reason): int {
            $this->seedStoppedRow($db, $actor, $reason);
            $row = $db->fetchAssociative('SELECT state, revision FROM gf_emergency_stop WHERE id = 1 FOR UPDATE');
            if ($row === false) {
                throw new \RuntimeException('emergency_stop_unavailable');
            }
            if ($row['state'] === 'STOP') {
                return (int) $row['revision'];
            }
            $revision = (int) $row['revision'] + 1;
            $db->update('gf_emergency_stop', [
                'state' => 'STOP', 'revision' => $revision, 'revocation_complete' => 0,
                'actor' => $actor, 'reason' => $reason, 'changed_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => 1, 'revision' => (int) $row['revision']]);
            $this->audit($db, 'STOP', $revision, $actor, $reason);
            return $revision;
        });
    }

    /**
     * Idempotent revocation for synthetic/local DB only. No token values are read
     * or logged. Failure retains STOP and prevents resume.
     */
    public function finishRevocation(): void
    {
        $this->db->transactional(function (Connection $db): void {
            $row = $db->fetchAssociative(
                'SELECT state, revision, revocation_complete FROM gf_emergency_stop WHERE id = 1 FOR UPDATE'
            );
            if ($row === false || $row['state'] !== 'STOP') {
                throw new \RuntimeException('emergency_stop_unavailable');
            }
            if ((int) $row['revocation_complete'] === 1) {
                return;
            }
            $db->executeStatement('UPDATE gf_identity_users SET session_generation = session_generation + 1');
            $db->executeStatement('DELETE FROM gf_password_reset_tokens');
            $db->executeStatement('DELETE FROM gf_password_recovery_outbox');
            $db->update('gf_emergency_stop', ['revocation_complete' => 1], [
                'id' => 1, 'revision' => (int) $row['revision'], 'state' => 'STOP',
            ]);
        });
    }

    /** Resume is an explicit admin operation, never an expiration or retry. */
    public function resume(string $actor, string $reason): int
    {
        $actor = $this->validateActor($actor);
        $reason = $this->validateReason($reason);
        return $this->db->transactional(function (Connection $db) use ($actor, $reason): int {
            $row = $db->fetchAssociative(
                'SELECT state, revision, revocation_complete FROM gf_emergency_stop WHERE id = 1 FOR UPDATE'
            );
            if ($row === false || $row['state'] !== 'STOP'
                || (int) $row['revocation_complete'] !== 1) {
                throw new \RuntimeException('emergency_stop_resume_denied');
            }
            $revision = (int) $row['revision'] + 1;
            $changed = $db->update('gf_emergency_stop', [
                'state' => 'RUNNING', 'revision' => $revision,
                'actor' => $actor, 'reason' => $reason, 'changed_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => 1, 'revision' => (int) $row['revision'], 'state' => 'STOP']);
            if ($changed !== 1) {
                throw new \RuntimeException('emergency_stop_conflict');
            }
            $this->audit($db, 'RUNNING', $revision, $actor, $reason);
            return $revision;
        });
    }

    private function seedStoppedRow(Connection $db, string $actor, string $reason): void
    {
        // Establish STOP and the matching audit record atomically when the
        // singleton row has not yet been initialized.
        $inserted = $db->executeStatement(
            "INSERT IGNORE INTO gf_emergency_stop
              (id, state, revision, revocation_complete, actor, reason, changed_at)
             VALUES (1, 'STOP', 1, 0, :actor, :reason, :now)",
            ['actor' => $actor, 'reason' => $reason, 'now' => gmdate('Y-m-d H:i:s')]
        );
        if ($inserted === 1) {
            $this->audit($db, 'STOP', 1, $actor, $reason);
        }
    }

    private function audit(Connection $db, string $state, int $revision, string $actor, string $reason): void
    {
        $db->insert('gf_emergency_stop_audit', [
            'id' => Uuid::v7()->toRfc4122(), 'state' => $state,
            'revision' => $revision, 'actor' => $actor,
            'reason' => $reason, 'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function validateActor(string $actor): string
    {
        if (preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D', $actor) !== 1) {
            throw new \InvalidArgumentException('invalid_actor');
        }
        return $actor;
    }

    private function validateReason(string $reason): string
    {
        if (preg_match('/\A[a-zA-Z0-9._:-]{1,120}\z/D', $reason) !== 1) {
            throw new \InvalidArgumentException('invalid_reason');
        }
        return $reason;
    }
}
