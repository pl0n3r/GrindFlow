<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\ScheduledPublication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $organizations = Organization::query()
            ->visibleTo($user)
            ->orderBy('name')
            ->get();

        $organizationIds = $organizations->modelKeys();

        $readyMediaByOrganization = $this->countByVisibleOrganization(
            'media_assets',
            $organizationIds,
            MediaAsset::STATUS_READY,
        );

        $scheduledByOrganization = $this->countByVisibleOrganization(
            'scheduled_publications',
            $organizationIds,
            ScheduledPublication::STATUS_SCHEDULED,
        );

        return view('dashboard', [
            'organizations' => $organizations,
            'readyMediaCount' => $readyMediaByOrganization === null
                ? null
                : array_sum($readyMediaByOrganization),
            'scheduledPublicationCount' => $scheduledByOrganization === null
                ? null
                : array_sum($scheduledByOrganization),
            'readyMediaByOrganization' => $readyMediaByOrganization,
            'scheduledByOrganization' => $scheduledByOrganization,
            'upcomingPublications' => $this->agendaForVisibleOrganizations($organizationIds),
            'pastDuePublications' => $this->agendaForVisibleOrganizations($organizationIds, true),
        ]);
    }

    /**
     * Five future (or past-due) rows are selected with a bounded tenant filter
     * and organization-safe joins. Null means missing schema, not zero activity.
     *
     * @param  list<string>  $organizationIds
     * @return Collection<int, \stdClass>|null
     */
    private function agendaForVisibleOrganizations(
        array $organizationIds,
        bool $pastDue = false,
    ): ?Collection
    {
        if ($organizationIds === []) {
            return collect();
        }

        try {
            foreach (['scheduled_publications', 'publishing_destinations', 'media_assets'] as $table) {
                if (Schema::hasTable($table) === false) {
                    return null;
                }
            }

            $nowUtc = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

            return DB::table('scheduled_publications as schedule')
                ->join('publishing_destinations as destination', function ($join): void {
                    $join->on('destination.id', '=', 'schedule.publishing_destination_id')
                        ->on('destination.organization_id', '=', 'schedule.organization_id');
                })
                ->join('media_assets as media', function ($join): void {
                    $join->on('media.id', '=', 'schedule.media_asset_id')
                        ->on('media.organization_id', '=', 'schedule.organization_id');
                })
                ->whereIn('schedule.organization_id', $organizationIds)
                ->where('schedule.status', ScheduledPublication::STATUS_SCHEDULED)
                ->where('schedule.scheduled_for_utc', $pastDue ? '<' : '>=', $nowUtc)
                ->orderBy('schedule.scheduled_for_utc', $pastDue ? 'desc' : 'asc')
                ->orderBy('schedule.id')
                ->limit(5)
                ->get([
                    'schedule.id',
                    'schedule.organization_id',
                    'schedule.scheduled_for_utc',
                    'destination.name as destination_name',
                    'media.original_filename as media_name',
                ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tenant models need an explicit organization scope outside TenantContext.
     * Grouped SQL is bounded by the visible IDs, never one query per card.
     *
     * @param  list<string>  $organizationIds
     * @return array<string, int>|null
     */
    private function countByVisibleOrganization(
        string $table,
        array $organizationIds,
        string $status,
    ): ?array {
        if ($organizationIds === []) {
            return [];
        }

        try {
            if (Schema::hasTable($table) === false) {
                return null;
            }

            $rows = DB::table($table)
                ->whereIn('organization_id', $organizationIds)
                ->where('status', $status)
                ->select('organization_id')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('organization_id')
                ->get();

            $counts = [];

            foreach ($rows as $row) {
                $counts[(string) $row->organization_id] = (int) $row->total;
            }

            return $counts;
        } catch (Throwable) {
            // An unavailable module is unknown, never false zero activity.
            return null;
        }
    }
}
