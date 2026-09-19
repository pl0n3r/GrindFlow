<?php

namespace App\Http\Requests\Distribution;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $this->attributes->get('tenantOrganization');

        return $user instanceof User
            && $organization instanceof Organization
            && $user->canManageOrganization($organization);
    }

    /**
     * @return array<string, list<string|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Validation\Rules\In>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', Rule::in(['sandbox'])],
        ];
    }
}
