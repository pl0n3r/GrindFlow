<?php

declare(strict_types=1);

namespace Killswitchtest;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * #792: contrato OFFLINE del interruptor de emergencia.
 * Esta referencia vive solo en tests; NO conecta publicadores, cron, HTTP,
 * credenciales, sesiones ni proveedores reales del producto.
 */
final class Tests extends TestCase
{
    public function test_criterio_principal(): void
    {
        $audit = new FakeEmergencyAudit();
        $revoker = new FakeAccessRevoker();
        $switch = new EmergencyStopReference($audit, $revoker, false);

        self::assertTrue($switch->mayPublish('tenant_a'));
        self::assertTrue($switch->mayRunScheduledTask('tenant_b'));

        $switch->engage('admin', 'operator_1', 'safety drill', self::TIME);
        foreach (['tenant_a', 'tenant_b'] as $tenant) {
            self::assertFalse($switch->mayPublish($tenant));
            self::assertFalse($switch->mayRunScheduledTask($tenant));
        }
        self::assertTrue($revoker->allRevoked());
        self::assertSame(
            ['session_a', 'session_b', 'token_a', 'token_b'],
            array_keys($revoker->revoked)
        );
        self::assertSame([
            'action' => 'engage', 'actor' => 'operator_1',
            'at' => self::TIME, 'reason' => 'safety drill', 'outcome' => 'accepted',
        ], $audit->events[0]);

        $switch->restore('admin', 'operator_1', 'drill complete', self::TIME);
        self::assertTrue($switch->mayPublish('tenant_a'));
        self::assertTrue($switch->mayRunScheduledTask('tenant_b'));
        self::assertSame('restore', $audit->events[1]['action']);
        self::assertSame('drill complete', $audit->events[1]['reason']);
        self::assertCount(2, $audit->events);
    }

    private const TIME = '2026-11-02T12:00:00Z';

    public function test_unknown_state_and_unauthorized_restore_fail_closed(): void
    {
        $audit = new FakeEmergencyAudit();
        $switch = new EmergencyStopReference($audit, new FakeAccessRevoker(), null);
        self::assertFalse($switch->mayPublish('tenant_a'));
        self::assertFalse($switch->mayRunScheduledTask('tenant_b'));
        try {
            $switch->restore('admin', 'operator_1', 'unsafe reset', self::TIME);
            self::fail('UNKNOWN cannot be silently converted into RUNNING');
        } catch (LogicException $e) {
            self::assertSame('state_unknown', $e->getMessage());
        }

        $switch->engage('admin', 'operator_1', 'incident response', self::TIME);
        try {
            $switch->restore('creator', 'creator_a', 'attempt to bypass', self::TIME);
            self::fail('Only administrator can restore');
        } catch (LogicException $e) {
            self::assertSame('admin_required', $e->getMessage());
        }
        self::assertFalse($switch->mayPublish('tenant_a'));
        self::assertSame('denied', $audit->last()['outcome']);
    }

    public function test_revocation_failure_keeps_stop_and_retry_is_idempotent(): void
    {
        $audit = new FakeEmergencyAudit();
        $revoker = new FakeAccessRevoker();
        $revoker->failTokenOnce = true;
        $switch = new EmergencyStopReference($audit, $revoker, false);

        try {
            $switch->engage('admin', 'operator_1', 'token incident', self::TIME);
            self::fail('Synthetic revocation fault should be observable');
        } catch (RuntimeException $e) {
            self::assertSame('synthetic_token_revocation_failure', $e->getMessage());
        }
        self::assertFalse($switch->mayPublish('tenant_a'));
        self::assertFalse($switch->mayRunScheduledTask('tenant_b'));
        self::assertFalse($revoker->allRevoked());

        try {
            $switch->restore('admin', 'operator_1', 'early restore', self::TIME);
            self::fail('Unfinished revocation must veto restore');
        } catch (LogicException $e) {
            self::assertSame('revocations_incomplete', $e->getMessage());
        }
        self::assertFalse($switch->mayPublish('tenant_a'));

        // Re-engage must retry partial revocation, not return early because STOP is on.
        $switch->engage('admin', 'operator_1', 'retry revoke', self::TIME);
        self::assertTrue($revoker->allRevoked());
        self::assertCount(4, $revoker->revoked);
        self::assertSame(['engage', 'restore', 'engage'], array_column($audit->events, 'action'));
        self::assertSame(['accepted', 'denied', 'accepted'], array_column($audit->events, 'outcome'));
    }

    public function test_audit_failure_is_fail_closed_and_no_external_calls_exist(): void
    {
        $audit = new FakeEmergencyAudit();
        $audit->unavailable = true;
        $revoker = new FakeAccessRevoker();
        $switch = new EmergencyStopReference($audit, $revoker, false);
        try {
            $switch->engage('admin', 'operator_1', 'audit outage', self::TIME);
            self::fail('Audit failure must not be interpreted as success');
        } catch (RuntimeException $e) {
            self::assertSame('audit_unavailable', $e->getMessage());
        }
        self::assertFalse($switch->mayPublish('tenant_a'));
        self::assertFalse($switch->mayRunScheduledTask('tenant_b'));
        self::assertSame([], $revoker->revoked);
        self::assertSame([], $audit->events);
    }

