<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreScheduledPublicationRequest;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\Scopes\TenantScope;
use App\Models\TrackedLink;
use App\Models\User;
use App\Services\Scheduling\ContentScheduler;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
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
        $filterDestinations = collect();
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

            // Historical schedules remain filterable if a destination was disabled.
            $filterDestinations = PublishingDestination::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get();
            $destinations = $filterDestinations
                ->where('status', PublishingDestination::STATUS_ACTIVE)
                ->values();

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
                'delivery',
            ];

            if ($linkingReady) {
                $relations[] = 'linkAssignment.trackedLink';
            }

            $filters = $request->validate([
                'status' => ['nullable', Rule::in(['scheduled', 'cancelled'])],
                'destination_id' => ['nullable', 'uuid'],
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
                'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            ]);

            // The paginator owns its page parameter; only validated filters
            // belong in previous/next links so unrelated query keys do not leak.
            unset($filters['page']);

            $publications = ScheduledPublication::query()
                ->with($relations)
                ->when(
                    $filters['status'] ?? null,
                    fn ($query, $status) => $query->where('status', $status),
                )
                ->when(
                    $filters['destination_id'] ?? null,
                    fn ($query, $id) => $query->where('publishing_destination_id', $id),
                )
                ->when(
                    $filters['from'] ?? null,
                    fn ($query, $from) => $query->whereDate('scheduled_for_utc', '>=', $from),
                )
                ->when(
                    $filters['to'] ?? null,
                    fn ($query, $to) => $query->whereDate('scheduled_for_utc', '<=', $to),
                )
                ->orderBy('scheduled_for_utc')
                ->orderBy('id')
                ->paginate(25)
                ->appends($filters);
        }

        return view('scheduling.index', [
            'organization' => $organization,
            'schedulingReady' => $schedulingReady,
            'linkingReady' => $linkingReady,
            'trackedLinks' => $trackedLinks,
            'destinations' => $destinations,
            'filterDestinations' => $filterDestinations,
            'eligibleAssets' => $eligibleAssets,
            'publications' => $publications,
            'canSchedule' => $schedulingReady
                && $user->canScheduleOrganization($organization),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTimezone' => (string) config('app.timezone', 'UTC'),
            'filters' => $filters ?? [],
            'calendarDays' => $schedulingReady
                ? $publications->getCollection()->groupBy(
                    fn (ScheduledPublication $publication) => $publication->scheduled_for_utc
                        ?->format('Y-m-d'),
                )
                : collect(),
        ]);
    }

    public function store(
        StoreScheduledPublicationRequest $request,
        ContentScheduler $scheduler,
    ): RedirectResponse {
        $validated = $request->validated();

        $asset = MediaAsset::query()
            ->findOrFail((string) $validated['asset_id']);
        $destinations = PublishingDestination::query()
            ->whereIn('id', $validated['destination_ids'])
            ->get();
        abort_unless($destinations->count() === count($validated['destination_ids']), 404);

        /** @var User $user */
        $user = $request->user();

        $created = $scheduler->scheduleMany(
            $asset,
            $destinations,
            $user,
            (string) $validated['scheduled_for_local'],
            (string) $validated['timezone'],
            isset($validated['tracked_link_id'])
                ? (string) $validated['tracked_link_id']
                : null,
            (string) $validated['request_key'],
        );

        return redirect()
            ->route('organizations.scheduler.index', [
                'organizationId' => $this->organization($request)->getKey(),
            ])
            ->with('status', $created->count().' publicación(es) programada(s).');
    }

    public function update(
        Request $request,
        ContentScheduler $scheduler,
    ): RedirectResponse {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canScheduleOrganization($organization), 403);
        $validated = $request->validate([
            'publication_id' => ['required', 'uuid'],
            'scheduled_for_local' => ['required', 'date_format:Y-m-d\\TH:i'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
        ]);
        $publication = ScheduledPublication::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('organization_id', $organization->getKey())
            ->whereKey($validated['publication_id'])
            ->firstOrFail();
        $scheduler->reschedule(
            $publication,
            $user,
            $validated['scheduled_for_local'],
            $validated['timezone'],
        );

        return back()->with('status', 'Programación actualizada.');
    }

    public function updateTrackedLink(
        Request $request,
        ContentScheduler $scheduler,
    ): RedirectResponse {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        abort_unless($user->canScheduleOrganization($organization), 403);

        $validated = $request->validate([
            'publication_id' => ['required', 'uuid'],
            'tracked_link_id' => ['present', 'nullable', 'uuid'],
        ]);

        $publication = ScheduledPublication::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('organization_id', $organization->getKey())
            ->whereKey($validated['publication_id'])
            ->firstOrFail();

        $scheduler->updateTrackedLink(
            $publication,
            $user,
            empty($validated['tracked_link_id'])
                ? null
                : (string) $validated['tracked_link_id'],
        );

        return back()->with('status', 'Tracked link assignment updated.');
    }

    public function cancel(
        Request $request,
        ContentScheduler $scheduler,
    ): RedirectResponse {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canScheduleOrganization($organization), 403);
        $validated = $request->validate(['publication_id' => ['required', 'uuid']]);
        $publication = ScheduledPublication::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('organization_id', $organization->getKey())
            ->whereKey($validated['publication_id'])
            ->firstOrFail();
        $scheduler->cancel($publication, $user);

        return back()->with('status', 'Programación cancelada.');
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
