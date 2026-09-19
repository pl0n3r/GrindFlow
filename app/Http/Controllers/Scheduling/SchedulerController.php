<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreScheduledPublicationRequest;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\User;
use App\Services\Scheduling\ContentScheduler;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SchedulerController extends Controller
{
    public function index(
        Request $request,
        ContentScheduler $scheduler,
    ): View {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        $schedulingReady = $this->schedulingReady();
        $destinations = collect();
        $eligibleAssets = collect();
        $publications = collect();

        if ($schedulingReady) {
            $destinations = PublishingDestination::query()
                ->where('status', PublishingDestination::STATUS_ACTIVE)
                ->orderBy('name')
                ->get();

            $eligibleAssets = $scheduler
                ->eligibleAssetsQuery()
                ->with('blob')
                ->latest()
                ->limit(100)
                ->get();

            $publications = ScheduledPublication::query()
                ->with([
                    'mediaAsset.blob',
                    'destination',
                    'scheduledBy',
                ])
                ->where('status', ScheduledPublication::STATUS_SCHEDULED)
                ->where('scheduled_for_utc', '>', now('UTC'))
                ->orderBy('scheduled_for_utc')
                ->limit(100)
                ->get();
        }

        return view('scheduling.index', [
            'organization' => $organization,
            'schedulingReady' => $schedulingReady,
            'destinations' => $destinations,
            'eligibleAssets' => $eligibleAssets,
            'publications' => $publications,
            'canSchedule' => $schedulingReady
                && $user->canScheduleOrganization($organization),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTimezone' => (string) config('app.timezone', 'UTC'),
        ]);
    }

    public function store(
        StoreScheduledPublicationRequest $request,
        ContentScheduler $scheduler,
    ): RedirectResponse {
        $validated = $request->validated();

        $asset = MediaAsset::query()
            ->findOrFail((string) $validated['asset_id']);
        $destination = PublishingDestination::query()
            ->findOrFail((string) $validated['destination_id']);

        /** @var User $user */
        $user = $request->user();

        $scheduler->schedule(
            $asset,
            $destination,
            $user,
            (string) $validated['scheduled_for_local'],
            (string) $validated['timezone'],
        );

        return redirect()
            ->route('organizations.scheduler.index', [
                'organizationId' => $this->organization($request)->getKey(),
            ])
            ->with('status', 'Contenido programado correctamente.');
    }

    private function schedulingReady(): bool
    {
        return Schema::hasTable('publishing_destinations')
            && Schema::hasTable('scheduled_publications');
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('tenantOrganization');

        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }
}
