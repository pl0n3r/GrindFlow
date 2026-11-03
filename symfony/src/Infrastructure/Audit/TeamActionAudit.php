<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Audit;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Tenant-scoped, metadata-only audit boundary for changes to team roles,
 * destinations and rules. Storage and authenticated identity are injected by
 * the server; this slice intentionally does not add persistence or HTTP routes.
 */
final class TeamActionAudit
{
    private const EVENTS = ['role_changed', 'destination_changed', 'rule_changed'];
    private const PAGE_LIMIT = 50;
    private const FIELDS = ['event_id', 'organization_id', 'actor_id', 'kind', 'target_id', 'occurred_at'];

    /**
     * @param Closure():array{organization_id:string,actor_id:string,role:string} $identity
     * @param Closure(array<string,string>):bool $append
     * @param Closure(string,int,int):array<int,array<string,mixed>> $fetch
     */
    public function __construct(
        private readonly Closure $identity,
        private readonly Closure $append,
        private readonly Closure $fetch,
    ) {
    }

    /** @return array<string,string> Only non-personal, whitelisted metadata. */
    public function record(string $kind, string $targetId): array
    {
        $principal = $this->principal();
        if (!in_array($principal['role'], ['owner', 'admin'], true)) {
            throw new RuntimeException('AUDIT_FORBIDDEN');
        }
        if (!in_array($kind, self::EVENTS, true) || !self::isUuid($targetId)) {
            throw new InvalidArgumentException('INVALID_AUDIT_EVENT');
        }

        $row = [
            'event_id' => bin2hex(random_bytes(16)),
            'organization_id' => $principal['organization_id'],
            'actor_id' => $principal['actor_id'],
            'kind' => $kind,
            'target_id' => strtolower($targetId),
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
        try {
            if (($this->append)($row) !== true) {
                throw new RuntimeException('AUDIT_STORAGE_FAILED');
            }
        } catch (Throwable) {
            // Never echo adapter errors: these may contain connection strings/PII.
            throw new RuntimeException('AUDIT_STORAGE_FAILED');
        }
        return $row;
    }

    /**
     * @return array{page:int,per_page:int,has_more:bool,events:list<array<string,string>>}
     */
    public function forOwner(int $page = 1, int $perPage = 20): array
    {
        $principal = $this->principal();
        if ($principal['role'] !== 'owner') {
            throw new RuntimeException('AUDIT_FORBIDDEN');
        }
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > self::PAGE_LIMIT) {
            throw new InvalidArgumentException('INVALID_AUDIT_PAGE');
        }

        try {
            $rows = ($this->fetch)($principal['organization_id'], $page, $perPage + 1);
        } catch (Throwable) {
            throw new RuntimeException('AUDIT_STORAGE_FAILED');
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $perPage + 1) {
            throw new RuntimeException('AUDIT_INVALID_ROWS');
        }

        $safeRows = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !self::validRow($row, $principal['organization_id'])) {
                throw new RuntimeException('AUDIT_INVALID_ROWS');
            }
            $safeRows[] = $row;
        }
        return [
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => count($safeRows) > $perPage,
            'events' => array_slice($safeRows, 0, $perPage),
        ];
    }

    /** @return array{organization_id:string,actor_id:string,role:string} */
    private function principal(): array
    {
        try {
            $identity = ($this->identity)();
        } catch (Throwable) {
            throw new RuntimeException('AUDIT_IDENTITY_UNAVAILABLE');
        }
        $keys = is_array($identity) ? array_keys($identity) : [];
        sort($keys);
        if (!is_array($identity) || $keys !== ['actor_id', 'organization_id', 'role']
            || !is_string($identity['organization_id']) || !self::isUuid($identity['organization_id'])
            || !is_string($identity['actor_id']) || !self::isUuid($identity['actor_id'])
            || !is_string($identity['role']) || !in_array($identity['role'], ['owner', 'admin', 'editor', 'viewer'], true)) {
            throw new RuntimeException('AUDIT_IDENTITY_UNAVAILABLE');
        }
        return $identity;
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value);
    }

    /** @param array<mixed> $row */
    private static function validRow(array $row, string $organization): bool
    {
        $keys = array_keys($row);
        $expected = self::FIELDS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected || !is_string($row['event_id'])
            || !preg_match('/^[a-f0-9]{32}$/D', $row['event_id'])
            || !is_string($row['organization_id']) || !self::isUuid($row['organization_id'])
            || strcasecmp($row['organization_id'], $organization) !== 0
            || !is_string($row['actor_id']) || !self::isUuid($row['actor_id'])
            || !is_string($row['kind']) || !in_array($row['kind'], self::EVENTS, true)
            || !is_string($row['target_id']) || !self::isUuid($row['target_id'])
            || !is_string($row['occurred_at'])) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $row['occurred_at'], new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $row['occurred_at'];
    }
}
