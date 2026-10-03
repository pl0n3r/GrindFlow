<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use JsonException;
use Symfony\Component\Uid\Uuid;

final readonly class FacebookPagePublicationService
{
    private const string PROVIDER = 'facebook_page';

    public function __construct(
        private Connection $db,
        private FacebookPageProvider $provider,
        private ?Closure $clock = null,
    ) {}

    public function publish(DistributionCommand $command): DistributionOutcome
    {
        $this->provider->assertAvailableFor($command->organizationId);

        if (!$this->db->createSchemaManager()->tablesExist(['gf_external_publication_attempts'])) {
            throw DistributionProviderException::configuration();
        }

        $fingerprint = $this->fingerprint($command);
        $claim = $this->claim($command, $fingerprint);

        if ($claim instanceof DistributionOutcome) {
            return $claim;
        }

        try {
            $outcome = $this->provider->publish($command);
        } catch (DistributionProviderException $exception) {
            $this->recordFailure($command, $exception);
            throw $exception;
        }

        $updated = $this->db->update('gf_external_publication_attempts', [
            'status' => 'published',
            'external_publication_id' => $outcome->externalPublicationId,
            'retry_after_seconds' => null,
            'retry_not_before' => null,
            'updated_at' => $this->sqlTimestamp($this->now()),
        ], [
            'organization_id' => $command->organizationId,
            'provider' => self::PROVIDER,
            'idempotency_key' => $command->idempotencyKey,
            'status' => 'in_flight',
        ]);

        if ($updated !== 1) {
            throw DistributionProviderException::ambiguous();
        }

        return $outcome;
    }

    private function claim(
        DistributionCommand $command,
        string $fingerprint,
    ): ?DistributionOutcome {
        return $this->db->transactional(function (Connection $db) use ($command, $fingerprint): ?DistributionOutcome {
            $now = $this->now();
            $nowSql = $this->sqlTimestamp($now);
            $inserted = true;

            try {
                $db->insert('gf_external_publication_attempts', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'organization_id' => $command->organizationId,
                    'provider' => self::PROVIDER,
                    'idempotency_key' => $command->idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'status' => 'in_flight',
                    'created_at' => $nowSql,
                    'updated_at' => $nowSql,
                ]);
            } catch (UniqueConstraintViolationException) {
                $inserted = false;
            }

            if ($inserted) {
                return null;
            }

            $row = $db->fetchAssociative(
                <<<'SQL'
                    SELECT request_fingerprint, status, external_publication_id,
                           retry_after_seconds, retry_not_before
                    FROM gf_external_publication_attempts
                    WHERE organization_id = :organization
                      AND provider = :provider
                      AND idempotency_key = :key
                    FOR UPDATE
                    SQL,
                [
                    'organization' => $command->organizationId,
                    'provider' => self::PROVIDER,
                    'key' => $command->idempotencyKey,
                ],
            );

            if ($row === false || !hash_equals((string) $row['request_fingerprint'], $fingerprint)) {
                throw DistributionProviderException::rejected();
            }

            return match ((string) $row['status']) {
                'published' => $this->storedOutcome($row),
                'rate_limited' => $this->resumeRateLimited($db, $command, $row, $now),
                'authentication_failed' => throw DistributionProviderException::authentication(),
                'rejected' => throw DistributionProviderException::rejected(),
                'ambiguous', 'in_flight' => throw DistributionProviderException::ambiguous(),
                default => throw DistributionProviderException::ambiguous(),
            };
        });
    }

    /** @param array<string,mixed> $row */
    private function storedOutcome(array $row): DistributionOutcome
    {
        $external = $row['external_publication_id'] ?? null;
        if (!is_string($external) || $external === '') {
            throw DistributionProviderException::ambiguous();
        }

        return new DistributionOutcome($external);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resumeRateLimited(
        Connection $db,
        DistributionCommand $command,
        array $row,
        DateTimeImmutable $now,
    ): ?DistributionOutcome {
        $retryAt = $row['retry_not_before'] ?? null;
        if (!is_string($retryAt) || $retryAt === '') {
            throw DistributionProviderException::ambiguous();
        }

        $retryDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $retryAt,
            new DateTimeZone('UTC'),
        );
        if ($retryDate === false) {
            throw DistributionProviderException::ambiguous();
        }

        $remaining = $retryDate->getTimestamp() - $now->getTimestamp();
        if ($remaining > 0) {
            throw DistributionProviderException::rateLimited($remaining);
        }

        $updated = $db->update('gf_external_publication_attempts', [
            'status' => 'in_flight',
            'retry_after_seconds' => null,
            'retry_not_before' => null,
            'updated_at' => $this->sqlTimestamp($now),
        ], [
            'organization_id' => $command->organizationId,
            'provider' => self::PROVIDER,
            'idempotency_key' => $command->idempotencyKey,
            'status' => 'rate_limited',
        ]);

        if ($updated !== 1) {
            throw DistributionProviderException::ambiguous();
        }

        return null;
    }

    private function recordFailure(
        DistributionCommand $command,
        DistributionProviderException $exception,
    ): void {
        $status = match ($exception->kind) {
            DistributionProviderException::KIND_AUTHENTICATION => 'authentication_failed',
            DistributionProviderException::KIND_RATE_LIMIT => 'rate_limited',
            DistributionProviderException::KIND_REJECTED => 'rejected',
            default => 'ambiguous',
        };

        $now = $this->now();
        $retryAfter = $status === 'rate_limited'
            ? max(60, min($exception->retryAfterSeconds ?? 300, 3600))
            : null;
        $retryNotBefore = $retryAfter === null
            ? null
            : $this->sqlTimestamp($now->modify('+'.$retryAfter.' seconds'));

        $this->db->update('gf_external_publication_attempts', [
            'status' => $status,
            'retry_after_seconds' => $retryAfter,
            'retry_not_before' => $retryNotBefore,
            'updated_at' => $this->sqlTimestamp($now),
        ], [
            'organization_id' => $command->organizationId,
            'provider' => self::PROVIDER,
            'idempotency_key' => $command->idempotencyKey,
            'status' => 'in_flight',
        ]);
    }

    private function fingerprint(DistributionCommand $command): string
    {
        try {
            $json = json_encode([
                'provider' => self::PROVIDER,
                'organization_id' => strtolower($command->organizationId),
                'message' => $command->message,
                'link' => $command->link,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw DistributionProviderException::rejected();
        }

        return hash('sha256', $json);
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock === null) {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw DistributionProviderException::configuration();
        }

        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function sqlTimestamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
