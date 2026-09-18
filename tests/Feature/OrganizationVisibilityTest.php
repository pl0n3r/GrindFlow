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
            ->assertSee('Visible Organization')
            ->assertDontSee('Foreign Organization');

        $this->assertDatabaseHas('organizations', ['id' => $foreign->id]);
    }

    public function test_guest_cannot_open_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
