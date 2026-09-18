<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantScopedModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_scope_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tenant_scope_records');

        parent::tearDown();
    }

    public function test_tenant_models_fail_closed_without_organization_context(): void
    {
        $organization = Organization::factory()->create();

        DB::table('tenant_scope_records')->insert([
            'id' => fake()->uuid(),
            'organization_id' => $organization->id,
            'name' => 'Hidden without context',
        ]);

        $this->assertSame(0, TenantScopeRecord::query()->count());
    }

    public function test_context_scopes_reads_and_forces_organization_on_create(): void
    {
        $user = User::factory()->create();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organizationA->id,
            'user_id' => $user->id,
            'role' => UserRole::Editor,
        ]);

        DB::table('tenant_scope_records')->insert([
            [
                'id' => fake()->uuid(),
                'organization_id' => $organizationA->id,
                'name' => 'Visible A',
            ],
            [
                'id' => fake()->uuid(),
                'organization_id' => $organizationB->id,
                'name' => 'Hidden B',
            ],
        ]);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            $organizationA->id,
            function () use ($organizationA): void {
                $this->assertSame(['Visible A'], TenantScopeRecord::query()->pluck('name')->all());

                $record = TenantScopeRecord::query()->create([
                    'id' => fake()->uuid(),
                    'name' => 'Created inside tenant',
                ]);

                $this->assertSame($organizationA->id, $record->organization_id);
            },
        );
    }

    public function test_cross_tenant_create_is_rejected(): void
    {
        $user = User::factory()->create();
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organizationA->id,
            'user_id' => $user->id,
            'role' => UserRole::Editor,
        ]);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            $organizationA->id,
            fn () => TenantScopeRecord::query()->create([
                'id' => fake()->uuid(),
                'organization_id' => $organizationB->id,
                'name' => 'Forbidden',
            ]),
        );
    }
}

class TenantScopeRecord extends Model
{
    use BelongsToOrganization;

    protected $table = 'tenant_scope_records';

    public $timestamps = false;

    protected $guarded = [];
}
