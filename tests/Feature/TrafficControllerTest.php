<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\TrackedLink;
use App\Models\TrackedLinkDailyMetric;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HTTP regression for the existing TrafficController routes, with disposable
 * identities and daily aggregates. It sends no third-party requests.
 */
class TrafficControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_dashboard_and_csv_never_leak_foreign_tenant_links(): void
    {
        [$owner, $organization] = $this->identity(UserRole::Studio);
        [$other, $foreignOrganization] = $this->identity(UserRole::Studio);
        $mine = $this->link($owner, $organization, 'Own campaign', 'https://example.test/own');
        $foreign = $this->link($other, $foreignOrganization, 'Foreign secret campaign', 'https://example.test/foreign');

        app(TenantContext::class)->runWithinOrganization(
            $owner,
            (string) $organization->getKey(),
            fn () => TrackedLinkDailyMetric::query()->create([
                'tracked_link_id' => $mine->getKey(),
                'metric_date' => '2026-09-10',
                'clicks' => 9,
            ]),
        );
        app(TenantContext::class)->runWithinOrganization(
            $other,
            (string) $foreignOrganization->getKey(),
            fn () => TrackedLinkDailyMetric::query()->create([
                'tracked_link_id' => $foreign->getKey(),
                'metric_date' => '2026-09-10',
                'clicks' => 999,
            ]),
        );

        // Pin the UTC window: the default rolling 30-day interval changes
        // with the wall clock and must not invalidate historical fixtures.
        $index = $this->actingAs($owner)->get(
            $this->url('index', $organization).'?from=2026-09-01&to=2026-09-30',
        );
        $index->assertOk()->assertSee('Own campaign')
            ->assertDontSee('Foreign secret campaign')->assertDontSee($foreign->token);
        $this->assertSame(9, $index->viewData('totalClicks'));

        $csv = $this->actingAs($owner)
            ->get($this->url('export', $organization).'?from=2026-09-01&to=2026-09-30')
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Own campaign', $csv);
        $this->assertStringNotContainsString('Foreign secret campaign', $csv);
        $this->assertStringNotContainsString($foreign->token, $csv);
        $this->assertStringNotContainsString(',999', $csv);
        $this->assertStringNotContainsString('visitor_hash', $csv);
    }

    public function test_editor_creates_tracked_link_but_rejects_unsafe_destination(): void
    {
        [$editor, $organization] = $this->identity(UserRole::Editor);
        $this->actingAs($editor)->post($this->url('store', $organization), [
            'label' => 'Editor campaign',
            'destination_url' => 'https://example.test/landing',
            'channel' => 'email',
            'campaign' => 'autumn',
        ])->assertRedirect($this->url('index', $organization));

        $this->actingAs($editor)->post($this->url('store', $organization), [
            'label' => 'Unsafe link',
            'destination_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('destination_url');

        app(TenantContext::class)->runWithinOrganization(
            $editor,
            (string) $organization->getKey(),
            function (): void {
                $link = TrackedLink::query()->sole();
                $this->assertSame('Editor campaign', $link->label);
                $this->assertSame('email', $link->channel);
                $this->assertSame(TrackedLink::STATUS_ACTIVE, $link->status);
            },
        );
    }

    public function test_model_and_foreign_tenant_cannot_manage_links(): void
    {
        [$owner, $organization] = $this->identity(UserRole::Studio);
        [$model, $modelOrganization] = $this->identity(UserRole::Model);
        [$stranger, $foreignOrganization] = $this->identity(UserRole::Studio);
        $link = $this->link($owner, $organization, 'Protected', 'https://example.test/protected');

        $this->actingAs($model)->get($this->url('index', $modelOrganization))
            ->assertForbidden();
        $this->actingAs($model)->post($this->url('store', $modelOrganization), [
            'label' => 'Unauthorized',
            'destination_url' => 'https://example.test/not-allowed',
        ])->assertForbidden();
        $this->actingAs($model)->patch($this->statusUrl($modelOrganization, $link), [
            'status' => TrackedLink::STATUS_DISABLED,
        ])->assertForbidden();

        $this->actingAs($stranger)->get($this->url('index', $organization))
            ->assertNotFound();
        $this->actingAs($stranger)->patch($this->statusUrl($foreignOrganization, $link), [
            'status' => TrackedLink::STATUS_DISABLED,
        ])->assertNotFound();

        app(TenantContext::class)->runWithinOrganization(
            $owner,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                TrackedLink::STATUS_ACTIVE,
                TrackedLink::query()->findOrFail($link->getKey())->status,
            ),
        );
    }

    public function test_disabled_link_keeps_token_and_clicks_and_never_redirects(): void
    {
        Queue::fake();
        [$owner, $organization] = $this->identity(UserRole::Studio);
        $link = $this->link($owner, $organization, 'Retained history', 'https://example.test/history');
        app(TenantContext::class)->runWithinOrganization(
            $owner,
            (string) $organization->getKey(),
            fn () => TrackedLinkDailyMetric::query()->create([
                'tracked_link_id' => $link->getKey(),
                'metric_date' => '2026-09-11',
                'clicks' => 12,
            ]),
        );

        $this->actingAs($owner)->patch($this->statusUrl($organization, $link), [
            'status' => TrackedLink::STATUS_DISABLED,
        ])->assertRedirect($this->url('index', $organization));
        $this->get(route('traffic.redirect', ['token' => $link->token]))->assertNotFound();
        Queue::assertNothingPushed();

        $this->actingAs($owner)->patch($this->statusUrl($organization, $link), [
            'status' => TrackedLink::STATUS_ACTIVE,
        ])->assertRedirect($this->url('index', $organization));
        app(TenantContext::class)->runWithinOrganization(
            $owner,
            (string) $organization->getKey(),
            function () use ($link): void {
                $saved = TrackedLink::query()->findOrFail($link->getKey());
                $this->assertSame($link->token, $saved->token);
                $this->assertSame(TrackedLink::STATUS_ACTIVE, $saved->status);
                $this->assertSame(12, (int) TrackedLinkDailyMetric::query()->sole()->clicks);
            },
        );
    }

    public function test_invalid_status_and_filter_inputs_do_not_mutate_links(): void
    {
        [$owner, $organization] = $this->identity(UserRole::Studio);
        $link = $this->link($owner, $organization, 'Validated link', 'https://example.test/valid');

        $this->actingAs($owner)->patch($this->statusUrl($organization, $link), [
            'status' => 'archived',
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->get($this->url('index', $organization).'?from=not-a-date')
            ->assertSessionHasErrors('from');
        $this->actingAs($owner)->get($this->url('index', $organization).'?tracked_link_id=not-a-uuid')
            ->assertSessionHasErrors('tracked_link_id');
        $this->actingAs($owner)->get($this->url('export', $organization).'?from=2025-01-01&to=2026-09-30')
            ->assertSessionHasErrors('to');

        app(TenantContext::class)->runWithinOrganization(
            $owner,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                TrackedLink::STATUS_ACTIVE,
                TrackedLink::query()->findOrFail($link->getKey())->status,
            ),
        );
    }

    /** @return array{User, Organization} */
    private function identity(UserRole $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::query()->create([
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'role' => $role,
        ]);

        return [$user, $organization];
    }

    private function link(User $user, Organization $organization, string $label, string $destination): TrackedLink
    {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): TrackedLink => TrackedLink::query()->create([
                'created_by_user_id' => $user->getKey(),
                'token' => Str::random(22),
                'label' => $label,
                'destination_url' => $destination,
                'channel' => 'email',
                'campaign' => 'test',
                'status' => TrackedLink::STATUS_ACTIVE,
            ]),
        );
    }

    private function url(string $action, Organization $organization): string
    {
        return route('organizations.traffic.'.$action, [
            'organizationId' => $organization->getKey(),
        ]);
    }

    private function statusUrl(Organization $organization, TrackedLink $link): string
    {
        return route('organizations.traffic.links.status', [
            'organizationId' => $organization->getKey(),
            'linkId' => $link->getKey(),
        ]);
    }
}
