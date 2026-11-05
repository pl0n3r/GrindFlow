<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Destination health is a tenant-scoped, read-only interpretation of trusted
 * evidence. A configured token or an old successful publish never proves that
 * an account is connected *now*. This class performs no remote provider I/O.
 */
final readonly class DestinationHealthService
{
    private const PROVIDERS = ['facebook_page', 'instagram_reels'];
    private const FRESH_SECONDS = 900;

    public function __construct(private Connection $db)
    {
    }

    /**
     * Signals must come from trusted server-side evidence, not the request.
     *
     * @param array<string,mixed> $signals
     * @return array{state:string,reason_code:string,action:string,can_enqueue:bool,evidence_at:?string}
     */
    public function classify(array $signals, ?DateTimeImmutable $at = null): array
    {
        $now = $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $credential = $signals['credential'] ?? null;
        $failure = $signals['last_delivery_status'] ?? null;
        $availability = $signals['provider_availability'] ?? null;
        $evidenceAt = $this->verifiedAt($signals['verified_at'] ?? null);
        $stamp = $evidenceAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        if (($signals['disabled'] ?? null) === true || $credential === 'disconnected') {
            return $this->result('blocked', 'destination_disconnected', 'review', $stamp);
        }
        if ($credential === 'revoked' || $failure === 'authentication_failed') {
            return $this->result('attention_required', 'token_revoked', 'reconnect', $stamp);
        }
        if ($credential === 'expired') {
            return $this->result('attention_required', 'token_expired', 'reconnect', $stamp);
        }
        if ($credential === 'permission_revoked') {
            return $this->result('attention_required', 'permission_revoked', 'review', $stamp);
        }
        if ($failure === 'ambiguous') {
            // The external ledger must not blindly retry an uncertain send.
            return $this->result('blocked', 'ambiguous_delivery', 'review', $stamp);
        }
        if ($availability === 'down' || $failure === 'rate_limited') {
            return $this->result('temporarily_unavailable', 'provider_temporarily_unavailable', 'retry_later', $stamp);
        }
        if ($availability === 'transient') {
            return $this->result('temporarily_unavailable', 'provider_temporarily_unavailable', 'retry_later', $stamp);
        }

        $age = $evidenceAt === null ? null : $now->getTimestamp() - $evidenceAt->getTimestamp();
        if ($credential === 'verified'
            && ($signals['permission'] ?? null) === 'granted'
            && $availability === 'available'
            && $age !== null && $age >= 0 && $age <= self::FRESH_SECONDS) {
            return $this->result('connected', 'recent_verified_authorization', 'none', $stamp);
        }

        // 'published' is a historical delivery, not a live authorization probe.
        // Content-specific 'rejected' is likewise not account-wide evidence.
        return $this->result('unknown', 'insufficient_verified_evidence', 'review', $stamp);
    }

    /**
     * Read the latest safe internal delivery signal for each provider.
     * A ledger row is scoped by organization and never exposes payloads, ids,
     * tokens, headers, raw provider errors or destination account identifiers.
     *
     * @return list<array{provider:string,state:string,reason_code:string,action:string,can_enqueue:bool,evidence_at:?string}>
     */
    public function forOrganization(string $organizationId, ?DateTimeImmutable $at = null): array
    {
        if (!Uuid::isValid($organizationId)) {
            return [];
        }

        $ready = false;
        try {
            $ready = $this->db->createSchemaManager()->tablesExist(['gf_external_publication_attempts']);
        } catch (\Throwable) {
            // Unavailable or unmigrated ledger is UNKNOWN, never CONNECTED.
        }

        $results = [];
        foreach (self::PROVIDERS as $provider) {
            $signals = [];
            if ($ready) {
                try {
                    $last = $this->db->fetchAssociative(
                        <<<'SQL'
                            SELECT status, updated_at
                            FROM gf_external_publication_attempts
                            WHERE organization_id = :organization AND provider = :provider
                            ORDER BY updated_at DESC, id DESC
                            LIMIT 1
                            SQL,
                        ['organization' => $organizationId, 'provider' => $provider],
                    );
                    if ($last !== false) {
                        // Whitelist evidence only. Even "published" fails closed.
                        $signals['last_delivery_status'] = (string) $last['status'];
                        // A delivery timestamp is not a provider verification stamp.
                    }
                } catch (\Throwable) {
                    $signals = [];
                }
            }
            $results[] = ['provider' => $provider, ...$this->classify($signals, $at)];
        }

        return $results;
    }

    /**
     * Selective dispatch boundary for a future caller. Only the destination
     * with current, trusted evidence may reach $send; a blocked destination
     * cannot prevent a separate healthy destination from progressing.
     *
     * @param list<array{organization_id:string,provider:string,destination_id:string,signals:array<string,mixed>}> $destinations
     * @return list<array{provider:string,destination_id:string,state:string,dispatched:bool}>
     */
    public function dispatchEligible(
        string $organizationId,
        array $destinations,
        callable $send,
        ?DateTimeImmutable $at = null,
    ): array {
        $outcomes = [];
        foreach ($destinations as $destination) {
            $provider = $destination['provider'] ?? '';
            $destinationId = $destination['destination_id'] ?? '';
            $owned = is_string($destination['organization_id'] ?? null)
                && hash_equals(strtolower($organizationId), strtolower($destination['organization_id']));
            $valid = $owned
                && in_array($provider, self::PROVIDERS, true)
                && is_string($destinationId) && preg_match('/^\d{1,32}$/D', $destinationId) === 1;
            $classification = $valid
                ? $this->classify($destination['signals'] ?? [], $at)
                : $this->result('blocked', 'destination_not_available', 'review', null);
            $eligible = $valid && $classification['can_enqueue'];
            if ($eligible) {
                // Never persist the return value; ExternalPublicationLedger
                // remains the authority for idempotency and ambiguous sends.
                $send($provider, $destinationId);
            }
            $outcomes[] = [
                'provider' => in_array($provider, self::PROVIDERS, true) ? $provider : 'unknown',
                'destination_id' => $owned && is_string($destinationId) && preg_match('/^\d{1,32}$/D', $destinationId) === 1
                    ? $destinationId : 'unavailable',
                'state' => $classification['state'],
                'dispatched' => $eligible,
            ];
        }

        return $outcomes;
    }

    /** @return array{state:string,reason_code:string,action:string,can_enqueue:bool,evidence_at:?string} */
    private function result(string $state, string $reason, string $action, ?string $at): array
    {
        return [
            'state' => $state,
            'reason_code' => $reason,
            'action' => $action,
            'can_enqueue' => $state === 'connected',
            'evidence_at' => $at,
        ];
    }

    private function verifiedAt(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }
}
