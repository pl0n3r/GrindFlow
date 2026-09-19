<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
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
            ->assertSee('Overview')
            ->assertSee('Tenant isolation active')
            ->assertSee('Laravel + MariaDB integrity')
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

    public function test_guest_cannot_open_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
