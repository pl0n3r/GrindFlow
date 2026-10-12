<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\FacebookPageProvider;

/**
 * S4 local materializer. Never contacts Facebook, publishes a post, or
 * retries a provider outcome. Only the canonical reservation service writes.
 */
final readonly class PublishingAutomationRuleScheduler
{
    public function __construct(
        private Connection $db,
        private WeeklySlotCalculator $slots,
        private ScheduleDraftApplicationService $drafts,
        private FacebookPageProvider $facebook,
    ) {}

    /**
     * Called only for an explicitly selected organization. The operator-facing
     * command is dry-run unless --apply is supplied; no background cron exists.
     * @return array{status:string,created:int,existing:int,blocked:int}
     */
    public function materialize(string $organizationId, bool $apply = false): array
    {
        $result = static fn (string $status, int $created = 0, int $existing = 0, int $blocked = 0): array =>
            compact('status', 'created', 'existing', 'blocked');

        if (!$this->db->createSchemaManager()->tablesExist(['gf_content_rules', 'gf_schedule_drafts'])) {
            return $result('schema_unavailable');
        }
        $rule = $this->db->fetchAssociative(
            'SELECT * FROM gf_content_rules WHERE organization_id = :organization',
            ['organization' => $organizationId],
        );
        if ($rule === false || ($rule['automation_state'] ?? null) !== 'active') {
            return $result('paused');
        }
        if (($rule['mode'] ?? null) !== 'review_only'
            || ($rule['automation_destination_id'] ?? null) === null) {
            return $result('invalid_rule');
        }
        try {
            $this->facebook->assertAvailableFor($organizationId);
            if (!hash_equals($this->facebook->destinationPageId(), (string) $rule['automation_destination_id'])) {
                return $result('destination_unavailable');
            }
        } catch (DistributionProviderException) {
            return $result('destination_unavailable');
        }

        try {
            $times = json_decode((string) ($rule['automation_local_times'] ?? ''), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $result('invalid_rule');
        }
        if (!is_array($times) || $times === [] || count($times) > 12
            || count($times) > (int) $rule['max_per_day']
            || count(array_unique($times)) !== count($times)) {
            return $result('invalid_rule');
        }
        foreach ($times as $time) {
            if (!is_string($time) || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) !== 1) {
                return $result('invalid_rule');
            }
        }
        $rule['local_times'] = $times;
        $slots = $this->slots->upcoming($rule);
        if ($slots === []) {
            return $result('no_future_slots');
        }
        if (!$apply) {
            return $result('dry_run', 0, 0, 0);
        }

        $created = $existing = $blocked = 0;
        foreach (array_slice($slots, 0, 84) as $slot) {
            $stamp = str_replace(['T', 'Z'], [' ', ''], $slot['scheduled_at_utc']);
            // Existing manually prepared drafts already own this time slot.
            // The reservation service repeats this check under the org lock.
            $count = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id = :organization AND scheduled_at_utc = :scheduled AND status = 'draft'",
                ['organization' => $organizationId, 'scheduled' => $stamp],
            );
            if ($count > 0) {
                ++$existing;
                continue;
            }
            $last = $this->db->fetchOne(
                "SELECT asset_id FROM gf_schedule_drafts WHERE organization_id = :organization ORDER BY created_at DESC, id DESC LIMIT 1",
                ['organization' => $organizationId],
            );
            // Explicit tenant, soft-delete, finite pool; each candidate is
            // independently revalidated under lock by reserve().
            $candidates = $this->db->fetchFirstColumn(
                "SELECT id FROM gf_vault_assets WHERE organization_id = :organization AND deleted_at IS NULL ORDER BY created_at, id LIMIT 101",
                ['organization' => $organizationId],
            );
            if ($candidates === []) {
                ++$blocked;
                continue;
            }
            $hasMoreCandidates = count($candidates) > 100;
            $candidates = array_slice($candidates, 0, 100);
            if (count($candidates) > 1 && is_string($last)) {
                usort($candidates, static fn (mixed $a, mixed $b): int =>
                    ((string) $a === $last ? 1 : 0) <=> ((string) $b === $last ? 1 : 0));
            }
            $success = false;
            foreach ($candidates as $assetId) {
                $attempt = $this->drafts->reserve(
                    $organizationId, (string) $rule['updated_by'],
                    (string) $assetId, $slot['scheduled_at_utc'], true,
                );
                if ($attempt['status'] === 'ok') {
                    if ($attempt['changed']) {
                        ++$created;
                    } else {
                        ++$existing;
                    }
                    $success = true;
                    break;
                }
                if (in_array($attempt['status'], ['full', 'occupied'], true)) {
                    ++$existing;
                    $success = true;
                    break;
                }
                if ($attempt['status'] === 'revoked' || $attempt['status'] === 'stale_slot') {
                    // A paused/changed rule, revoked actor, or stale slot
                    // cancels this run instead of trying another account.
                    return $result('authorization_changed', $created, $existing, $blocked);
                }
            }
            if (!$success && $hasMoreCandidates) {
                // A valid resource may exist beyond the bounded scan.
                // Never advertise an empty pool based on an incomplete page.
                return $result('selection_incomplete', $created, $existing, $blocked);
            }
            if (!$success) {
                ++$blocked;
            }
        }
        return $result($blocked > 0 ? 'insufficient_content' : 'prepared', $created, $existing, $blocked);
    }
}
