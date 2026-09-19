<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReverseRevenueAllocationRequest;
use App\Http\Requests\Finance\StoreRevenueAllocationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\FinanceLedgerManager;
use App\Services\Finance\FinanceReconciliationReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function index(
        Request $request,
        FinanceReconciliationReport $report,
    ): View {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canManageFinanceOrganization($organization), 403);

        $financeReady = Schema::hasTable('revenue_allocations');
        $allocations = collect();
        $beneficiaries = collect();
        $currencySummaries = collect();
        $beneficiarySummaries = collect();
        $filters = [];

        if ($financeReady) {
            $beneficiaries = $organization->users()
                ->orderBy('name')
                ->get();

            $filters = $this->validatedFilters($request, $beneficiaries);
            $currencySummaries = $report->currencySummaries($filters);
            $beneficiarySummaries = $this->labeledSummaries(
                $report->beneficiarySummaries($filters),
                $beneficiaries,
            );

            $allocations = $report->filtered($filters)
                ->with(['beneficiary', 'createdBy', 'reversalOf', 'reversal'])
                ->orderByDesc('occurred_on')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->appends($filters);
        }

        return view('finance.index', [
            'organization' => $organization,
            'financeReady' => $financeReady,
            'allocations' => $allocations,
            'beneficiaries' => $beneficiaries,
            'currencySummaries' => $currencySummaries,
            'beneficiarySummaries' => $beneficiarySummaries,
            'filters' => $filters,
        ]);
    }

    public function export(
        Request $request,
        FinanceReconciliationReport $report,
    ): StreamedResponse {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canManageFinanceOrganization($organization), 403);

        $beneficiaries = $organization->users()
            ->orderBy('name')
            ->get();
        $filters = $this->validatedFilters($request, $beneficiaries);
        $rows = $this->labeledSummaries(
            $report->beneficiarySummaries($filters),
            $beneficiaries,
        );

        return response()->streamDownload(
            static function () use ($rows): void {
                $stream = fopen('php://output', 'w');

                if ($stream === false) {
                    return;
                }

                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, [
                    'currency',
                    'beneficiary',
                    'allocated_minor',
                    'reversed_minor',
                    'net_minor',
                    'events',
                ], ',', '"', '');

                foreach ($rows as $row) {
                    fputcsv($stream, [
                        $row['currency'],
                        self::safeCsvText($row['beneficiary_label']),
                        $row['allocated_minor'],
                        $row['reversed_minor'],
                        $row['net_minor'],
                        $row['events'],
                    ], ',', '"', '');
                }

                fclose($stream);
            },
            'grindflow-finance-reconciliation.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @param  Collection<int, User>  $beneficiaries
     * @return array<string, string>
     */
    private function validatedFilters(Request $request, Collection $beneficiaries): array
    {
        $currency = $request->query('currency');
        if (is_string($currency)) {
            $request->merge(['currency' => strtoupper(trim($currency))]);
        }

        $filters = $request->validate([
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'beneficiary' => [
                'nullable',
                'string',
                Rule::in($beneficiaries->pluck('id')->prepend('unassigned')->all()),
            ],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        unset($filters['page']);

        return $filters;
    }

    /**
     * @param  Collection<int, array{beneficiary_user_id: ?string, currency: string, events: int, allocated_minor: int, reversed_minor: int, net_minor: int}>  $rows
     * @param  Collection<int, User>  $beneficiaries
     * @return Collection<int, array{beneficiary_user_id: ?string, currency: string, events: int, allocated_minor: int, reversed_minor: int, net_minor: int, beneficiary_label: string}>
     */
    private function labeledSummaries(Collection $rows, Collection $beneficiaries): Collection
    {
        $names = $beneficiaries->pluck('name', 'id');

        return $rows->map(static function (array $row) use ($names): array {
            $id = $row['beneficiary_user_id'];
            $row['beneficiary_label'] = $id === null
                ? 'Organization / unassigned'
                : (string) ($names->get($id) ?? 'Former beneficiary');

            return $row;
        });
    }

    private static function safeCsvText(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+@-]/', $value) === 1
            ? "'".$value
            : $value;
    }

    public function store(
        StoreRevenueAllocationRequest $request,
        FinanceLedgerManager $manager,
    ): RedirectResponse {
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $manager->create(
            $user,
            (string) $validated['source_label'],
            (int) $validated['amount_minor'],
            (string) $validated['currency'],
            CarbonImmutable::parse(
                (string) $validated['occurred_on'],
                'UTC',
            ),
            isset($validated['beneficiary_user_id'])
                ? (string) $validated['beneficiary_user_id']
                : null,
            isset($validated['note'])
                ? (string) $validated['note']
                : null,
        );

        return redirect()
            ->route('organizations.finance.index', [
                'organizationId' => $this->organization($request)->getKey(),
            ])
            ->with('status', 'Revenue allocation recorded.');
    }

    public function reverse(
        ReverseRevenueAllocationRequest $request,
        FinanceLedgerManager $manager,
        string $allocationId,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $manager->reverse(
            $user,
            $allocationId,
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('organizations.finance.index', [
                'organizationId' => $this->organization($request)->getKey(),
            ])
            ->with('status', 'Revenue allocation reversed.');
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('tenantOrganization');

        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }
}
