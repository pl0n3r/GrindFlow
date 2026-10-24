<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Http\Controllers\Finance\FinanceController;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RevenueAllocation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinanceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_dispatch_to_the_real_finance_controller(): void
    {
        foreach ([
            'organizations.finance.index' => 'index',
            'organizations.finance.export' => 'export',
            'organizations.finance.store' => 'store',
            'organizations.finance.reverse' => 'reverse',
        ] as $name => $method) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame(FinanceController::class.'@'.$method, $route->getActionName());
        }
    }

    public function test_unauthorized_roles_cannot_read_export_create_or_reverse(): void
    {
        foreach ([UserRole::Editor, UserRole::Model] as $role) {
            [$actor, $organization] = $this->identity($role);
            $base = ['organizationId' => $organization->getKey()];

            $this->actingAs($actor)->get(route('organizations.finance.index', $base))
                ->assertForbidden();
            $this->actingAs($actor)->get(route('organizations.finance.export', $base))
                ->assertForbidden();
            $this->actingAs($actor)->post(route('organizations.finance.store', $base), [
                'source_label' => 'Not permitted',
                'amount_minor' => 100,
                'currency' => 'COP',
                'occurred_on' => '2026-10-01',
            ])->assertForbidden();
            $this->actingAs($actor)->post(route('organizations.finance.reverse', [
                ...$base,
                'allocationId' => (string) Str::uuid(),
            ]), ['reason' => 'Not permitted'])->assertForbidden();
        }
    }

    public function test_invalid_minor_units_and_foreign_beneficiaries_never_create_entries(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$foreignBeneficiary] = $this->identity(UserRole::Model);
        $url = route('organizations.finance.store', [
            'organizationId' => $organization->getKey(),
        ]);
        $payload = [
            'source_label' => 'Validation fixture',
            'amount_minor' => 1200,
            'currency' => 'usd',
            'occurred_on' => '2026-10-01',
        ];

        foreach ([0, -1, '1.5', '9000000000000001'] as $invalid) {
            $this->actingAs($studio)->post($url, [
                ...$payload,
                'amount_minor' => $invalid,
            ])->assertSessionHasErrors('amount_minor');
        }

        $this->actingAs($studio)->post($url, [
            ...$payload,
            'beneficiary_user_id' => $foreignBeneficiary->getKey(),
        ])->assertSessionHasErrors('beneficiary_user_id');

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn () => $this->assertSame(0, RevenueAllocation::query()->count()),
        );

        $this->actingAs($studio)->post($url, $payload)
            ->assertRedirect(route('organizations.finance.index', [
                'organizationId' => $organization->getKey(),
            ]));

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            function (): void {
                $allocation = RevenueAllocation::query()->sole();
                $this->assertSame(1200, $allocation->amount_minor);
                $this->assertSame('USD', $allocation->currency);
            },
        );
    }

    public function test_reversal_is_append_only_once_and_foreign_tenant_cannot_reverse(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$outsider, $foreignOrganization] = $this->identity(UserRole::Studio);
        $index = route('organizations.finance.index', [
            'organizationId' => $organization->getKey(),
        ]);

        $this->actingAs($studio)->post(route('organizations.finance.store', [
            'organizationId' => $organization->getKey(),
        ]), [
            'source_label' => 'Reversal fixture',
            'amount_minor' => 3150,
            'currency' => 'COP',
            'occurred_on' => '2026-10-01',
        ])->assertRedirect($index);

        $original = app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            fn (): RevenueAllocation => RevenueAllocation::query()->sole(),
        );

        $reversalRoute = route('organizations.finance.reverse', [
            'organizationId' => $organization->getKey(),
            'allocationId' => $original->getKey(),
        ]);

        $this->actingAs($studio)->post($reversalRoute, [
            'reason' => 'Reconcile mistaken allocation',
        ])->assertRedirect($index)
            ->assertSessionHas('status', 'Revenue allocation reversed.');

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            function () use ($original): void {
                $this->assertSame(2, RevenueAllocation::query()->count());
                $reversal = RevenueAllocation::query()
                    ->where('reversal_of_id', $original->getKey())->sole();
                $this->assertSame(3150, $reversal->amount_minor);
                $this->assertSame('COP', $reversal->currency);
                $this->assertSame('Reconcile mistaken allocation', $reversal->note);
            },
        );

        $this->actingAs($studio)->post($reversalRoute, [
            'reason' => 'Duplicate reversal',
        ])->assertSessionHasErrors('allocation');

        $this->actingAs($outsider)->post(route('organizations.finance.reverse', [
            'organizationId' => $foreignOrganization->getKey(),
            'allocationId' => $original->getKey(),
        ]), ['reason' => 'Cross-tenant attempt'])->assertNotFound();
    }

    public function test_reconciliation_and_csv_keep_currency_and_tenant_boundaries(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$beneficiary] = $this->identity(UserRole::Model, $organization);
        $beneficiary->forceFill(['name' => '=SUM(1,2)'])->save();
        [$outsider, $foreignOrganization] = $this->identity(UserRole::Studio);

        foreach ([
            [$studio, $organization, 'Own COP record', 1600, 'COP', $beneficiary->getKey()],
            [$studio, $organization, 'Own USD record', 300, 'USD', null],
            [$outsider, $foreignOrganization, 'Foreign private record', 99999, 'COP', null],
        ] as [$actor, $org, $label, $amount, $currency, $userId]) {
            $payload = [
                'source_label' => $label,
                'amount_minor' => $amount,
                'currency' => $currency,
                'occurred_on' => '2026-10-01',
            ];
            if ($userId !== null) {
                $payload['beneficiary_user_id'] = $userId;
            }
            $this->actingAs($actor)->post(route('organizations.finance.store', [
                'organizationId' => $org->getKey(),
            ]), $payload)->assertRedirect();
        }

        $base = ['organizationId' => $organization->getKey()];
        $index = $this->actingAs($studio)->get(
            route('organizations.finance.index', $base).'?currency=cop',
        )->assertOk()->assertSee('Own COP record')
            ->assertDontSee('Foreign private record')
            ->assertDontSee('Own USD record');

        $summaries = $index->viewData('currencySummaries');
        $this->assertCount(1, $summaries);
        $this->assertSame('COP', $summaries[0]['currency']);
        $this->assertSame(1600, $summaries[0]['net_minor']);

        $csv = $this->actingAs($studio)->get(
            route('organizations.finance.export', $base).'?currency=cop',
        )->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $body = $csv->streamedContent();
        $this->assertStringContainsString("'=SUM(1,2)", $body);
        $this->assertStringContainsString('1600,0,1600,1', $body);
        $this->assertStringNotContainsString('99999', $body);
        $this->assertStringNotContainsString('Foreign private record', $body);
        $this->assertStringNotContainsString((string) $beneficiary->getKey(), $body);
    }

    /**
     * @return array{User, Organization}
     */
    private function identity(UserRole $role, ?Organization $organization = null): array
    {
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
