<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\FinanceLedgerManager;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_currency_and_beneficiary_groups_reconcile_with_event_date_filters(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$beneficiary] = $this->identity(UserRole::Model, $organization);
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));

        try {
            app(TenantContext::class)->runWithinOrganization(
                $studio,
                (string) $organization->getKey(),
                function () use ($studio, $beneficiary): void {
                    $manager = app(FinanceLedgerManager::class);
                    $original = $manager->create(
                        $studio, 'Earlier COP revenue', 5000, 'COP',
                        CarbonImmutable::parse('2026-09-18', 'UTC'),
                        (string) $beneficiary->getKey(), null,
                    );
                    $manager->create(
                        $studio, 'Current COP revenue', 700, 'COP',
                        CarbonImmutable::parse('2026-09-20', 'UTC'),
                        (string) $beneficiary->getKey(), null,
                    );
                    $manager->create(
                        $studio, 'Unassigned USD', 2500, 'USD',
                        CarbonImmutable::parse('2026-09-20', 'UTC'),
                        null, null,
                    );
                    $manager->reverse($studio, (string) $original->getKey(), 'Correction');
                },
            );

            $url = $this->indexRoute($organization);
            $all = $this->actingAs($studio)->get($url)
                ->assertOk()
                ->assertSee('Beneficiary reconciliation')
                ->assertSee('Download reconciliation CSV')
                ->assertSee('5,700')
                ->assertSee('5,000')
                ->assertSee('2,500');
            $currency = $all->viewData('currencySummaries')->keyBy('currency');
            $this->assertSame(700, $currency['COP']['net_minor']);
            $this->assertSame(2500, $currency['USD']['net_minor']);
            $this->assertSame(4, $all->viewData('allocations')->total());

            $period = $this->actingAs($studio)->get(
                $url.'?from=2026-09-20&to=2026-09-20&currency=cop&beneficiary='.$beneficiary->getKey(),
            )->assertOk()->assertSee('-4,300');

            $rows = $period->viewData('beneficiarySummaries');
            $this->assertCount(1, $rows);
            $this->assertSame(2, $rows[0]['events']);
            $this->assertSame(700, $rows[0]['allocated_minor']);
            $this->assertSame(5000, $rows[0]['reversed_minor']);
            $this->assertSame(-4300, $rows[0]['net_minor']);
            $this->assertSame(2, $period->viewData('allocations')->total());
            $this->assertSame('COP', $period->viewData('filters')['currency']);
            $this->assertSame(-4300, $period->viewData('currencySummaries')[0]['net_minor']);

            $unassigned = $this->actingAs($studio)->get($url.'?beneficiary=unassigned')
                ->assertOk();
            $this->assertSame(1, $unassigned->viewData('allocations')->total());
            $this->assertSame('USD', $unassigned->viewData('beneficiarySummaries')[0]['currency']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_full_reconciliation_csv_and_ledger_pagination_do_not_leak_foreign_records(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$beneficiary] = $this->identity(UserRole::Model, $organization);
        $beneficiary->forceFill(['name' => '=SUM(1,2)'])->save();
        [$otherStudio, $otherOrganization] = $this->identity(UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            function () use ($studio, $beneficiary): void {
                $manager = app(FinanceLedgerManager::class);

                for ($i = 0; $i < 53; $i++) {
                    $manager->create(
                        $studio, 'Local revenue '.$i, 100, 'COP',
                        CarbonImmutable::parse('2026-09-19', 'UTC'),
                        (string) $beneficiary->getKey(), null,
                    );
                }
            },
        );

        app(TenantContext::class)->runWithinOrganization(
            $otherStudio,
            (string) $otherOrganization->getKey(),
            fn () => app(FinanceLedgerManager::class)->create(
                $otherStudio, 'Foreign secret', 9000000, 'COP',
                CarbonImmutable::parse('2026-09-19', 'UTC'),
                null, null,
            ),
        );

        $url = $this->indexRoute($organization);
        $first = $this->actingAs($studio)->get($url)
            ->assertOk()
            ->assertSee('53 matching')
            ->assertSee('Showing 1–25 of 53')
            ->assertDontSee('Foreign secret')
            ->assertDontSee('9,000,000');

        $this->assertSame(25, $first->viewData('allocations')->count());
        $this->assertSame(5300, $first->viewData('currencySummaries')[0]['net_minor']);
        $this->assertSame(53, $first->viewData('beneficiarySummaries')[0]['events']);
        $this->assertSame(5300, $first->viewData('beneficiarySummaries')[0]['net_minor']);

        $next = $first->viewData('allocations')->nextPageUrl();
        $this->assertNotNull($next);
        $second = $this->actingAs($studio)->get($next)
            ->assertOk()
            ->assertSee('Showing 26–50 of 53');
        $last = $this->actingAs($studio)->get($url.'?page=3')
            ->assertOk()
            ->assertSee('Showing 51–53 of 53');
        $this->assertSame(3, $last->viewData('allocations')->count());

        $this->actingAs($studio)->get($url.'?page=8')
            ->assertOk()
            ->assertSee('No ledger events on this page.')
            ->assertSee('Go to last ledger page');

        $csv = $this->actingAs($studio)->get($this->exportRoute($organization))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Cache-Control', 'private, no-store')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $content = $csv->streamedContent();
        $this->assertStringContainsString(
            'currency,beneficiary,allocated_minor,reversed_minor,net_minor,events',
            $content,
        );
        $this->assertStringContainsString("'=SUM(1,2)", $content);
        $this->assertStringContainsString('5300,0,5300,53', $content);
        $this->assertStringNotContainsString('Foreign secret', $content);
        $this->assertStringNotContainsString('9000000', $content);
        $this->assertStringNotContainsString((string) $beneficiary->getKey(), $content);
        $this->assertSame(2, count(array_filter(explode("\n", $content))));
    }

    public function test_reconciliation_validates_filters_and_preserves_them_across_pages(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$otherUser] = $this->identity(UserRole::Model);

        app(TenantContext::class)->runWithinOrganization(
            $studio,
            (string) $organization->getKey(),
            function () use ($studio): void {
                $manager = app(FinanceLedgerManager::class);
                for ($i = 0; $i < 27; $i++) {
                    $manager->create(
                        $studio, 'September COP '.$i, 20, 'COP',
                        CarbonImmutable::parse('2026-09-19', 'UTC'),
                        null, null,
                    );
                }
            },
        );

        $url = $this->indexRoute($organization);
        $filters = [
            'currency' => 'cop',
            'beneficiary' => 'unassigned',
            'from' => '2026-09-19',
            'to' => '2026-09-19',
        ];

        $first = $this->actingAs($studio)->get($url.'?'.http_build_query($filters))
            ->assertOk()->assertSee('27 matching');
        $next = $first->viewData('allocations')->nextPageUrl();
        $this->assertIsString($next);
        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('2', $query['page']);
        unset($query['page']);
        $filters['currency'] = 'COP';
        $this->assertEquals($filters, $query);

        $this->actingAs($studio)->get($next)->assertOk()
            ->assertSee('Showing 26–27 of 27');
        $export = $this->actingAs($studio)->get(
            $this->exportRoute($organization).'?'.http_build_query($filters),
        )->assertOk();
        $this->assertStringContainsString('540,0,540,27', $export->streamedContent());

        foreach ([
            '?currency=US%24' => 'currency',
            '?from=2026-09-20&to=2026-09-19' => 'to',
            '?beneficiary='.$otherUser->getKey() => 'beneficiary',
            '?page=0' => 'page',
            '?page=10001' => 'page',
        ] as $queryString => $key) {
            $this->actingAs($studio)->get($url.$queryString)
                ->assertSessionHasErrors($key);
        }

        $this->actingAs($studio)
            ->get($this->exportRoute($organization).'?currency=Z%3D1')
            ->assertSessionHasErrors('currency');
    }

    public function test_finance_csv_is_private_and_safe_before_schema_migration(): void
    {
        [$studio, $organization] = $this->identity(UserRole::Studio);
        [$editor] = $this->identity(UserRole::Editor, $organization);
        [$outsider, $foreignOrganization] = $this->identity(UserRole::Studio);
        $url = $this->exportRoute($organization);

        $this->actingAs($editor)->get($url)->assertForbidden();
        $this->actingAs($outsider)->get($url)->assertNotFound();
        $this->actingAs($studio)->get($this->exportRoute($foreignOrganization))
            ->assertNotFound();

        Schema::dropIfExists('revenue_allocations');
        try {
            $this->actingAs($studio)->get($this->indexRoute($organization))
                ->assertOk()
                ->assertSee('Finance migration required');
            $this->actingAs($studio)->get($url)->assertStatus(503);
        } finally {
            $migration = require database_path(
                'migrations/2026_09_19_041500_create_revenue_allocations_table.php',
            );
            $migration->up();
        }
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

    private function indexRoute(Organization $organization): string
    {
        return route('organizations.finance.index', [
            'organizationId' => $organization->getKey(),
        ]);
    }

    private function exportRoute(Organization $organization): string
    {
        return route('organizations.finance.reconciliation.export', [
            'organizationId' => $organization->getKey(),
        ]);
    }
}
