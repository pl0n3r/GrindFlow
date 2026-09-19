<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\RevenueAllocation;
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

    public function test_finance_ledger_rejects_direct_updates_in_mariadb(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL is required for this integrity contract.');
        }

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $allocation = $this->financeAllocation(
            $user,
            $organization,
        );

        $this->expectException(QueryException::class);

        DB::table('revenue_allocations')
            ->where('id', $allocation->id)
            ->update(['amount_minor' => 1]);
    }

    public function test_finance_ledger_rejects_direct_deletes_in_mariadb(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL is required for this integrity contract.');
        }

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $allocation = $this->financeAllocation(
            $user,
            $organization,
        );

        $this->expectException(QueryException::class);

        DB::table('revenue_allocations')
            ->where('id', $allocation->id)
            ->delete();
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
    private function financeAllocation(
        User $user,
        Organization $organization,
    ): RevenueAllocation {
        Membership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => UserRole::Studio,
        ]);

        return RevenueAllocation::query()
            ->withoutGlobalScopes()
            ->create([
                'organization_id' => $organization->id,
                'created_by_user_id' => $user->id,
                'source_label' => 'Integrity test',
                'amount_minor' => 1000,
                'currency' => 'COP',
                'occurred_on' => '2026-09-19',
            ]);
    }
}
