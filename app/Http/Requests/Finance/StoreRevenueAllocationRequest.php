<?php

namespace App\Http\Requests\Finance;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreRevenueAllocationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge([
                'currency' => strtoupper(trim((string) $this->input('currency'))),
            ]);
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $this->attributes->get('tenantOrganization');

        return $user instanceof User
            && $organization instanceof Organization
            && $user->canManageFinanceOrganization($organization);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'source_label' => ['required', 'string', 'max:191'],
            'amount_minor' => [
                'required',
                'integer',
                'min:1',
                'max:9000000000000000',
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
            ],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'beneficiary_user_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
