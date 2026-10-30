<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Audit\TeamActionAudit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** No database, network or real identities. */
final class TeamActionAuditTest extends TestCase
{
    private const TENANT = '11111111-1111-4111-8111-111111111111';
    private const FOREIGN = '22222222-2222-4222-8222-222222222222';
    private const ACTOR = '33333333-3333-4333-8333-333333333333';
    private const TARGET = '44444444-4444-4444-8444-444444444444';

    public function testRecordsOnlySafeEventTypesWithServerIdentityAndUtc(): void
    {
        $rows = [];
        $audit = $this->audit('admin', $rows);
        foreach (['role_changed', 'destination_changed', 'rule_changed'] as $kind) {
            $before = time();
            $event = $audit->record($kind, self::TARGET);
            $after = time();
            self::assertSame(self::TENANT, $event['organization_id']);
            self::assertSame(self::ACTOR, $event['actor_id']);
            self::assertSame($kind, $event['kind']);
            self::assertSame(self::TARGET, $event['target_id']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $event['event_id']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $event['occurred_at']);
            $instant = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $event['occurred_at'], new \DateTimeZone('UTC'));
            self::assertInstanceOf(\DateTimeImmutable::class, $instant);
            self::assertGreaterThanOrEqual($before, $instant->getTimestamp());
            self::assertLessThanOrEqual($after, $instant->getTimestamp());
            self::assertSame(['event_id', 'organization_id', 'actor_id', 'kind', 'target_id', 'occurred_at'], array_keys($event));
        }
        self::assertCount(3, $rows);
        self::assertCount(3, array_unique(array_column($rows, 'event_id')));
    }

    public function testInvalidEventTypesAndTargetsNeverReachStorage(): void
    {
        $rows = [];
        $audit = $this->audit('owner', $rows);
        foreach ([['role_changed', 'malformed-id'], ['target-secret', self::TARGET],
            ['rule_changed', 'person@example.test'], ['role_changed', 'token=synthetic-secret']] as [$kind, $target]) {
            try {
                $audit->record($kind, $target);
                self::fail('Untrusted event must not be accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('INVALID_AUDIT_EVENT', $e->getMessage());
            }
        }
        self::assertSame([], $rows);
    }

    public function testOnlyOwnerOrAdminMayAppendAndOnlyOwnerMayRead(): void
    {
        foreach (['editor', 'viewer'] as $role) {
            $rows = [];
            $audit = $this->audit($role, $rows);
            $this->expectSafeError('AUDIT_FORBIDDEN', static fn () => $audit->record('role_changed', self::TARGET));
            $this->expectSafeError('AUDIT_FORBIDDEN', static fn () => $audit->forOwner());
            self::assertSame([], $rows);
        }
        $rows = [];
        $admin = $this->audit('admin', $rows);
        $admin->record('destination_changed', self::TARGET);
        $this->expectSafeError('AUDIT_FORBIDDEN', static fn () => $admin->forOwner());
        self::assertCount(1, $rows);
    }

    public function testOwnerReadsOnlyTenantScopedBoundedPages(): void
    {
        $rows = [];
        $audit = $this->audit('owner', $rows);
        for ($i = 0; $i < 62; ++$i) {
            $audit->record('rule_changed', self::TARGET);
        }
        $first = $audit->forOwner(1, 25);
        $second = $audit->forOwner(2, 25);
        $third = $audit->forOwner(3, 25);
        self::assertSame([1, 25, true], [$first['page'], $first['per_page'], $first['has_more']]);
        self::assertSame([2, 25, true], [$second['page'], $second['per_page'], $second['has_more']]);
        self::assertSame([3, 25, false], [$third['page'], $third['per_page'], $third['has_more']]);
        self::assertCount(25, $first['events']);
        self::assertCount(25, $second['events']);
        self::assertCount(12, $third['events']);
        $events = array_merge($first['events'], $second['events'], $third['events']);
        foreach ($events as $event) {
            self::assertSame(self::TENANT, $event['organization_id']);
        }
        $ids = array_column($events, 'event_id');
        self::assertCount(62, array_unique($ids));
        self::assertSame(array_column($rows, 'event_id'), $ids);
        foreach ([[0, 1], [1, 0], [1, 51], [100001, 2]] as [$page, $size]) {
            $this->expectSafeError('INVALID_AUDIT_PAGE', static fn () => $audit->forOwner($page, $size));
        }
    }

    public function testForeignTenantAndUnexpectedFieldsFailClosed(): void
    {
        foreach ([
            static fn (array $row): array => array_replace($row, ['organization_id' => self::FOREIGN]),
            static fn (array $row): array => array_merge($row, ['notes' => 'not allowed']),
            static fn (array $row): array => array_replace($row, ['kind' => 'arbitrary']),
            static fn (array $row): array => array_replace($row, ['occurred_at' => '2026-13-50T23:99:99Z']),
        ] as $mutate) {
            $rows = [];
            $audit = $this->audit('owner', $rows, $mutate);
            $audit->record('role_changed', self::TARGET);
            $this->expectSafeError('AUDIT_INVALID_ROWS', static fn () => $audit->forOwner());
        }
    }

    public function testSensitiveDataHiddenInAllowlistedFieldsNeverLeaks(): void
    {
        foreach (['actor_id' => 'person@example.test', 'target_id' => 'synthetic-secret',
            'event_id' => 'private-secret', 'occurred_at' => '2026-10-30 private'] as $field => $secret) {
            $rows = [];
            $audit = $this->audit('owner', $rows, static fn (array $row): array => array_replace($row, [$field => $secret]));
            $audit->record('destination_changed', self::TARGET);
            $this->expectSafeError('AUDIT_INVALID_ROWS', static fn () => $audit->forOwner());
        }
    }


    public function testAcceptsUnorderedIdentityAndRowKeysAndCanonicalTenantCase(): void
    {
        $tenantId = 'aabbccdd-eeff-4abc-8def-abcdefabcdef';
        $rows = [];
        $audit = new TeamActionAudit(
            static fn (): array => [
                'role' => 'owner',
                'actor_id' => self::ACTOR,
                'organization_id' => strtoupper($tenantId),
            ],
            static function (array $row) use (&$rows): bool {
                $rows[] = $row;
                return true;
            },
            static function (string $tenant, int $page, int $limit) use (&$rows, $tenantId): array {
                self::assertSame(strtoupper($tenantId), $tenant);
                return array_map(
                    static function (array $row): array {
                        $reordered = array_reverse($row, true);
                        $reordered['organization_id'] = strtolower($reordered['organization_id']);
                        return $reordered;
                    },
                    array_slice($rows, ($page - 1) * ($limit - 1), $limit),
                );
            },
        );
        $written = $audit->record('role_changed', self::TARGET);
        self::assertSame(strtoupper($tenantId), $written['organization_id']);
        $results = $audit->forOwner();
        self::assertSame(1, count($results['events']));
        self::assertSame($tenantId, $results['events'][0]['organization_id']);
        self::assertSame('role_changed', $results['events'][0]['kind']);
    }

    public function testAdapterFailureAndInvalidIdentityHidePrivateErrors(): void
    {
        $principal = static fn (): array => [
            'organization_id' => self::TENANT, 'actor_id' => self::ACTOR, 'role' => 'owner',
        ];
        $storage = new TeamActionAudit($principal, static function (): bool {
            throw new RuntimeException('private synthetic connection password');
        }, static fn (): array => []);
        $this->expectSafeError('AUDIT_STORAGE_FAILED', static fn () => $storage->record('rule_changed', self::TARGET));
        $rejected = new TeamActionAudit($principal, static fn (): bool => false, static fn (): array => []);
        $this->expectSafeError('AUDIT_STORAGE_FAILED', static fn () => $rejected->record('rule_changed', self::TARGET));
        $read = new TeamActionAudit($principal, static fn (): bool => true, static function (): array {
            throw new RuntimeException('private synthetic database secret');
        });
        $this->expectSafeError('AUDIT_STORAGE_FAILED', static fn () => $read->forOwner());
        $bad = new TeamActionAudit(static fn (): array => [
            'organization_id' => self::TENANT, 'actor_id' => 'private@example.test', 'role' => 'owner',
        ], static fn (): bool => true, static fn (): array => []);
        $this->expectSafeError('AUDIT_IDENTITY_UNAVAILABLE', static fn () => $bad->forOwner());
    }

    /** @param list<array<string,string>> $rows */
    private function audit(string $role, array &$rows, ?\Closure $mutate = null): TeamActionAudit
    {
        return new TeamActionAudit(
            static fn (): array => ['organization_id' => self::TENANT, 'actor_id' => self::ACTOR, 'role' => $role],
            static function (array $event) use (&$rows): bool {
                $rows[] = $event;
                return true;
            },
            static function (string $tenant, int $page, int $limit) use (&$rows, $mutate): array {
                if ($tenant !== self::TENANT) {
                    throw new RuntimeException('wrong tenant');
                }
                return array_map(
                    static fn (array $row): array => $mutate ? $mutate($row) : $row,
                    array_slice($rows, ($page - 1) * ($limit - 1), $limit),
                );
            },
        );
    }

    private function expectSafeError(string $expected, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe operation must fail.');
        } catch (RuntimeException | InvalidArgumentException $e) {
            self::assertSame($expected, $e->getMessage());
        }
    }
}
