<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionFanoutService;
use GrindFlow\Distribution\DistributionOutcome;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\ExternalPublicationLedger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Synthetic-only: SQLite memory, fake provider callbacks, no credentials or network. */
final class DistributionFanoutTest extends TestCase
{
    private FanoutSqliteConnection $db;
    private DistributionFanoutService $fanout;
    private string $organization;
    private string $actor;
    private string $draft;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        /** @var FanoutSqliteConnection $db */
        $db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite', 'memory' => true,
            'wrapperClass' => FanoutSqliteConnection::class,
        ]);
        $this->db = $db;
        $this->organization = Uuid::v7()->toRfc4122();
        $this->actor = Uuid::v7()->toRfc4122();
        $this->draft = Uuid::v7()->toRfc4122();
        $this->now = new DateTimeImmutable('2026-11-05T12:00:00Z');

        foreach ([
            'CREATE TABLE gf_emergency_stop (id INTEGER PRIMARY KEY, state TEXT NOT NULL, revision INTEGER NOT NULL, revocation_complete INTEGER NOT NULL)',
            'CREATE TABLE gf_identity_users (id TEXT PRIMARY KEY, is_active INTEGER NOT NULL)',
            'CREATE TABLE gf_identity_organizations (id TEXT PRIMARY KEY)',
            'CREATE TABLE gf_identity_memberships (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, organization_id TEXT NOT NULL, role TEXT NOT NULL)',
            'CREATE TABLE gf_schedule_drafts (id TEXT PRIMARY KEY, organization_id TEXT NOT NULL, asset_id TEXT NOT NULL, caption TEXT, status TEXT NOT NULL, delivery_locked_at TEXT)',
            'CREATE TABLE gf_distribution_fanout_groups (id TEXT PRIMARY KEY, organization_id TEXT NOT NULL, draft_id TEXT NOT NULL, request_key TEXT NOT NULL, request_fingerprint TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(organization_id, draft_id, request_key), UNIQUE(organization_id, draft_id))',
            'CREATE TABLE gf_distribution_fanout_deliveries (id TEXT PRIMARY KEY, group_id TEXT NOT NULL, organization_id TEXT NOT NULL, provider TEXT NOT NULL, destination_id TEXT NOT NULL, idempotency_key TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(group_id, provider, destination_id), UNIQUE(organization_id, provider, idempotency_key))',
            'CREATE TABLE gf_external_publication_attempts (id TEXT PRIMARY KEY, organization_id TEXT NOT NULL, provider TEXT NOT NULL, idempotency_key TEXT NOT NULL, request_fingerprint TEXT NOT NULL, status TEXT NOT NULL, external_publication_id TEXT, retry_after_seconds INTEGER, retry_not_before TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(organization_id, provider, idempotency_key))',
        ] as $sql) {
            $db->executeStatement($sql);
        }
        $db->insert('gf_emergency_stop', ['id' => 1, 'state' => 'RUNNING', 'revision' => 1, 'revocation_complete' => 1]);
        $db->insert('gf_identity_users', ['id' => $this->actor, 'is_active' => 1]);
        $db->insert('gf_identity_organizations', ['id' => $this->organization]);
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $this->actor,
            'organization_id' => $this->organization, 'role' => 'studio',
        ]);
        $db->insert('gf_schedule_drafts', [
            'id' => $this->draft, 'organization_id' => $this->organization,
            'asset_id' => Uuid::v7()->toRfc4122(), 'caption' => 'Texto explícito para fan-out sintético',
            'status' => 'draft', 'delivery_locked_at' => null,
        ]);
        $clock = fn (): DateTimeImmutable => $this->now;
        $this->fanout = new DistributionFanoutService($db, new ExternalPublicationLedger($db, $clock));
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    /** @return list<array{provider:string,destination_id:string}> */
    private function destinations(): array
    {
        return [
            ['provider' => 'facebook_page', 'destination_id' => '101'],
            ['provider' => 'facebook_page', 'destination_id' => '102'],
            ['provider' => 'facebook_page', 'destination_id' => '103'],
        ];
    }

    /** @return array<string,mixed> */
    private function group(): array
    {
        return $this->fanout->prepare(
            $this->organization, $this->actor, $this->draft,
            'request-fanout-0001', $this->destinations(),
            static fn (): bool => true,
        );
    }

    public function testThreeIndependentDestinationsRecoverOnlyFailedC(): void
    {
        $group = $this->group();
        self::assertSame(3, $group['total']);
        self::assertSame(0, $group['sent']);
        self::assertSame(3, $group['pending']);
        $calls = [];
        $failedC = false;
        $authorize = static fn (string $org, string $provider, string $id): bool => true;
        $send = static function (DistributionCommand $command, string $provider, string $id) use (&$calls, &$failedC): DistributionOutcome {
            $calls[] = $id;
            if ($id === '103' && !$failedC) {
                $failedC = true;
                throw DistributionProviderException::rateLimited(120);
            }
            return new DistributionOutcome('receipt-'.$id);
        };
        $deliveries = $group['deliveries'];
        foreach ([$deliveries[0], $deliveries[1]] as $delivery) {
            $result = $this->fanout->deliver(
                $this->organization, $this->actor, $group['id'], $delivery['id'],
                $authorize, $send,
            );
        }
        self::assertSame(2, $result['sent']);
        self::assertSame(1, $result['pending']);

        try {
            $this->fanout->deliver(
                $this->organization, $this->actor, $group['id'], $deliveries[2]['id'],
                $authorize, $send,
            );
            self::fail('El fake debe rate-limit C.');
        } catch (DistributionProviderException $exception) {
            self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $exception->kind);
        }
        $failed = $this->fanout->summary($this->organization, $this->actor, $group['id']);
        self::assertSame(2, $failed['sent']);
        self::assertSame(1, $failed['failed']);
        self::assertSame(['101', '102', '103'], $calls);
        $this->now = $this->now->modify('+121 seconds');
        $recovered = $this->fanout->deliver(
            $this->organization, $this->actor, $group['id'], $deliveries[2]['id'],
            $authorize, $send,
        );
        self::assertSame(3, $recovered['sent']);
        self::assertSame(0, $recovered['failed']);
        self::assertSame(['101', '102', '103', '103'], $calls);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_external_publication_attempts'));
        self::assertCount(3, array_unique(array_column(
            $this->db->fetchAllAssociative('SELECT idempotency_key FROM gf_distribution_fanout_deliveries'),
            'idempotency_key',
        )));
    }

    public function testDuplicateRequestPreservesGroupAndDeliveryIdentities(): void
    {
        $first = $this->group();
        $again = $this->fanout->prepare(
            $this->organization, $this->actor, $this->draft,
            'request-fanout-0001', array_reverse($this->destinations()),
            static fn (): bool => true,
        );
        self::assertSame($first, $again);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_distribution_fanout_groups'));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_distribution_fanout_deliveries'));

        try {
            $this->fanout->prepare(
                $this->organization, $this->actor, $this->draft,
                'another-request-0001', $this->destinations(),
                static fn (): bool => true,
            );
            self::fail('A different request key cannot create a second group for the same draft.');
        } catch (\LogicException) {
            // One durable fan-out group per draft; concurrent requests cannot duplicate it.
        }
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_distribution_fanout_groups'));

        $this->expectException(\LogicException::class);
        $this->fanout->prepare(
            $this->organization, $this->actor, $this->draft, 'request-fanout-0001',
            [ $this->destinations()[0] ],
            static fn (): bool => true,
        );
    }

    public function testRevokedActorAndForeignTenantFailBeforeProviderIo(): void
    {
        $group = $this->group();
        $calls = 0;
        $authorize = static fn (string $org, string $provider, string $id): bool => true;
        $send = static function () use (&$calls): DistributionOutcome {
            ++$calls;
            return new DistributionOutcome('should-not-send');
        };
        try {
            $this->fanout->summary(Uuid::v7()->toRfc4122(), $this->actor, $group['id']);
            self::fail('Foreign tenant must not read group.');
        } catch (\LogicException) {
            // Expected.
        }
        $this->db->update('gf_identity_users', ['is_active' => 0], ['id' => $this->actor]);
        try {
            $this->fanout->deliver(
                $this->organization, $this->actor, $group['id'], $group['deliveries'][0]['id'],
                $authorize, $send,
            );
            self::fail('Revoked actor must not call providers.');
        } catch (\LogicException) {
            // Expected.
        }
        self::assertSame(0, $calls);
    }

    public function testBlockedDestinationAndAmbiguousAttemptNeverBlindlyRetry(): void
    {
        $group = $this->group();
        $calls = 0;
        $send = static function () use (&$calls): DistributionOutcome {
            ++$calls;
            throw DistributionProviderException::ambiguous();
        };
        $delivery = $group['deliveries'][0]['id'];
        try {
            $this->fanout->deliver(
                $this->organization, $this->actor, $group['id'], $delivery,
                static fn (): bool => false, $send,
            );
            self::fail('Unverified destination must not send.');
        } catch (DistributionProviderException $exception) {
            self::assertSame(DistributionProviderException::KIND_AUTHENTICATION, $exception->kind);
        }
        self::assertSame(0, $calls);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $this->fanout->deliver(
                    $this->organization, $this->actor, $group['id'], $delivery,
                    static fn (): bool => true, $send,
                );
                self::fail('Ambiguous result must not be retried.');
            } catch (DistributionProviderException $exception) {
                self::assertSame(DistributionProviderException::KIND_AMBIGUOUS, $exception->kind);
            }
        }
        self::assertSame(1, $calls);
        self::assertSame('ambiguous',
            $this->fanout->summary($this->organization, $this->actor, $group['id'])['deliveries'][0]['status']);
    }


    public function testUnverifiedDestinationCannotPrepareAnyGroup(): void
    {
        $attempts = 0;
        try {
            $this->fanout->prepare(
                $this->organization, $this->actor, $this->draft,
                'denied-provider-0001', $this->destinations(),
                static function (string $org, string $provider, string $destination) use (&$attempts): bool {
                    ++$attempts;
                    return false;
                },
            );
            self::fail('A destination without proven ownership must not be queued.');
        } catch (DistributionProviderException $exception) {
            self::assertSame(DistributionProviderException::KIND_AUTHENTICATION, $exception->kind);
        }
        self::assertSame(1, $attempts);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_distribution_fanout_groups'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_distribution_fanout_deliveries'));
    }

    public function testEmergencyStopPreventsEvenQueueCreation(): void
    {
        $this->db->update('gf_emergency_stop', ['state' => 'PAUSED', 'revision' => 2], ['id' => 1]);
        try {
            $this->group();
            self::fail('Emergency stop must fail closed.');
        } catch (\Throwable $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM gf_distribution_fanout_groups'));
    }
}

/** SQLite does not support row-lock syntax; production MariaDB keeps FOR UPDATE. */
final class FanoutSqliteConnection extends Connection
{
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $query = preg_replace('/\\s+FOR UPDATE\\s*$/i', '', $query) ?? $query;
        return parent::fetchAssociative($query, $params, $types);
    }
}
