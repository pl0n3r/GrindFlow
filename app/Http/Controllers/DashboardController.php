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

        return view('dashboard', [
            'organizations' => $organizations,
            'readyMediaCount' => $this->countForVisibleOrganizations(
                'media_assets',
                $organizationIds,
                MediaAsset::STATUS_READY,
            ),
            'scheduledPublicationCount' => $this->countForVisibleOrganizations(
                'scheduled_publications',
                $organizationIds,
                ScheduledPublication::STATUS_SCHEDULED,
            ),
        ]);
    }

    /**
     * Tenant models need an explicit org scope outside TenantContext.
     *
     * @param list<string> $organizationIds
     */
    private function countForVisibleOrganizations(
        string $table,
        array $organizationIds,
        string $status,
    ): ?int {
        if ($organizationIds === []) {
            return 0;
        }

        try {
            if (Schema::hasTable($table) === false) {
                return null;
            }

            return DB::table($table)
                ->whereIn('organization_id', $organizationIds)
                ->where('status', $status)
                ->count();
        } catch (Throwable) {
            // An incomplete module is unknown, not zero activity.
            return null;
        }
    }
}
