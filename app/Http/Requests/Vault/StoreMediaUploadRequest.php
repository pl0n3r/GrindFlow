<?php

namespace App\Http\Requests\Vault;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreMediaUploadRequest extends FormRequest
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
        /** @var array<int, string> $mimetypes */
        $mimetypes = config('grindflow.media.allowed_mimetypes', []);

        return [
            'media' => [
                'required',
                'file',
                'mimetypes:'.implode(',', $mimetypes),
                'max:'.(int) config('grindflow.media.max_upload_kb', 512000),
            ],
        ];
    }
}
