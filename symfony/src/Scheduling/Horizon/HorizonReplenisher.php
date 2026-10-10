<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Horizon;

/** Build-ahead planning only. Even pilot produces previews, never publications. */
final class HorizonReplenisher
{
    public function __construct(private readonly GapDetector $detector = new GapDetector())
    {
    }

    /**
     * @param list<array{tenant_id:string,date:string,slot:int,status?:string}> $scheduled
     * @param list<array{id:string,tenant_id:string,eligible:bool}> $assets
     * @param list<string> $blockedDates
     * @param list<array{date:string,slot:int}> $blockedSlots
     * @return array<string,mixed>
     */
    public function plan(
        string $tenantId,
        string $timezone,
        string $startDate,
        int $horizonDays,
        int $slotsPerDay,
        array $scheduled,
        array $assets,
        string $mode,
        array $blockedDates = [],
        array $blockedSlots = [],
        bool $permitted = true,
    ): array {
        if (!in_array($mode, ['manual', 'assisted', 'pilot'], true)
            || !array_is_list($assets) || count($assets) > 10000) {
            return self::rejected('invalid_input');
        }

        $inspection = $this->detector->detect(
            $tenantId, $timezone, $startDate, $horizonDays, $slotsPerDay,
            $scheduled, $blockedDates, $blockedSlots, $permitted,
        );
        if ($inspection['status'] !== 'ok') {
            return self::rejected($inspection['reason']);
        }

        $eligible = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || !isset($asset['id'], $asset['tenant_id'], $asset['eligible'])
                || !is_string($asset['id']) || trim($asset['id']) === '' || strlen($asset['id']) > 128
                || !is_string($asset['tenant_id']) || trim($asset['tenant_id']) === ''
                || !is_bool($asset['eligible'])) {
                return self::rejected('invalid_asset');
            }
            if ($asset['tenant_id'] === $tenantId && $asset['eligible']) {
                // A given asset is assigned at most once by this conservative planner.
                $eligible[$asset['id']] = $asset['id'];
            }
        }

        $missing = count($inspection['gaps']);
        $result = [
            'status' => 'ok', 'reason' => $missing ? 'gaps_detected' : 'horizon_covered',
            'mode' => $mode, 'missing_count' => $missing,
            'unfilled_count' => $missing, 'action' => 'notify', 'proposals' => [],
        ];
        if ($mode === 'manual' || $missing === 0) {
            return $result;
        }
        if ($eligible === []) {
            return array_replace($result, ['status' => 'rejected', 'reason' => 'no_eligible_content']);
        }

        $assetIds = array_values($eligible);
        $proposals = [];
        foreach ($inspection['gaps'] as $index => $gap) {
            if (!isset($assetIds[$index])) {
                break;
            }
            $proposals[] = $gap + ['asset_id' => $assetIds[$index]];
        }
        return array_replace($result, [
            'action' => $mode === 'assisted' ? 'suggest' : 'pilot_preview',
            'proposals' => $proposals,
            'unfilled_count' => $missing - count($proposals),
        ]);
    }

    /** @return array<string,mixed> */
    private static function rejected(string $reason): array
    {
        return ['status' => 'rejected', 'reason' => $reason, 'mode' => null,
            'missing_count' => 0, 'unfilled_count' => 0, 'action' => 'none',
            'proposals' => []];
    }
}
