<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('database')]
class MariaDbIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_identity_is_immutable_at_database_level(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL is required for this integrity contract.');
        }

        $user = User::factory()->create();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $membership = Membership::query()->create([
            'organization_id' => $organizationA->id,
            'user_id' => $user->id,
            'role' => UserRole::Studio,
        ]);

        $this->expectException(QueryException::class);

        DB::table('memberships')
            ->where('id', $membership->id)
            ->update(['organization_id' => $organizationB->id]);
    }

    public function test_invalid_membership_role_is_rejected_by_mariadb_enum(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL is required for this integrity contract.');
        }

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('memberships')->insert([
            'id' => fake()->uuid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'invalid-role',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
