<?php

namespace App\Http\Requests\Finance;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ReverseRevenueAllocationRequest extends FormRequest
{
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
