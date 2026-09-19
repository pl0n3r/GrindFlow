<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreScheduledPublicationRequest;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\TrackedLink;
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
        $linkingReady = $schedulingReady
            && Schema::hasTable('tracked_links')
            && Schema::hasTable('scheduled_publication_links');
        $trackedLinks = collect();
        $destinations = collect();
        $eligibleAssets = collect();
        $publications = collect();

        if ($schedulingReady) {
            if ($linkingReady) {
                $trackedLinks = TrackedLink::query()
                    ->where('status', TrackedLink::STATUS_ACTIVE)
                    ->orderBy('label')
                    ->limit(100)
                    ->get();
            }

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

            $relations = [
                'mediaAsset.blob',
                'destination',
                'scheduledBy',
            ];

            if ($linkingReady) {
                $relations[] = 'linkAssignment.trackedLink';
            }

            $publications = ScheduledPublication::query()
                ->with($relations)
                ->where('status', ScheduledPublication::STATUS_SCHEDULED)
                ->where('scheduled_for_utc', '>', now('UTC'))
                ->orderBy('scheduled_for_utc')
                ->limit(100)
                ->get();
        }

        return view('scheduling.index', [
            'organization' => $organization,
            'schedulingReady' => $schedulingReady,
            'linkingReady' => $linkingReady,
            'trackedLinks' => $trackedLinks,
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
            isset($validated['tracked_link_id'])
                ? (string) $validated['tracked_link_id']
                : null,
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
