<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionOutcome;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\ExternalPublicationLedger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Synthetic-only DBAL in-memory regression: no real providers or network. */
final class ExternalPublicationLedgerTest extends TestCase
{
    private LedgerInMemoryConnection $db;
    private string $organizationId;

    protected function setUp(): void
    {
        /** @var LedgerInMemoryConnection $db */
        $db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => LedgerInMemoryConnection::class,
        ]);
        $this->db = $db;
        $this->organizationId = Uuid::v7()->toRfc4122();
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE gf_emergency_stop (
                id INTEGER PRIMARY KEY, state TEXT NOT NULL,
                revision INTEGER NOT NULL, revocation_complete INTEGER NOT NULL
            )
            SQL);
        $this->db->insert('gf_emergency_stop', [
            'id' => 1, 'state' => 'RUNNING', 'revision' => 1, 'revocation_complete' => 1,
        ]);
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE gf_external_publication_attempts (
                id TEXT PRIMARY KEY,
                organization_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                idempotency_key TEXT NOT NULL,
                request_fingerprint TEXT NOT NULL,
                status TEXT NOT NULL,
                external_publication_id TEXT DEFAULT NULL,
                retry_after_seconds INTEGER DEFAULT NULL,
                retry_not_before TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (organization_id, provider, idempotency_key)
            )
            SQL);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testSameIdempotencyKeyDoesNotSendTwice(): void
    {
        $transport = new LedgerFakeTransport();
        $ledger = new ExternalPublicationLedger($this->db);
        $command = $this->command('same-key');

        $first = $ledger->publish($command, 'facebook_page', 'page_id', '12345678', $transport->send(...));
        $second = $ledger->publish($command, 'facebook_page', 'page_id', '12345678', $transport->send(...));

        self::assertSame('synthetic-publication-1', $first->externalPublicationId);
        self::assertSame($first->externalPublicationId, $second->externalPublicationId);
        self::assertSame(1, $transport->calls, 'La misma clave no debe provocar un segundo envío.');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_external_publication_attempts'));
        self::assertSame('published', $this->record()['status']);
    }

    public function testRateLimitedFailureIsRecordedWithRetryWindowAndResumes(): void
    {
        $now = new DateTimeImmutable('2026-11-03T14:00:00+00:00');
        $clock = static function () use (&$now): DateTimeImmutable { return $now; };
        $ledger = new ExternalPublicationLedger($this->db, $clock);
        $transport = new LedgerFakeTransport();
        $transport->rateLimitFirst = true;
        $command = $this->command('rate-limit-key');

        try {
            $ledger->publish($command, 'instagram_reels', 'instagram_user_id', '102938', $transport->send(...));
            self::fail('El transporte falso debe señalar un límite de peticiones.');
        } catch (DistributionProviderException $e) {
            self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $e->kind);
            self::assertSame(120, $e->retryAfterSeconds);
        }
        $row = $this->record();
        self::assertSame('rate_limited', $row['status']);
        self::assertSame(120, (int) $row['retry_after_seconds']);
        self::assertSame('2026-11-03 14:02:00', $row['retry_not_before']);
        self::assertSame(1, $transport->calls);

        try {
            $ledger->publish($command, 'instagram_reels', 'instagram_user_id', '102938', $transport->send(...));
            self::fail('Antes de la ventana de reintento no puede llamarse al transporte.');
        } catch (DistributionProviderException $e) {
            self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $e->kind);
        }
        self::assertSame(1, $transport->calls);

        $now = $now->modify('+121 seconds');
        $outcome = $ledger->publish($command, 'instagram_reels', 'instagram_user_id', '102938', $transport->send(...));
        self::assertSame('synthetic-publication-2', $outcome->externalPublicationId);
        self::assertSame(2, $transport->calls);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_external_publication_attempts'));
        $after = $this->record();
        self::assertSame('published', $after['status']);
        self::assertNull($after['retry_after_seconds']);
        self::assertNull($after['retry_not_before']);
    }

    public function testInvalidProviderOrDestinationFailsClosedBeforeSending(): void
    {
        $transport = new LedgerFakeTransport();
        $ledger = new ExternalPublicationLedger($this->db);
        $command = $this->command('invalid-provider');
        foreach ([
            ['unknown_provider', 'page_id', '12345'],
            ['facebook_page', 'unexpected_key', '12345'],
            ['instagram_reels', 'instagram_user_id', 'not-numeric'],
        ] as [$provider, $key, $destination]) {
            try {
                $ledger->publish($command, $provider, $key, $destination, $transport->send(...));
                self::fail('Los valores externos inválidos deben rechazarse antes de enviar.');
            } catch (DistributionProviderException $e) {
                self::assertSame(DistributionProviderException::KIND_CONFIGURATION, $e->kind);
            }
        }
        self::assertSame(0, $transport->calls);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_external_publication_attempts'));
    }

    public function testStoredEvidenceNeverContainsTokensOrPayloads(): void
    {
        $payload = 'SYNTHETIC_PRIVATE_CAPTION_DO_NOT_STORE';
        $token = 'SYNTHETIC_PRIVATE_TOKEN_DO_NOT_STORE';
        $link = 'https://example.test/fixture?private=SYNTHETIC_URL_MARKER';
        $transport = new LedgerFakeTransport();
        $ledger = new ExternalPublicationLedger($this->db);
        $command = new DistributionCommand($this->organizationId, 'secret-free-evidence', $payload, $link);
        $send = static function () use ($transport, $token): DistributionOutcome {
            self::assertNotSame('', $token);
            return $transport->send();
        };
        $ledger->publish($command, 'facebook_page', 'page_id', '998877', $send);

        $stored = json_encode($this->db->fetchAllAssociative('SELECT * FROM gf_external_publication_attempts'), JSON_THROW_ON_ERROR);
        self::assertSame('published', $this->record()['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $this->record()['request_fingerprint']);
        self::assertSame('synthetic-publication-1', $this->record()['external_publication_id']);
        foreach ([$payload, $token, $link, 'SYNTHETIC_URL_MARKER'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $stored);
        }
        self::assertSame(1, $transport->calls);
    }

    private function command(string $idempotencyKey): DistributionCommand
    {
        return new DistributionCommand($this->organizationId, $idempotencyKey, 'Publicación sintética sin red');
    }

    /** @return array<string,mixed> */
    private function record(): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM gf_external_publication_attempts LIMIT 1');
        self::assertIsArray($row);
        return $row;
    }
}

/** SQLite rejects FOR UPDATE; strip that unsupported lock suffix in this isolated test connection. */
final class LedgerInMemoryConnection extends Connection
{
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $query = preg_replace('/\s+FOR UPDATE\s*$/i', '', $query) ?? $query;
        return parent::fetchAssociative($query, $params, $types);
    }
}

/** Sends no network traffic; returns deterministic synthetic receipts. */
final class LedgerFakeTransport
{
    public int $calls = 0;
    public bool $rateLimitFirst = false;

    public function send(): DistributionOutcome
    {
        ++$this->calls;
        if ($this->rateLimitFirst && $this->calls === 1) {
            throw DistributionProviderException::rateLimited(120);
        }
        return new DistributionOutcome('synthetic-publication-'.$this->calls);
    }
}