    public function test_tenant_fixtures_and_audit_never_leak_secret_payloads(): void
    {
        $audit = new FakeEmergencyAudit();
        $revoker = new FakeAccessRevoker();
        $switch = new EmergencyStopReference($audit, $revoker, false);
        try {
            $switch->engage('creator', 'creator_a', 'not authorized', self::TIME);
            self::fail('Non-admin must not toggle global stop');
        } catch (LogicException $e) {
            self::assertSame('admin_required', $e->getMessage());
        }
        self::assertTrue($switch->mayPublish('tenant_a'));
        self::assertSame('denied', $audit->last()['outcome']);

        try {
            $switch->engage('admin', 'operator_1', 'secret=synthetic-private-token', self::TIME);
            self::fail('Unsafe audit reason should be rejected');
        } catch (LogicException $e) {
            self::assertSame('invalid_reason', $e->getMessage());
        }
        $switch->engage('admin', 'operator_1', 'security exercise', self::TIME);
        self::assertFalse($switch->mayPublish('tenant_a'));
        self::assertFalse($switch->mayPublish('tenant_b'));
        self::assertCount(4, $revoker->revoked);
        $json = json_encode($audit->events, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-private-token', $json);
        self::assertStringNotContainsString('Authorization', $json);
        self::assertStringNotContainsString('Bearer', $json);
        foreach ($audit->events as $event) {
            self::assertSame(['action', 'actor', 'at', 'reason', 'outcome'], array_keys($event));
        }
    }
}

/** Pure offline reference, not a Symfony runtime service. */
final class EmergencyStopReference
{
    public function __construct(
        private readonly FakeEmergencyAudit $audit,
        private readonly FakeAccessRevoker $revoker,
        private ?bool $stopped,
    ) {
    }

    public function mayPublish(string $tenant): bool
    {
        return $this->knownTenant($tenant) && $this->stopped === false;
    }

    public function mayRunScheduledTask(string $tenant): bool
    {
        return $this->knownTenant($tenant) && $this->stopped === false;
    }

    public function engage(string $role, string $actor, string $reason, string $at): void
    {
        $this->requireAdmin($role, 'engage', $actor, $at);
        $this->validateFields($actor, $reason, $at);
        // Fail closed BEFORE writing the audit or revoking simulated credentials.
        $this->stopped = true;
        $this->audit->append($this->event('engage', $actor, $at, $reason, 'accepted'));
        // Always retry even when stopped=true: partial revocations are idempotent.
        $this->revoker->revokeAll();
    }

    public function restore(string $role, string $actor, string $reason, string $at): void
    {
        $this->requireAdmin($role, 'restore', $actor, $at);
        $this->validateFields($actor, $reason, $at);
        if ($this->stopped === null) {
            throw new LogicException('state_unknown');
        }
        if ($this->stopped !== true || !$this->revoker->allRevoked()) {
            $this->audit->append($this->event('restore', $actor, $at, $reason, 'denied'));
            throw new LogicException('revocations_incomplete');
        }
        // Audit first: never resume while persistence of the decision failed.
        $this->audit->append($this->event('restore', $actor, $at, $reason, 'accepted'));
        $this->stopped = false;
    }

    private function requireAdmin(string $role, string $action, string $actor, string $at): void
    {
        if ($role === 'admin') {
            return;
        }
        $safeActor = preg_match('/\A[a-zA-Z0-9_-]{1,48}\z/D', $actor) === 1 ? $actor : 'UNKNOWN';
        $this->audit->append($this->event($action, $safeActor, $at, 'unauthorized', 'denied'));
        throw new LogicException('admin_required');
    }

    private function validateFields(string $actor, string $reason, string $at): void
    {
        if (preg_match('/\A[a-zA-Z0-9_-]{1,48}\z/D', $actor) !== 1) {
            throw new LogicException('invalid_actor');
        }
        if (preg_match('/\A[a-zA-Z0-9 _-]{3,80}\z/D', $reason) !== 1) {
            throw new LogicException('invalid_reason');
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $at) !== 1) {
            throw new LogicException('invalid_timestamp');
        }
    }

    private function knownTenant(string $tenant): bool
    {
        return in_array($tenant, ['tenant_a', 'tenant_b'], true);
    }

    /** @return array<string, string> */
    private function event(string $action, string $actor, string $at, string $reason, string $outcome): array
    {
        return [
            'action' => $action, 'actor' => $actor, 'at' => $at,
            'reason' => $reason, 'outcome' => $outcome,
        ];
    }
}

final class FakeEmergencyAudit
{
    /** @var list<array<string, string>> */
    public array $events = [];
    public bool $unavailable = false;

    /** @param array<string, string> $event */
    public function append(array $event): void
    {
        if ($this->unavailable) {
            throw new RuntimeException('audit_unavailable');
        }
        $this->events[] = $event;
    }

    /** @return array<string, string> */
    public function last(): array
    {
        return $this->events[array_key_last($this->events)];
    }
}

/** Fake only: synthetic tenant IDs and session/token identifiers. */
final class FakeAccessRevoker
{
    /** @var array<string, list<string>> */
    private array $sessions = [
        'tenant_a' => ['session_a'], 'tenant_b' => ['session_b'],
    ];
    /** @var array<string, list<string>> */
    private array $tokens = [
        'tenant_a' => ['token_a'], 'tenant_b' => ['token_b'],
    ];

    /** @var array<string, bool> */
    public array $revoked = [];
    public bool $failTokenOnce = false;

    public function revokeAll(): void
    {
        foreach ($this->sessions as $ids) {
            foreach ($ids as $id) {
                $this->revoked[$id] = true;
            }
        }
        if ($this->failTokenOnce) {
            $this->failTokenOnce = false;
            throw new RuntimeException('synthetic_token_revocation_failure');
        }
        foreach ($this->tokens as $ids) {
            foreach ($ids as $id) {
                $this->revoked[$id] = true;
            }
        }
    }

    public function allRevoked(): bool
    {
        return count($this->revoked) === 4;
    }
}
