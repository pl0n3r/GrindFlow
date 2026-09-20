<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\ScheduledPublication;
use App\Models\User;
use Illuminate\Http\Request;
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
        ]);
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
