<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReverseRevenueAllocationRequest;
use App\Http\Requests\Finance\StoreRevenueAllocationRequest;
use App\Models\Organization;
use App\Models\RevenueAllocation;
use App\Models\User;
use App\Services\Finance\FinanceLedgerManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->canManageFinanceOrganization($organization),
            403,
        );

        $financeReady = Schema::hasTable('revenue_allocations');
        $allocations = collect();
        $beneficiaries = collect();
        $allocatedMinor = 0;
        $reversedMinor = 0;

        if ($financeReady) {
            $allocations = RevenueAllocation::query()
                ->with(['beneficiary', 'createdBy', 'reversalOf'])
                ->orderByDesc('occurred_on')
                ->latest()
                ->limit(100)
                ->get();

            $beneficiaries = $organization
                ->users()
                ->orderBy('name')
                ->get();

            $allocatedMinor = (int) RevenueAllocation::query()
                ->whereNull('reversal_of_id')
                ->sum('amount_minor');

            $reversedMinor = (int) RevenueAllocation::query()
                ->whereNotNull('reversal_of_id')
                ->sum('amount_minor');
        }

        return view('finance.index', [
            'organization' => $organization,
            'financeReady' => $financeReady,
            'allocations' => $allocations,
            'beneficiaries' => $beneficiaries,
            'allocatedMinor' => $allocatedMinor,
            'reversedMinor' => $reversedMinor,
            'netMinor' => $allocatedMinor - $reversedMinor,
        ]);
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
