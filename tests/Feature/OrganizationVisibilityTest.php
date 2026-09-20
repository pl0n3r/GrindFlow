<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_only_users_organizations(): void
    {
        $user = User::factory()->create();
        $visible = Organization::factory()->create(['name' => 'Visible Organization']);
        $foreign = Organization::factory()->create(['name' => 'Foreign Organization']);

        Membership::query()->create([
            'organization_id' => $visible->id,
            'user_id' => $user->id,
            'role' => UserRole::Studio,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Tu operación')
            ->assertSee('Datos de tus organizaciones visibles')
            ->assertSee('Contenido listo')
            ->assertSee('Publicaciones programadas')
            ->assertSee('Visible Organization')
            ->assertDontSee('Foreign Organization')
            ->assertDontSee('PostgreSQL boundary');

        $this->assertDatabaseHas('organizations', ['id' => $foreign->id]);
    }

    public function test_dashboard_hides_forbidden_traffic_link_without_hiding_distribution(): void
    {
        $model = User::factory()->create();
        $editor = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $model->getKey(),
            'role' => UserRole::Model,
        ]);

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $editor->getKey(),
            'role' => UserRole::Editor,
        ]);

        $traffic = route('organizations.traffic.index', [
            'organizationId' => $organization->getKey(),
        ]);
        $distribution = route('organizations.distribution.index', [
            'organizationId' => $organization->getKey(),
        ]);

        $this->actingAs($model)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee($traffic)
            ->assertSee($distribution)
            ->assertSee('aria-disabled="true"', false);

        $this->actingAs($editor)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee($traffic)
            ->assertSee($distribution);
    }

    public function test_dashboard_totals_are_scoped_to_memberships_not_foreign_tenant_data(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $visible = Organization::factory()->create(['name' => 'Visible one']);
        $foreign = Organization::factory()->create(['name' => 'Hidden other']);

        foreach ([[$user, $visible], [$other, $foreign]] as [$member, $organization]) {
            Membership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $member->getKey(),
                'role' => UserRole::Studio,
            ]);
        }

        $this->makeReadyMedia($user, $visible, 'visible.jpg');

        $this->makeReadyMedia($other, $foreign, 'hidden-1.jpg');
        $this->makeReadyMedia($other, $foreign, 'hidden-2.jpg');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-dashboard-metric="ready-media"', false)
            ->assertSee('Recursos listos en tus organizaciones')
            ->assertSee('>1</div>', false)
            ->assertDontSee('Hidden other')
            ->assertDontSee('hidden-1.jpg')
            ->assertDontSee('>3</div>', false);
    }

    public function test_workspace_shows_per_organization_counts_without_foreign_data(): void
    {
        $user = User::factory()->create();
        $foreignUser = User::factory()->create();
        $alpha = Organization::factory()->create(['name' => 'Alpha visible']);
        $beta = Organization::factory()->create(['name' => 'Beta visible']);
        $foreign = Organization::factory()->create(['name' => 'Private foreign']);

        foreach ([$alpha, $beta] as $organization) {
            Membership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'role' => UserRole::Studio,
            ]);
        }

        Membership::query()->create([
            'organization_id' => $foreign->getKey(),
            'user_id' => $foreignUser->getKey(),
            'role' => UserRole::Studio,
        ]);

        $this->makeReadyMedia($user, $alpha, 'alpha.jpg');
        $this->makeReadyMedia($user, $beta, 'beta-one.jpg');
        $this->makeReadyMedia($user, $beta, 'beta-two.jpg');
        $this->makeReadyMedia($foreignUser, $foreign, 'foreign.jpg');

        $response = $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-organization-summary="'.$alpha->getKey().'"', false)
            ->assertSee('data-organization-summary="'.$beta->getKey().'"', false)
            ->assertDontSee('data-organization-summary="'.$foreign->getKey().'"', false)
            ->assertDontSee('Private foreign');

        $html = $response->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression(
            '/Alpha visible.*?Contenido listo:.*?<strong>1<\/strong>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/Beta visible.*?Contenido listo:.*?<strong>2<\/strong>/s',
            $html,
        );
        $this->assertDatabaseCount('media_assets', 4);
    }

    public function test_dashboard_without_organizations_has_genuine_zero_counts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-dashboard-metric="ready-media"', false)
            ->assertSee('data-dashboard-metric="scheduled-publications"', false)
            ->assertSee('>0</div>', false)
            ->assertDontSee('Módulo no disponible');
    }

    private function makeReadyMedia(
        User $user,
        Organization $organization,
        string $filename,
    ): void {
        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($filename): void {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'synthetic/'.$filename,
                    'sha256' => hash('sha256', $filename),
                    'byte_size' => 10,
                    'mime_type' => 'image/jpeg',
                ]);

                MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => $filename,
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                ]);
            },
        );
    }

    public function test_upcoming_publications_only_show_future_rows_for_visible_organizations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $visible = Organization::factory()->create();
        $foreign = Organization::factory()->create();

        foreach ([[$user, $visible], [$other, $foreign]] as [$member, $organization]) {
            Membership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $member->getKey(),
                'role' => UserRole::Studio,
            ]);
        }

        $this->makeScheduledMedia($user, $visible, 'next-visible.jpg', 2);
        $this->makeScheduledMedia($user, $visible, 'past-visible.jpg', -2);
        $this->makeScheduledMedia($other, $foreign, 'hidden-next.jpg', 1);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Próximas publicaciones')
            ->assertSee('next-visible.jpg')
            ->assertDontSee('past-visible.jpg')
            ->assertDontSee('hidden-next.jpg')
            ->assertSee(route('organizations.scheduler.index', [
                'organizationId' => $visible->getKey(),
            ]))
            ->assertDontSee(route('organizations.scheduler.index', [
                'organizationId' => $foreign->getKey(),
            ]));
    }

    public function test_upcoming_publications_has_true_empty_state(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Sin próximas publicaciones')
            ->assertSee('Sin fechas pasadas pendientes')
            ->assertDontSee('Agenda no disponible');
    }

    public function test_past_due_agenda_excludes_future_and_foreign_tenant_rows(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $visible = Organization::factory()->create();
        $foreign = Organization::factory()->create();

        foreach ([[$user, $visible], [$other, $foreign]] as [$member, $organization]) {
            Membership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $member->getKey(),
                'role' => UserRole::Studio,
            ]);
        }

        $this->makeScheduledMedia($user, $visible, 'past-own.jpg', -2);
        $this->makeScheduledMedia($user, $visible, 'future-own.jpg', 2);
        $this->makeScheduledMedia($other, $foreign, 'past-foreign.jpg', -3);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('Programaciones con fecha pasada')
            ->assertSee('data-past-due-publication=', false)
            ->assertSee('past-own.jpg')
            ->assertDontSee('past-foreign.jpg')
            ->assertSee('esto no confirma un fallo de entrega');

        $html = $response->getContent();
        $this->assertIsString($html);
        $pastDue = explode('aria-label="Programaciones con fecha pasada"', $html, 2)[1];
        $this->assertStringContainsString('past-own.jpg', $pastDue);
        $this->assertStringNotContainsString('future-own.jpg', $pastDue);
    }

    private function makeScheduledMedia(
        User $user,
        Organization $organization,
        string $filename,
        int $daysFromNow,
    ): void {
        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user, $filename, $daysFromNow): void {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => 'synthetic/schedule/'.$filename,
                    'sha256' => hash('sha256', 'schedule-'.$filename),
                    'byte_size' => 10,
                    'mime_type' => 'image/jpeg',
                ]);
                $media = MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'original_filename' => $filename,
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                ]);
                $destination = PublishingDestination::query()->create([
                    'name' => 'Canal de prueba',
                    'provider' => 'provider-test',
                    'status' => 'active',
                ]);
                ScheduledPublication::query()->create([
                    'media_asset_id' => $media->getKey(),
                    'publishing_destination_id' => $destination->getKey(),
                    'scheduled_by_user_id' => $user->getKey(),
                    'status' => 'scheduled',
                    'timezone' => 'UTC',
                    'scheduled_for_utc' => CarbonImmutable::now('UTC')
                        ->addDays($daysFromNow)
                        ->startOfMinute(),
                ]);
            },
        );
    }

    public function test_guest_cannot_open_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
