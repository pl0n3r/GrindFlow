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
use GrindFlow\Infrastructure\Connectors\ConnectorState;
use GrindFlow\Scheduling\Recovery\RetryClassifier;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contrato de conectores/reintentos con SQLite descartable y callbacks falsos.
 * Sin cliente HTTP, sockets, credenciales, proveedores ni tráfico de red.
 */
final class ConnectorRetryIdempotencyTest extends TestCase
{
    private ConnectorRetrySqliteConnection $db;
    private string $organizationId;

    protected function setUp(): void
    {
        /** @var ConnectorRetrySqliteConnection $db */
        $db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => ConnectorRetrySqliteConnection::class,
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

    public function testRateLimitRetriesOnlyAfterWindowAndPublishesAtMostOnce(): void
    {
        $now = new DateTimeImmutable('2026-11-22T12:00:00Z');
        $ledger = new ExternalPublicationLedger($this->db, static function () use (&$now): DateTimeImmutable {
            return $now;
        });
        $command = $this->command('retry_after_quota_1051');
        $calls = 0;
        $effects = 0;
        $fake = static function () use (&$calls, &$effects): DistributionOutcome {
            ++$calls;
            if ($calls === 1) {
                // Falló ANTES del efecto: 429 sin cuerpo ni token de proveedor.
                throw DistributionProviderException::rateLimited(120);
            }
            ++$effects;
            return new DistributionOutcome('synthetic-receipt-1051');
        };

        $this->assertProviderError(
            DistributionProviderException::KIND_RATE_LIMIT,
            static fn (): DistributionOutcome => $ledger->publish($command, 'facebook_page', 'page_id', '12345', $fake)
        );
        self::assertSame('rate_limited', $this->record()['status']);
        self::assertSame('2026-11-22 12:02:00', $this->record()['retry_not_before']);
        self::assertSame(0, $effects);

        $now = $now->modify('+119 seconds');
        $this->assertProviderError(
            DistributionProviderException::KIND_RATE_LIMIT,
            static fn (): DistributionOutcome => $ledger->publish($command, 'facebook_page', 'page_id', '12345', $fake)
        );
        self::assertSame(1, $calls, 'No se llama al fake antes de la ventana de cuota.');

        $now = $now->modify('+1 second');
        $receipt = $ledger->publish($command, 'facebook_page', 'page_id', '12345', $fake);
        $repeat = (new ExternalPublicationLedger($this->db))->publish(
            $command, 'facebook_page', 'page_id', '12345', $fake
        );
        self::assertSame('synthetic-receipt-1051', $receipt->externalPublicationId);
        self::assertSame($receipt->externalPublicationId, $repeat->externalPublicationId);
        self::assertSame(2, $calls, 'Primer 429 + un único intento posterior.');
        self::assertSame(1, $effects, 'Reejecutar un efecto confirmado nunca lo duplica.');
        self::assertSame('published', $this->record()['status']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_external_publication_attempts'));
    }

    public function testAmbiguousFailureAfterEffectCannotAutomaticallySendAgain(): void
    {
        $ledger = new ExternalPublicationLedger($this->db);
        $command = $this->command('ambiguous_after_send_1051');
        $effects = 0;
        $fake = static function () use (&$effects): DistributionOutcome {
            ++$effects;
            // El proveedor pudo aplicar el efecto; el acuse se perdió.
            throw DistributionProviderException::ambiguous();
        };
        $send = static fn (): DistributionOutcome => $ledger->publish(
            $command, 'instagram_reels', 'instagram_user_id', '12345', $fake
        );
        $this->assertProviderError(DistributionProviderException::KIND_AMBIGUOUS, $send);
        self::assertSame('ambiguous', $this->record()['status']);
        $this->assertProviderError(DistributionProviderException::KIND_AMBIGUOUS, $send);
        self::assertSame(1, $effects, 'Un resultado ambiguo debe quedar cerrado a reenvíos.');
    }

    public function testInvalidCredentialNeverRetriesWithoutAuthorizedReconnect(): void
    {
        $ledger = new ExternalPublicationLedger($this->db);
        $command = $this->command('invalid_credential_1051');
        $calls = 0;
        $fake = static function () use (&$calls): DistributionOutcome {
            ++$calls;
            throw DistributionProviderException::authentication();
        };
        $send = static fn (): DistributionOutcome => $ledger->publish(
            $command, 'facebook_page', 'page_id', '12345', $fake
        );
        $this->assertProviderError(DistributionProviderException::KIND_AUTHENTICATION, $send);
        self::assertSame('authentication_failed', $this->record()['status']);
        $this->assertProviderError(DistributionProviderException::KIND_AUTHENTICATION, $send);
        self::assertSame(1, $calls, '401 no se reintenta ni se reconecta por sí mismo.');

        $state = new ConnectorState();
        $state->observe(['status' => 'invalid_credential'], 100);
        self::assertSame(ConnectorState::INVALID_CREDENTIAL, $state->summary()['state']);
        self::assertFalse($state->summary()['can_retry']);
        self::assertFalse($state->retry(static fn (): array => ['status' => 'ok'], 101));
        try {
            $state->reconnect(false, 102);
            self::fail('Reconexión no autorizada aceptada.');
        } catch (LogicException) {
            self::assertSame(ConnectorState::INVALID_CREDENTIAL, $state->summary()['state']);
        }
        $state->reconnect(true, 103); // Autorización SINTÉTICA, no activa proveedor real.
        self::assertSame(ConnectorState::RETRYING, $state->summary()['state']);
        self::assertTrue($state->retry(static fn (): array => ['status' => 'ok'], 103));
        self::assertSame(ConnectorState::ACTIVE, $state->summary()['state']);
    }

    public function testFailureClassifierRequiresIdempotencyAndNeverRetriesUnsafeState(): void
    {
        $classifier = new RetryClassifier();
        $failure = [
            'tenant_id' => 'tenant_synthetic',
            'delivery_id' => 'delivery_synthetic',
            'delivery_state' => 'failed',
            'failure_code' => 'rate_limited',
            'idempotency_key' => 'key_synthetic_1051',
            'idempotency_proven' => true,
            'attempt' => 1,
            'max_attempts' => 3,
        ];
        self::assertSame(['decision' => 'retry_safe', 'reason' => 'idempotent_transient_failure'],
            $classifier->classify($failure));
        self::assertSame(['decision' => 'retry_safe', 'reason' => 'idempotent_transient_failure'],
            $classifier->classify(array_replace($failure, ['failure_code' => 'transient_before_send'])));
        self::assertSame(['decision' => 'retry_never', 'reason' => 'idempotency_unverified'],
            $classifier->classify(array_replace($failure, ['idempotency_proven' => false])));
        self::assertSame(['decision' => 'retry_never', 'reason' => 'external_state_ambiguous'],
            $classifier->classify(array_replace($failure, ['delivery_state' => 'ambiguous'])));
        self::assertSame(['decision' => 'needs_human', 'reason' => 'authorization_required'],
            $classifier->classify(array_replace($failure, ['failure_code' => 'authentication_failed'])));
        self::assertSame(['decision' => 'retry_never', 'reason' => 'attempt_budget_exhausted'],
            $classifier->classify(array_replace($failure, ['attempt' => 3])));
    }

    public function testEvidenceAndMessagesNeverContainFakeTokenOrSensitivePayload(): void
    {
        $token = 'SYNTHETIC_FAKE_TOKEN_1051_NOT_A_SECRET';
        $payload = 'SYNTHETIC_FAKE_PAYLOAD_1051';
        $link = 'https://example.invalid/private?marker=SYNTHETIC_FAKE_URL_1051';
        $command = new DistributionCommand($this->organizationId, 'evidence_safe_1051', $payload, $link);
        $calls = 0;
        $fake = static function () use (&$calls, $token): DistributionOutcome {
            self::assertNotSame('', $token);
            ++$calls;
            return new DistributionOutcome('synthetic-receipt-safe');
        };
        $ledger = new ExternalPublicationLedger($this->db);
        $ledger->publish($command, 'facebook_page', 'page_id', '12345', $fake);
        $ledger->publish($command, 'facebook_page', 'page_id', '12345', $fake);
        self::assertSame(1, $calls);
        $evidence = json_encode($this->db->fetchAllAssociative(
            'SELECT * FROM gf_external_publication_attempts'
        ), JSON_THROW_ON_ERROR);
        $state = new ConnectorState();
        $state->observe(['status' => 'quota_exhausted', 'retry_after' => 120], 100);
        $safeSummary = json_encode($state->summary(), JSON_THROW_ON_ERROR);
        self::assertSame(ConnectorState::QUOTA_EXHAUSTED, $state->summary()['state']);
        self::assertNotSame(ConnectorState::INVALID_CREDENTIAL, $state->summary()['state']);
        foreach ([$token, $payload, $link, 'SYNTHETIC_FAKE_URL_1051'] as $marker) {
            self::assertStringNotContainsString($marker, $evidence);
            self::assertStringNotContainsString($marker, $safeSummary);
        }
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $this->record()['request_fingerprint']);
        self::assertSame('published', $this->record()['status']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_external_publication_attempts'));
    }

    private function command(string $key): DistributionCommand
    {
        return new DistributionCommand($this->organizationId, $key, 'Contenido de fixture sin red.');
    }

    /** @return array<string,mixed> */
    private function record(): array
    {
        $record = $this->db->fetchAssociative('SELECT * FROM gf_external_publication_attempts LIMIT 1');
        self::assertIsArray($record);
        return $record;
    }

    /** @param callable():DistributionOutcome $action */
    private function assertProviderError(string $kind, callable $action): void
    {
        try {
            $action();
            self::fail('Error sintético esperado del adaptador.');
        } catch (DistributionProviderException $exception) {
            self::assertSame($kind, $exception->kind);
            self::assertStringNotContainsString('SYNTHETIC_FAKE_TOKEN_1051', $exception->getMessage());
        }
    }
}

/** SQLite no soporta FOR UPDATE; esta adaptación solo existe en la suite descartable. */
final class ConnectorRetrySqliteConnection extends Connection
{
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $query = preg_replace('/\s+FOR UPDATE\s*$/i', '', $query) ?? $query;
        return parent::fetchAssociative($query, $params, $types);
    }
}