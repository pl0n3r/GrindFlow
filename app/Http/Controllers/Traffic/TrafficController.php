<?php

namespace App\Http\Controllers\Traffic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Traffic\StoreTrackedLinkRequest;
use App\Models\Organization;
use App\Models\TrackedLink;
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

        $trafficReady = $this->trafficReady();
        $links = collect();

        if ($trafficReady) {
            $links = TrackedLink::query()
                ->withSum('dailyMetrics as total_clicks', 'clicks')
                ->latest()
                ->limit(100)
                ->get();
        }

        return view('traffic.index', [
            'organization' => $organization,
            'trafficReady' => $trafficReady,
            'links' => $links,
            'canManageTraffic' => $trafficReady
                && $user->canManageTrafficOrganization($organization),
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
