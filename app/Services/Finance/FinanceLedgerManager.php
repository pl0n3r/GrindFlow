<?php

namespace App\Services\Finance;

use App\Models\Membership;
use App\Models\RevenueAllocation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceLedgerManager
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function create(
        User $actor,
        string $sourceLabel,
        int $amountMinor,
        string $currency,
        CarbonImmutable $occurredOn,
        ?string $beneficiaryUserId,
        ?string $note,
    ): RevenueAllocation {
        $organizationId = $this->requireTenant($actor);

        if ($amountMinor < 1) {
            throw ValidationException::withMessages([
                'amount_minor' => 'Amount must be greater than zero.',
            ]);
        }

        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ValidationException::withMessages([
                'currency' => 'Currency must contain exactly three letters.',
            ]);
        }

        $beneficiaryUserId = $this->validatedBeneficiary(
            $organizationId,
            $beneficiaryUserId,
        );

        return RevenueAllocation::query()->create([
            'created_by_user_id' => $actor->getKey(),
            'beneficiary_user_id' => $beneficiaryUserId,
            'source_label' => trim($sourceLabel),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'occurred_on' => $occurredOn->toDateString(),
            'note' => $this->nullableTrim($note),
        ]);
    }

    public function reverse(
        User $actor,
        string $allocationId,
        string $reason,
    ): RevenueAllocation {
        $this->requireTenant($actor);

        return DB::transaction(function () use (
            $actor,
            $allocationId,
            $reason,
        ): RevenueAllocation {
            $original = RevenueAllocation::query()
                ->lockForUpdate()
                ->findOrFail($allocationId);

            if ($original->reversal_of_id !== null) {
                throw ValidationException::withMessages([
                    'allocation' => 'A reversal cannot itself be reversed.',
                ]);
            }

            $alreadyReversed = RevenueAllocation::query()
                ->where('reversal_of_id', $original->getKey())
                ->exists();

            if ($alreadyReversed) {
                throw ValidationException::withMessages([
                    'allocation' => 'This allocation has already been reversed.',
                ]);
            }

            return RevenueAllocation::query()->create([
                'created_by_user_id' => $actor->getKey(),
                'beneficiary_user_id' => $original->beneficiary_user_id,
                'reversal_of_id' => $original->getKey(),
                'source_label' => $original->source_label,
                'amount_minor' => $original->amount_minor,
                'currency' => $original->currency,
                'occurred_on' => CarbonImmutable::now('UTC')->toDateString(),
                'note' => trim($reason),
            ]);
        });
    }

    private function requireTenant(User $actor): string
    {
        $organizationId = $this->tenantContext->organizationId();

        if (
            $organizationId === null
            || $actor->canManageFinanceOrganization($organizationId) === false
        ) {
            throw new AuthorizationException(
                'The user cannot manage finance for this organization.',
            );
        }

        return $organizationId;
    }

    private function validatedBeneficiary(
        string $organizationId,
        ?string $beneficiaryUserId,
    ): ?string {
        if ($beneficiaryUserId === null || trim($beneficiaryUserId) === '') {
            return null;
        }

        $exists = Membership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $beneficiaryUserId)
            ->exists();

        if ($exists === false) {
            throw ValidationException::withMessages([
                'beneficiary_user_id' => 'Beneficiary must belong to this organization.',
            ]);
        }

        return $beneficiaryUserId;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
