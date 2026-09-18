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
class TenancyRlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_rls_blocks_cross_tenant_reads_writes_and_self_escalation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the RLS contract.');
        }

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $organizationA = Organization::factory()->create(['name' => 'Organization A']);
        $organizationB = Organization::factory()->create(['name' => 'Organization B']);

        $membershipA = Membership::query()->create([
            'organization_id' => $organizationA->id,
            'user_id' => $userA->id,
            'role' => UserRole::Studio,
        ]);

        Membership::query()->create([
            'organization_id' => $organizationB->id,
            'user_id' => $userB->id,
            'role' => UserRole::Studio,
        ]);

        DB::unprepared(<<<'SQL'
            do $$
            begin
                if not exists (select 1 from pg_roles where rolname = 'grindflow_rls_test') then
                    create role grindflow_rls_test nologin;
                end if;
            end
            $$;

            grant usage on schema public, app to grindflow_rls_test;
            grant select, insert, update, delete
                on table public.organizations, public.memberships
                to grindflow_rls_test;
            SQL);

        try {
            DB::statement('set role grindflow_rls_test');
            DB::select("select set_config('app.current_user_id', ?, false)", [$userA->id]);

            $visibleOrganizations = DB::table('organizations')
                ->orderBy('name')
                ->pluck('id')
                ->all();

            $this->assertSame([$organizationA->id], $visibleOrganizations);

            $affected = DB::table('organizations')
                ->where('id', $organizationB->id)
                ->update(['name' => 'Cross-tenant mutation']);

            $this->assertSame(0, $affected);

            try {
                DB::transaction(function () use ($organizationB, $userA): void {
                    DB::table('memberships')->insert([
                        'id' => fake()->uuid(),
                        'organization_id' => $organizationB->id,
                        'user_id' => $userA->id,
                        'role' => UserRole::Editor->value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

                $this->fail('Cross-tenant membership insertion should be rejected by RLS.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                DB::transaction(function () use ($membershipA): void {
                    DB::table('memberships')
                        ->where('id', $membershipA->id)
                        ->update(['role' => UserRole::Admin->value]);
                });

                $this->fail('Users must not be able to elevate their own membership role.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        } finally {
            DB::statement('reset role');
            DB::select("select set_config('app.current_user_id', '', false)");
            DB::unprepared('drop owned by grindflow_rls_test; drop role if exists grindflow_rls_test;');
        }
    }
}
