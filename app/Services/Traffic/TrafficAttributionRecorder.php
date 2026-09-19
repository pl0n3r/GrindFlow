<?php

namespace App\Services\Traffic;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TrafficAttributionRecorder
{
    private const DEDUPE_MINUTES = 10;

    private const DEDUPE_RETENTION_HOURS = 24;

    public function record(
        string $organizationId,
        string $trackedLinkId,
        string $visitorHash,
        CarbonImmutable $clickedAt,
    ): bool {
        if (
            Schema::hasTable('tracked_links') === false
            || Schema::hasTable('tracked_link_daily_metrics') === false
            || Schema::hasTable('tracked_link_dedupes') === false
            || preg_match('/^[a-f0-9]{64}$/', $visitorHash) !== 1
        ) {
            return false;
        }

        return DB::transaction(function () use (
            $organizationId,
            $trackedLinkId,
            $visitorHash,
            $clickedAt,
        ): bool {
            $linkExists = DB::table('tracked_links')
                ->where('id', $trackedLinkId)
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->exists();

            if ($linkExists === false) {
                return false;
            }

            $now = $clickedAt->setTimezone('UTC');
            $timestamp = $now->format('Y-m-d H:i:s');

            DB::table('tracked_link_dedupes')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'organization_id' => $organizationId,
                'tracked_link_id' => $trackedLinkId,
                'visitor_hash' => $visitorHash,
                'last_counted_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $dedupe = DB::table('tracked_link_dedupes')
                ->where('organization_id', $organizationId)
                ->where('tracked_link_id', $trackedLinkId)
                ->where('visitor_hash', $visitorHash)
                ->lockForUpdate()
                ->first(['id', 'last_counted_at']);

            if ($dedupe === null) {
                return false;
            }

            if (
                is_string($dedupe->last_counted_at)
                && CarbonImmutable::parse(
                    $dedupe->last_counted_at,
                    'UTC',
                )->isAfter(
                    $now->subMinutes(self::DEDUPE_MINUTES),
                )
            ) {
                return false;
            }

            DB::table('tracked_link_dedupes')
                ->where('id', $dedupe->id)
                ->update([
                    'last_counted_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

            DB::table('tracked_link_daily_metrics')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'organization_id' => $organizationId,
                'tracked_link_id' => $trackedLinkId,
                'metric_date' => $now->toDateString(),
                'clicks' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            DB::table('tracked_link_daily_metrics')
                ->where('organization_id', $organizationId)
                ->where('tracked_link_id', $trackedLinkId)
                ->where('metric_date', $now->toDateString())
                ->increment('clicks', 1, [
                    'updated_at' => $timestamp,
                ]);

            return true;
        });
    }

    public function pruneExpired(
        ?CarbonImmutable $now = null,
    ): int {
        if (Schema::hasTable('tracked_link_dedupes') === false) {
            return 0;
        }

        $cutoff = ($now ?? CarbonImmutable::now('UTC'))
            ->subHours(self::DEDUPE_RETENTION_HOURS)
            ->format('Y-m-d H:i:s');

        return DB::table('tracked_link_dedupes')
            ->whereNotNull('last_counted_at')
            ->where('last_counted_at', '<', $cutoff)
            ->delete();
    }
}
