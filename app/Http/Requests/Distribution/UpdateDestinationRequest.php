<?php

namespace App\Http\Requests\Distribution;

use App\Models\Organization;
use App\Models\PublishingDestination;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $this->attributes->get('tenantOrganization');

        return $user instanceof User
            && $organization instanceof Organization
            && $user->canManageOrganization($organization);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in([
                PublishingDestination::STATUS_ACTIVE,
                PublishingDestination::STATUS_DISABLED,
            ])],
        ];
    }
}
