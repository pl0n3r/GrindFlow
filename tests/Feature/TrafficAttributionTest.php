<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\RecordTrackedLinkClick;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\TrackedLink;
use App\Models\TrackedLinkDailyMetric;
use App\Models\User;
use App\Services\Traffic\TrafficAttributionRecorder;
use App\Services\Traffic\VisitorFingerprint;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrafficAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['grindflow.traffic.hash_key' => 'traffic-test-secret']);
    }

    public function test_studio_can_create_tenant_scoped_tracked_link(): void
    {
        [$user, $organization] = $this->identity(UserRole::Studio);

        $this->actingAs($user)
            ->post(
                route('organizations.traffic.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'label' => 'September X campaign',
                    'destination_url' => 'https://example.com/landing?offer=1',
                    'channel' => 'x',
                    'campaign' => 'september',
                ],
            )
            ->assertRedirect(
                route('organizations.traffic.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )
            ->assertSessionHas('status');

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user): void {
                $link = TrackedLink::query()->sole();

                $this->assertSame(
                    $user->getKey(),
                    $link->created_by_user_id,
                );
                $this->assertSame(
                    'https://example.com/landing?offer=1',
                    $link->destination_url,
                );
                $this->assertSame('x', $link->channel);
                $this->assertSame('september', $link->campaign);
                $this->assertSame(
                    TrackedLink::STATUS_ACTIVE,
                    $link->status,
                );
                $this->assertMatchesRegularExpression(
                    '/^[A-Za-z0-9]{22}$/',
                    $link->token,
                );
            },
        );
    }

    public function test_model_role_cannot_create_tracked_links(): void
    {
        [$user, $organization] = $this->identity(UserRole::Model);

        $this->actingAs($user)
            ->post(
                route('organizations.traffic.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'label' => 'Forbidden',
                    'destination_url' => 'https://example.com/',
                ],
            )
            ->assertForbidden();
    }

    public function test_model_role_cannot_view_traffic_analytics(): void
    {
        [$user, $organization] = $this->identity(UserRole::Model);

        $this->actingAs($user)
            ->get(
                route('organizations.traffic.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )
            ->assertForbidden();
    }

    public function test_destination_requires_http_or_https(): void
    {
        [$user, $organization] = $this->identity(UserRole::Editor);

        $this->actingAs($user)
            ->post(
                route('organizations.traffic.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'label' => 'Unsafe',
                    'destination_url' => 'javascript:alert(1)',
                ],
            )
            ->assertSessionHasErrors('destination_url');
    }

    public function test_traffic_index_is_tenant_scoped(): void
    {
        [$user, $organization] = $this->identity(UserRole::Studio);
        [$otherUser, $otherOrganization] = $this->identity(UserRole::Studio);

        $local = $this->link(
            $user,
            $organization,
            'Local campaign',
            'https://example.com/local',
        );
        $foreign = $this->link(
            $otherUser,
            $otherOrganization,
            'Foreign secret campaign',
            'https://example.com/foreign',
        );

        $this->actingAs($user)
            ->get(
                route('organizations.traffic.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )
            ->assertOk()
            ->assertSee($local->label)
            ->assertDontSee($foreign->label)
            ->assertDontSee($foreign->token);
    }

    public function test_public_redirect_is_no_cache_and_queues_only_hash(): void
    {
        Queue::fake([RecordTrackedLinkClick::class]);

        [$user, $organization] = $this->identity(UserRole::Studio);
        $link = $this->link(
            $user,
            $organization,
            'Public redirect',
            'https://example.com/destination',
        );

        $remoteIp = '203.0.113.20';
        $spoofedForwardedIp = '198.51.100.99';

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => $remoteIp,
        ])->withHeaders([
            'X-Forwarded-For' => $spoofedForwardedIp,
            'User-Agent' => 'Sensitive Browser Fingerprint',
            'Referer' => 'https://private.example/path',
        ])->get(
            route('traffic.redirect', ['token' => $link->token]),
        );

        $response
            ->assertStatus(302)
            ->assertRedirect('https://example.com/destination')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $expected = hash_hmac(
            'sha256',
            'grindflow:traffic:v1:'.$link->getKey().':'.$remoteIp,
            'traffic-test-secret',
        );
        $spoofed = hash_hmac(
            'sha256',
            'grindflow:traffic:v1:'.$link->getKey().':'.$spoofedForwardedIp,
            'traffic-test-secret',
        );

        Queue::assertPushed(
            RecordTrackedLinkClick::class,
            function (RecordTrackedLinkClick $job) use (
                $link,
                $organization,
                $expected,
                $spoofed,
                $remoteIp,
            ): bool {
                $this->assertSame(
                    $organization->getKey(),
                    $job->organizationId,
                );
                $this->assertSame($link->getKey(), $job->trackedLinkId);
                $this->assertSame($expected, $job->visitorHash);
                $this->assertNotSame($spoofed, $job->visitorHash);
                $this->assertStringNotContainsString(
                    $remoteIp,
                    $job->visitorHash,
                );

                return true;
            },
        );
    }

    public function test_hash_is_scoped_per_link_to_prevent_cross_link_correlation(): void
    {
        $request = Request::create(
            '/l/test',
            'GET',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.30'],
        );

        $hasher = app(VisitorFingerprint::class);

        $first = $hasher->forLink($request, 'link-a');
        $second = $hasher->forLink($request, 'link-b');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertSame(64, strlen($first));
        $this->assertSame(64, strlen($second));
        $this->assertStringNotContainsString('203.0.113.30', $first);
    }

    public function test_same_hash_counts_once_inside_fixed_dedupe_window(): void
    {
        [$user, $organization] = $this->identity(UserRole::Studio);
        $link = $this->link(
            $user,
            $organization,
            'Dedupe',
            'https://example.com/dedupe',
        );
        $hash = hash('sha256', 'same-visitor');
        $start = CarbonImmutable::parse('2026-09-19 03:00:00', 'UTC');

        $recorder = app(TrafficAttributionRecorder::class);

        $this->assertTrue($recorder->record(
            (string) $organization->getKey(),
            (string) $link->getKey(),
            $hash,
            $start,
        ));
        $this->assertFalse($recorder->record(
            (string) $organization->getKey(),
            (string) $link->getKey(),
            $hash,
            $start->addMinutes(5),
        ));
        $this->assertTrue($recorder->record(
            (string) $organization->getKey(),
            (string) $link->getKey(),
            $hash,
            $start->addMinutes(11),
        ));

        $this->assertSame(
            2,
            (int) DB::table('tracked_link_daily_metrics')
                ->where('tracked_link_id', $link->getKey())
                ->value('clicks'),
        );
        $this->assertSame(
            1,
            DB::table('tracked_link_dedupes')
                ->where('tracked_link_id', $link->getKey())
                ->count(),
        );
    }

    public function test_daily_metrics_are_aggregated_without_event_rows(): void
    {
        [$user, $organization] = $this->identity(UserRole::Studio);
        $link = $this->link(
            $user,
            $organization,
            'Daily aggregate',
            'https://example.com/daily',
        );
        $recorder = app(TrafficAttributionRecorder::class);
        $start = CarbonImmutable::parse('2026-09-19 23:55:00', 'UTC');

        $this->assertTrue($recorder->record(
            (string) $organization->getKey(),
            (string) $link->getKey(),
            hash('sha256', 'visitor-a'),
            $start,
        ));
        $this->assertTrue($recorder->record(
            (string) $organization->getKey(),
            (string) $link->getKey(),
            hash('sha256', 'visitor-b'),
            $start->addMinutes(10),
        ));

        $metrics = DB::table('tracked_link_daily_metrics')
            ->where('tracked_link_id', $link->getKey())
            ->orderBy('metric_date')
            ->get(['metric_date', 'clicks']);

        $this->assertCount(2, $metrics);
        $this->assertSame('2026-09-19', $metrics[0]->metric_date);
        $this->assertSame(1, (int) $metrics[0]->clicks);
        $this->assertSame('2026-09-20', $metrics[1]->metric_date);
        $this->assertSame(1, (int) $metrics[1]->clicks);
    }

    public function test_dedupe_hashes_are_pruned_after_retention_window(): void
    {
        [$user, $organization] = $this->identity(UserRole::Studio);
        $link = $this->link(
            $user,
            $organization,
            'Prune',
            'https://example.com/prune',
        );
        $start = CarbonImmutable::parse('2026-09-19 01:00:00', 'UTC');
        $recorder = app(TrafficAttributionRecorder::class);

        $this->assertTrue($recorder->record(
            (string) $organization->getKey(),
            (string) $link->getKey(),
            hash('sha256', 'visitor-prune'),
            $start,
        ));

        $this->assertSame(
            1,
            $recorder->pruneExpired($start->addHours(25)),
        );
        $this->assertSame(
            0,
            DB::table('tracked_link_dedupes')->count(),
        );
        $this->assertSame(
            1,
            (int) DB::table('tracked_link_daily_metrics')
                ->where('tracked_link_id', $link->getKey())
                ->value('clicks'),
        );
    }

    public function test_disabled_link_does_not_redirect_or_queue_metrics(): void
    {
        Queue::fake([RecordTrackedLinkClick::class]);

        [$user, $organization] = $this->identity(UserRole::Studio);
        $link = $this->link(
            $user,
            $organization,
            'Disabled',
            'https://example.com/disabled',
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($link): void {
                TrackedLink::query()
                    ->findOrFail($link->getKey())
                    ->forceFill([
                        'status' => TrackedLink::STATUS_DISABLED,
                    ])
                    ->save();
            },
        );

        $this->get(
            route('traffic.redirect', ['token' => $link->token]),
        )->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_traffic_schema_contains_no_raw_visitor_columns(): void
    {
        $columns = array_merge(
            Schema::getColumnListing('tracked_links'),
            Schema::getColumnListing('tracked_link_daily_metrics'),
            Schema::getColumnListing('tracked_link_dedupes'),
        );

        foreach ([
            'ip',
            'ip_address',
            'user_agent',
            'referrer',
            'referer',
            'country',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $this->assertContains('visitor_hash', $columns);
    }

    public function test_traffic_endpoints_are_safe_before_migration(): void
    {
        [$user, $organization] = $this->identity(UserRole::Studio);

        Schema::dropIfExists('tracked_link_dedupes');
        Schema::dropIfExists('tracked_link_daily_metrics');
        Schema::dropIfExists('tracked_links');

        $migrationPath = database_path(
            'migrations/2026_09_19_033000_create_traffic_attribution_tables.php',
        );

        try {
            $route = route('organizations.traffic.index', [
                'organizationId' => $organization->getKey(),
            ]);

            $this->actingAs($user)
                ->get($route)
                ->assertOk()
                ->assertSee('Migration required');

            $this->actingAs($user)
                ->post($route, [])
                ->assertStatus(503);

            $this->get('/l/abcdefghijklmnopqrstuv')
                ->assertNotFound();

            $this->assertSame(
                0,
                app(TrafficAttributionRecorder::class)->pruneExpired(),
            );
        } finally {
            $migration = require $migrationPath;
            $migration->up();
        }
    }

    public function test_dashboard_filters_daily_metrics_by_period_and_channel(): void
    {
        [$user, $organization] = $this->identity(UserRole::Studio);
        [$selected, $other] = [
            $this->link($user, $organization, 'Selected', 'https://example.com/selected'),
            $this->link($user, $organization, 'Other', 'https://example.com/other'),
        ];

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($selected, $other): void {
                $other->forceFill(['channel' => 'other'])->save();
                TrackedLinkDailyMetric::query()->create([
                    'tracked_link_id' => $selected->getKey(),
                    'metric_date' => '2026-09-10',
                    'clicks' => 7,
                ]);
                TrackedLinkDailyMetric::query()->create([
                    'tracked_link_id' => $other->getKey(),
                    'metric_date' => '2026-09-10',
                    'clicks' => 90,
                ]);
            },
        );

        $this->actingAs($user)->get(route('organizations.traffic.index', [
            'organizationId' => $organization->getKey(),
            'from' => '2026-09-01',
            'to' => '2026-09-19',
            'channel' => 'test',
        ]))->assertOk()->assertSee('7')->assertSee('Selected')->assertDontSee('Other');
    }

    /**
     * @return array{User, Organization}
     */
    private function identity(UserRole $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        return [$user, $organization];
    }

    private function link(
        User $user,
        Organization $organization,
        string $label,
        string $destination,
    ): TrackedLink {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): TrackedLink => TrackedLink::query()->create([
                'created_by_user_id' => $user->getKey(),
                'token' => Str::random(22),
                'label' => $label,
                'destination_url' => $destination,
                'channel' => 'test',
                'campaign' => 'feature',
                'status' => TrackedLink::STATUS_ACTIVE,
            ]),
        );
    }
}
