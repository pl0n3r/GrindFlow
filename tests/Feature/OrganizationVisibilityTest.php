<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
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

    public function test_guest_cannot_open_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
