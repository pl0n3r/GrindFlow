<?php

namespace App\Http\Controllers\Traffic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Traffic\StoreTrackedLinkRequest;
use App\Models\Organization;
use App\Models\TrackedLink;
use App\Models\TrackedLinkDailyMetric;
use App\Models\User;
use App\Services\Traffic\TrackedLinkManager;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $filters = $this->filters($request);
        $from = $filters['from'] ?? now('UTC')->subDays(29)->toDateString();
        $to = $filters['to'] ?? now('UTC')->toDateString();
        // Exclusive next-day bound includes the full final UTC day on both DATE
        // and datetime-backed test databases without wrapping indexed columns.
        $toExclusive = Carbon::parse($to, 'UTC')->addDay()->toDateString();
        $totalClicks = 0;
        $linkCount = 0;
        $channels = collect();

        if ($trafficReady) {
            $filteredLinks = TrackedLink::query()
                ->when($filters['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
                ->when($filters['campaign'] ?? null, fn ($query, $campaign) => $query->where('campaign', $campaign))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['tracked_link_id'] ?? null, fn ($query, $id) => $query->whereKey($id));

            $links = (clone $filteredLinks)
                ->with(['scheduledPublicationLinks.scheduledPublication.destination'])
                ->withSum(['dailyMetrics as total_clicks' => fn ($query) => $query
                    ->where('metric_date', '>=', $from)
                    ->where('metric_date', '<', $toExclusive)], 'clicks')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->appends(collect($filters)->except('page')->all());
            $linkCount = $links->total();
            $series = TrackedLinkDailyMetric::query()
                ->whereIn('tracked_link_id', (clone $filteredLinks)->select('id'))
                ->where('metric_date', '>=', $from)
                ->where('metric_date', '<', $toExclusive)
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
            'linkCount' => $linkCount,
            'canManageTraffic' => $trafficReady,
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
            'series' => $series,
            'totalClicks' => $totalClicks,
            'channels' => $channels,
        ]);
    }

    /**
     * Download filtered per-link daily aggregates, not visitor-level events.
     * The streaming query is explicitly tenant-scoped: middleware may clear
     * the request's ambient TenantContext before Symfony sends the body.
     */
    public function export(Request $request): StreamedResponse
    {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->canManageTrafficOrganization($organization),
            403,
        );

        abort_unless($this->trafficReady(), 503);

        $filters = $this->filters($request);
        $from = $filters['from'] ?? now('UTC')->subDays(29)->toDateString();
        $to = $filters['to'] ?? now('UTC')->toDateString();
        // Exclusive next-day bound includes the full final UTC day on both DATE
        // and datetime-backed test databases without wrapping indexed columns.
        $toExclusive = Carbon::parse($to, 'UTC')->addDay()->toDateString();

        if (Carbon::parse($from, 'UTC')->diffInDays(
            Carbon::parse($to, 'UTC'),
        ) >= 366) {
            throw ValidationException::withMessages([
                'to' => 'The CSV export supports up to 366 days.',
            ]);
        }

        $organizationId = (string) $organization->getKey();

        $query = DB::table('tracked_link_daily_metrics as metrics')
            ->join('tracked_links as links', function (JoinClause $join): void {
                $join->on('metrics.tracked_link_id', '=', 'links.id')
                    ->on('metrics.organization_id', '=', 'links.organization_id');
            })
            ->where('metrics.organization_id', $organizationId)
            ->where('links.organization_id', $organizationId)
            ->where('metrics.metric_date', '>=', $from)
            ->where('metrics.metric_date', '<', $toExclusive)
            ->when(
                $filters['channel'] ?? null,
                fn ($query, $channel) => $query->where('links.channel', $channel),
            )
            ->when(
                $filters['campaign'] ?? null,
                fn ($query, $campaign) => $query->where('links.campaign', $campaign),
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, $status) => $query->where('links.status', $status),
            )
            ->when(
                $filters['tracked_link_id'] ?? null,
                fn ($query, $id) => $query->where('links.id', $id),
            )
            ->orderBy('metrics.metric_date')
            ->orderBy('links.id')
            ->select([
                'metrics.metric_date',
                'metrics.clicks',
                'links.label',
                'links.token',
                'links.channel',
                'links.campaign',
                'links.status',
            ]);

        return response()->streamDownload(
            static function () use ($query): void {
                $output = fopen('php://output', 'wb');

                if ($output === false) {
                    throw new \RuntimeException('Cannot initialize the CSV stream.');
                }

                try {
                    fwrite($output, "\xEF\xBB\xBF");
                    fputcsv(
                        $output,
                        ['date_utc', 'label', 'short_link', 'channel', 'campaign', 'status', 'clicks'],
                        ',',
                        '"',
                        '',
                    );

                    foreach ($query->cursor() as $row) {
                        fputcsv($output, [
                            (string) $row->metric_date,
                            self::csvCell((string) $row->label),
                            route('traffic.redirect', ['token' => $row->token]),
                            self::csvCell((string) ($row->channel ?? '')),
                            self::csvCell((string) ($row->campaign ?? '')),
                            self::csvCell((string) $row->status),
                            (int) $row->clicks,
                        ], ',', '"', '');
                    }
                } finally {
                    fclose($output);
                }
            },
            "grindflow-traffic-{$from}-{$to}.csv",
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Prevent spreadsheet formula execution from user-controlled labels and tags.
     */
    private static function csvCell(string $value): string
    {
        return preg_match('/^[\\s\\x00-\\x1F]*[=+@-]/u', $value) === 1
            ? "'".$value
            : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'channel' => ['nullable', 'string', 'max:64'],
            'campaign' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', Rule::in([TrackedLink::STATUS_ACTIVE, TrackedLink::STATUS_DISABLED])],
            'tracked_link_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
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

    public function updateLinkDetails(
        StoreTrackedLinkRequest $request,
        TrackedLinkManager $manager,
    ): RedirectResponse {
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $manager->updateDetails(
            $user,
            (string) $request->route('linkId'),
            (string) $validated['label'],
            (string) $validated['destination_url'],
            isset($validated['channel']) ? (string) $validated['channel'] : null,
            isset($validated['campaign']) ? (string) $validated['campaign'] : null,
        );

        return back()->with(
            'status',
            'Tracked link updated. Public token and click totals are unchanged.',
        );
    }

    public function updateLinkStatus(
        Request $request,
        TrackedLinkManager $manager,
    ): RedirectResponse {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->canManageTrafficOrganization($organization),
            403,
        );

        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    TrackedLink::STATUS_ACTIVE,
                    TrackedLink::STATUS_DISABLED,
                ]),
            ],
        ]);

        $manager->setStatus(
            $user,
            (string) $request->route('linkId'),
            (string) $validated['status'],
        );

        return redirect()
            ->route('organizations.traffic.index', [
                'organizationId' => $organization->getKey(),
            ])
            ->with(
                'status',
                $validated['status'] === TrackedLink::STATUS_DISABLED
                    ? 'Tracked link disabled. Historical metrics are preserved.'
                    : 'Tracked link enabled.',
            );
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
