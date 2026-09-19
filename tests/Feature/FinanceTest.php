<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RevenueAllocation;
use App\Models\User;
use App\Services\Finance\FinanceLedgerManager;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_can_create_tenant_scoped_revenue_allocation(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$beneficiary] = $this->identity(
            UserRole::Model,
            $organization,
        );

        $this->actingAs($studio)
            ->post(
                route('organizations.finance.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'source_label' => 'September subscription revenue',
                    'amount_minor' => 125000,
                    'currency' => 'cop',
                    'occurred_on' => '2026-09-19',
                    'beneficiary_user_id' => $beneficiary->getKey(),
                    'note' => 'Studio allocation',
                ],
            )
            ->assertRedirect(
                route('organizations.finance.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )
            ->assertSessionHas('status');

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            function () use ($studio, $beneficiary): void {
                $allocation = RevenueAllocation::query()->sole();

                $this->assertSame(
                    $studio->getKey(),
                    $allocation->created_by_user_id,
                );
                $this->assertSame(
                    $beneficiary->getKey(),
                    $allocation->beneficiary_user_id,
                );
                $this->assertSame(125000, $allocation->amount_minor);
                $this->assertSame('COP', $allocation->currency);
                $this->assertSame(
                    '2026-09-19',
                    $allocation->occurred_on->toDateString(),
                );
                $this->assertNull($allocation->reversal_of_id);
            },
        );
    }

    public function test_editor_and_model_cannot_manage_finance(): void
    {
        foreach ([UserRole::Editor, UserRole::Model] as $role) {
            [$user, $organization] = $this->identity($role);

            $this->actingAs($user)
                ->get(
                    route('organizations.finance.index', [
                        'organizationId' => $organization->getKey(),
                    ]),
                )
                ->assertForbidden();

            $this->actingAs($user)
                ->post(
                    route('organizations.finance.store', [
                        'organizationId' => $organization->getKey(),
                    ]),
                    [
                        'source_label' => 'Forbidden',
                        'amount_minor' => 100,
                        'currency' => 'COP',
                        'occurred_on' => '2026-09-19',
                    ],
                )
                ->assertForbidden();
        }
    }

    public function test_foreign_beneficiary_is_rejected(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$foreignUser] = $this->identity(UserRole::Model);

        $this->actingAs($studio)
            ->from(
                route('organizations.finance.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )
            ->post(
                route('organizations.finance.store', [
                    'organizationId' => $organization->getKey(),
                ]),
                [
                    'source_label' => 'Cross tenant',
                    'amount_minor' => 500,
                    'currency' => 'USD',
                    'occurred_on' => '2026-09-19',
                    'beneficiary_user_id' => $foreignUser->getKey(),
                ],
            )
            ->assertRedirect()
            ->assertSessionHasErrors('beneficiary_user_id');

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn () => $this->assertSame(
                0,
                RevenueAllocation::query()->count(),
            ),
        );
    }

    public function test_reversal_is_append_only_and_cannot_repeat(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);

        $original = app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn (): RevenueAllocation => app(FinanceLedgerManager::class)
                ->create(
                    $studio,
                    'Campaign revenue',
                    9000,
                    'USD',
                    CarbonImmutable::parse('2026-09-19', 'UTC'),
                    null,
                    null,
                ),
        );

        $reversal = app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn (): RevenueAllocation => app(FinanceLedgerManager::class)
                ->reverse(
                    $studio,
                    (string) $original->getKey(),
                    'Incorrect allocation',
                ),
        );

        $this->assertSame(
            $original->getKey(),
            $reversal->reversal_of_id,
        );
        $this->assertSame($original->amount_minor, $reversal->amount_minor);
        $this->assertSame($original->currency, $reversal->currency);
        $this->assertSame('Incorrect allocation', $reversal->note);

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            function (): void {
                $this->assertSame(
                    2,
                    RevenueAllocation::query()->count(),
                );

                $allocated = (int) RevenueAllocation::query()
                    ->whereNull('reversal_of_id')
                    ->sum('amount_minor');
                $reversed = (int) RevenueAllocation::query()
                    ->whereNotNull('reversal_of_id')
                    ->sum('amount_minor');

                $this->assertSame(0, $allocated - $reversed);
            },
        );

        $this->expectException(ValidationException::class);

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn (): RevenueAllocation => app(FinanceLedgerManager::class)
                ->reverse(
                    $studio,
                    (string) $original->getKey(),
                    'Second reversal',
                ),
        );
    }

    public function test_reversal_cannot_itself_be_reversed(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);

        [$original, $reversal] = app(TenantContext::class)
            ->runWithinOrganization(
                $studio,
                (string) $organization->getKey(),
                function () use ($studio): array {
                    $manager = app(FinanceLedgerManager::class);
                    $original = $manager->create(
                        $studio,
                        'Revenue',
                        1000,
                        'COP',
                        CarbonImmutable::parse('2026-09-19', 'UTC'),
                        null,
                        null,
                    );

                    return [
                        $original,
                        $manager->reverse(
                            $studio,
                            (string) $original->getKey(),
                            'Correction',
                        ),
                    ];
                },
            );

        $this->assertNotNull($original);
        $this->expectException(ValidationException::class);

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn (): RevenueAllocation => app(FinanceLedgerManager::class)
                ->reverse(
                    $studio,
                    (string) $reversal->getKey(),
                    'Not allowed',
                ),
        );
    }

    public function test_cross_tenant_reversal_is_not_visible(): void
    {
        [$firstUser, $firstOrganization] = $this->identity(UserRole::Studio);
        [$secondUser, $secondOrganization] = $this->identity(UserRole::Studio);

        $foreign = app(TenantContext::class)->runWithinOrganization(
            $firstUser,
            (string) $firstOrganization->getKey(),
            fn (): RevenueAllocation => app(FinanceLedgerManager::class)
                ->create(
                    $firstUser,
                    'Foreign revenue',
                    1000,
                    'USD',
                    CarbonImmutable::parse('2026-09-19', 'UTC'),
                    null,
                    null,
                ),
        );

        $this->actingAs($secondUser)
            ->post(
                route('organizations.finance.reverse', [
                    'organizationId' => $secondOrganization->getKey(),
                    'allocationId' => $foreign->getKey(),
                ]),
                ['reason' => 'Cross tenant attempt'],
            )
            ->assertNotFound();
    }

    public function test_finance_index_does_not_leak_foreign_entries(): void
    {
        [$user, $organization] = $this->identity(UserRole::Studio);
        [$otherUser, $otherOrganization] = $this->identity(UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): RevenueAllocation => app(FinanceLedgerManager::class)
                ->create(
                    $user,
                    'Local finance record',
                    2000,
                    'COP',
                    CarbonImmutable::parse('2026-09-19', 'UTC'),
                    null,
                    null,
                ),
        );

        app(TenantContext::class)->runWithinOrganization(
            $otherUser,
            (string) $otherOrganization->getKey(),
            fn (): RevenueAllocation => app(FinanceLedgerManager::class)
                ->create(
                    $otherUser,
                    'Foreign secret record',
                    3000,
                    'COP',
                    CarbonImmutable::parse('2026-09-19', 'UTC'),
                    null,
                    null,
                ),
        );

        $this->actingAs($user)
            ->get(
                route('organizations.finance.index', [
                    'organizationId' => $organization->getKey(),
                ]),
            )
            ->assertOk()
            ->assertSee('Local finance record')
            ->assertDontSee('Foreign secret record');
    }

    public function test_finance_ledger_entries_cannot_be_updated_or_deleted(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);

        $allocation = app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn (): RevenueAllocation => app(FinanceLedgerManager::class)
                ->create(
                    $studio,
                    'Immutable record',
                    1000,
                    'COP',
                    CarbonImmutable::parse('2026-09-19', 'UTC'),
                    null,
                    null,
                ),
        );

        try {
            app(TenantContext::class)->runWithinOrganization(
                $studio,
                (string) $organization->getKey(),
                function () use ($allocation): void {
                    RevenueAllocation::query()
                        ->findOrFail($allocation->getKey())
                        ->forceFill(['amount_minor' => 1])
                        ->save();
                },
            );

            $this->fail('Updating a finance ledger entry must fail.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'append-only',
                $exception->getMessage(),
            );
        }

        $this->expectException(LogicException::class);

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn () => RevenueAllocation::query()
                ->findOrFail($allocation->getKey())
                ->delete(),
        );
    }

    public function test_finance_endpoints_are_safe_before_migration(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);

        Schema::dropIfExists('revenue_allocations');

        $migrationPath = database_path(
            'migrations/2026_09_19_041500_create_revenue_allocations_table.php',
        );

        try {
            $index = route('organizations.finance.index', [
                'organizationId' => $organization->getKey(),
            ]);

            $this->actingAs($studio)
                ->get($index)
                ->assertOk()
                ->assertSee('Finance migration required');

            $this->actingAs($studio)
                ->post($index, [])
                ->assertStatus(503);

            $this->actingAs($studio)
                ->post(
                    route('organizations.finance.reverse', [
                        'organizationId' => $organization->getKey(),
                        'allocationId' => (string) Str::uuid(),
                    ]),
                    ['reason' => 'Migration not ready'],
                )
                ->assertStatus(503);
        } finally {
            $migration = require $migrationPath;
            $migration->up();
        }
    }

    /**
     * @return array{User, Organization}
     */
    private function identity(
        UserRole $role,
        ?Organization $organization = null,
    ): array {
        $user = User::factory()->create();
        $organization ??= Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        return [$user, $organization];
    }
}
