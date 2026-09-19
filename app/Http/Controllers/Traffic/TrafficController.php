<?php

namespace App\Http\Controllers\Traffic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Traffic\StoreTrackedLinkRequest;
use App\Models\Organization;
use App\Models\TrackedLink;
use App\Models\TrackedLinkDailyMetric;
use App\Models\User;
use App\Services\Traffic\TrackedLinkManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class TrafficController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->canManageTrafficOrganization($organization),
            403,
        );

        $trafficReady = $this->trafficReady();
        $links = collect();
        $series = collect();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'channel' => ['nullable', 'string', 'max:64'],
            'campaign' => ['nullable', 'string', 'max:128'],
            'tracked_link_id' => ['nullable', 'uuid'],
        ]);
        $from = $filters['from'] ?? now('UTC')->subDays(29)->toDateString();
        $to = $filters['to'] ?? now('UTC')->toDateString();
        $totalClicks = 0;
        $channels = collect();

        if ($trafficReady) {
            $filteredLinks = TrackedLink::query()
                ->when($filters['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
                ->when($filters['campaign'] ?? null, fn ($query, $campaign) => $query->where('campaign', $campaign))
                ->when($filters['tracked_link_id'] ?? null, fn ($query, $id) => $query->whereKey($id));

            $links = (clone $filteredLinks)
                ->with(['scheduledPublicationLinks.scheduledPublication.destination'])
                ->withSum(['dailyMetrics as total_clicks' => fn ($query) => $query
                    ->whereBetween('metric_date', [$from, $to])], 'clicks')
                ->latest()
                ->limit(100)
                ->get();
            $series = TrackedLinkDailyMetric::query()
                ->whereIn('tracked_link_id', (clone $filteredLinks)->select('id'))
                ->whereBetween('metric_date', [$from, $to])
                ->selectRaw('metric_date, SUM(clicks) as clicks')
                ->groupBy('metric_date')
                ->orderBy('metric_date')
                ->get();
            $totalClicks = (int) $series->sum('clicks');
            $channels = TrackedLink::query()->whereNotNull('channel')->distinct()->orderBy('channel')->pluck('channel');
        }

        return view('traffic.index', [
            'organization' => $organization,
            'trafficReady' => $trafficReady,
            'links' => $links,
            'canManageTraffic' => $trafficReady,
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
            'series' => $series,
            'totalClicks' => $totalClicks,
            'channels' => $channels,
        ]);
    }

    public function store(
        StoreTrackedLinkRequest $request,
        TrackedLinkManager $manager,
    ): RedirectResponse {
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $manager->create(
            $user,
            (string) $validated['label'],
            (string) $validated['destination_url'],
            isset($validated['channel'])
                ? (string) $validated['channel']
                : null,
            isset($validated['campaign'])
                ? (string) $validated['campaign']
                : null,
        );

        return redirect()
            ->route('organizations.traffic.index', [
                'organizationId' => $this->organization($request)->getKey(),
            ])
            ->with('status', 'Tracked link created.');
    }

    private function trafficReady(): bool
    {
        return Schema::hasTable('tracked_links')
            && Schema::hasTable('tracked_link_daily_metrics')
            && Schema::hasTable('tracked_link_dedupes');
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('tenantOrganization');

        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }
}
