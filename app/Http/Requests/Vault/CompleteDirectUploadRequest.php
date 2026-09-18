<?php

namespace App\Http\Requests\Vault;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class CompleteDirectUploadRequest extends FormRequest
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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'upload_token' => ['required', 'string', 'max:8192'],
        ];
    }
}
