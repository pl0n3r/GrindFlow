<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * A pure, tenant-scoped policy selector. No network calls or persistence.
 * Only trusted server-side actors may supply a Studio override; frontend RBAC
 * is never an authorization source.
 */
final class DestinationFallbackPolicy
{
    /** @var array<string,list<string>> */
    private const DEFAULTS = [
        'facebook_page' => ['vertical', 'square'],
        'instagram_reels' => ['vertical'],
    ];

    /** @return list<string> */
    public function recommended(string $provider): array
    {
        return self::DEFAULTS[$provider] ?? [];
    }

    /**
     * @param list<array<string,mixed>> $overrides  Server-side policy rows.
     * @param callable(string,string,string,string):bool $canManageStudioPolicy
     * @return array{order:list<string>,origin:string}
     */
    public function select(
        string $organizationId,
        string $actorId,
        string $creatorId,
        string $destinationId,
        string $provider,
        string $role,
        array $overrides,
        callable $canManageStudioPolicy,
    ): array {
        if (!Uuid::isValid($organizationId) || !Uuid::isValid($actorId)
            || !Uuid::isValid($creatorId) || !preg_match('/^\d{1,32}$/D', $destinationId)
            || !in_array($role, ['independent', 'studio'], true)
            || !array_key_exists($provider, self::DEFAULTS)) {
            throw new InvalidArgumentException('Invalid fallback policy context.');
        }

        $order = self::DEFAULTS[$provider];
        if ($overrides === []) {
            return ['order' => $order, 'origin' => 'recommended'];
        }
        // Do not silently accept an override from a browser or a different
        // organization. Even non-matching rows must be proven tenant-owned.
        if ($role !== 'studio'
            || $canManageStudioPolicy($organizationId, $actorId, $creatorId, $destinationId) !== true
            || count($overrides) > 30) {
            throw new InvalidArgumentException('Studio policy is not authorized.');
        }

        $matching = [];
        foreach ($overrides as $row) {
            if (!is_array($row) || array_keys($row) !== ['organization_id', 'scope', 'subject_id', 'order']
                || ($row['organization_id'] ?? null) !== $organizationId
                || !in_array($row['scope'] ?? null, ['studio', 'creator', 'account'], true)
                || !is_string($row['subject_id'] ?? null)
                || !is_array($row['order'] ?? null)) {
                throw new InvalidArgumentException('Invalid Studio policy row.');
            }
            $scope = $row['scope'];
            $subject = $row['subject_id'];
            if (($scope === 'studio' && $subject !== $organizationId)
                || ($scope === 'creator' && !Uuid::isValid($subject))
                || ($scope === 'account' && !preg_match('/^\d{1,32}$/D', $subject))) {
                throw new InvalidArgumentException('Invalid Studio policy scope.');
            }
            $formats = $row['order'];
            if (!array_is_list($formats) || count($formats) > 2
                || count(array_filter($formats, 'is_string')) !== count($formats)
                || count($formats) !== count(array_unique($formats))
                || array_diff($formats, self::DEFAULTS[$provider]) !== []) {
                throw new InvalidArgumentException('Incompatible Studio policy order.');
            }
            if ($scope === 'studio' || ($scope === 'creator' && $subject === $creatorId)
                || ($scope === 'account' && $subject === $destinationId)) {
                $priority = ['studio' => 1, 'creator' => 2, 'account' => 3][$scope];
                if (isset($matching[$priority])) {
                    throw new InvalidArgumentException('Conflicting Studio policy rows.');
                }
                $matching[$priority] = $formats;
            }
        }
        if ($matching === []) {
            return ['order' => $order, 'origin' => 'recommended'];
        }
        ksort($matching);
        // An empty order is an explicit deny, never an implicit default.
        return ['order' => end($matching), 'origin' => 'studio_override'];
    }
}
