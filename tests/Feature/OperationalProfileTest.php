<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\OperationalProfile;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_identity_is_distinct_from_membership_and_tenant_scoped(): void
    {
        $user = User::factory()->create();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $membership = Membership::query()->create([
            'organization_id' => $organizationA->id,
            'user_id' => $user->id,
            'role' => UserRole::Editor,
        ]);

        DB::table('operational_profiles')->insert([
            'id' => fake()->uuid(),
            'organization_id' => $organizationB->id,
            'name' => 'Foreign profile',
            'slug' => 'foreign-profile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            $organizationA->id,
            function () use ($organizationA, $membership): void {
                $profile = OperationalProfile::query()->create([
                    'name' => 'Canonical profile',
                    'slug' => 'canonical-profile',
                ]);

                $this->assertSame($organizationA->id, $profile->organization_id);
                $this->assertNotSame($membership->id, $profile->id);
                $this->assertSame(['Canonical profile'], OperationalProfile::query()->pluck('name')->all());
            },
        );

        $this->assertTrue(Schema::hasColumn('operational_profiles', 'organization_id'));
        $this->assertFalse(Schema::hasColumn('operational_profiles', 'membership_id'));
        $this->assertFalse(Schema::hasColumn('operational_profiles', 'user_id'));
    }

    public function test_profile_access_rejects_cross_tenant_and_missing_membership(): void
    {
        $user = User::factory()->create();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organizationA->id,
            'user_id' => $user->id,
            'role' => UserRole::Editor,
        ]);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            $organizationA->id,
            function () use ($organizationA, $organizationB): void {
                $massAssigned = OperationalProfile::query()->create([
                    'organization_id' => $organizationB->id,
                    'name' => 'Mass assigned tenant',
                    'slug' => 'mass-assigned-tenant',
                ]);
                $this->assertSame($organizationA->id, $massAssigned->organization_id);
                $this->assertDatabaseHas('operational_profiles', [
                    'id' => $massAssigned->id,
                    'organization_id' => $organizationA->id,
                ]);
                $this->assertDatabaseMissing('operational_profiles', [
                    'organization_id' => $organizationB->id,
                    'slug' => 'mass-assigned-tenant',
                ]);

                $this->expectException(AuthorizationException::class);

                $profile = new OperationalProfile([
                    'name' => 'Cross tenant',
                    'slug' => 'cross-tenant',
                ]);
                $profile->forceFill(['organization_id' => $organizationB->id]);
                $profile->save();
            },
        );
    }

    public function test_missing_membership_cannot_enter_profile_tenant_context(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            $organization->id,
            fn () => OperationalProfile::query()->count(),
        );
    }
}
