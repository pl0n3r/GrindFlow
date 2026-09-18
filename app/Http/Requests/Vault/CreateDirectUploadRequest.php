<?php

namespace App\Http\Requests\Vault;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDirectUploadRequest extends FormRequest
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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var array<int, string> $mimetypes */
        $mimetypes = config('grindflow.media.allowed_mimetypes', []);

        $configuredMax = (int) config(
            'grindflow.media.direct_upload_max_bytes',
            2_147_483_648,
        );
        $maxBytes = max(1, min($configuredMax, 2_147_483_648));

        return [
            'filename' => ['required', 'string', 'max:512'],
            'mime_type' => [
                'required',
                'string',
                'max:191',
                Rule::in($mimetypes),
            ],
            'byte_size' => [
                'required',
                'integer',
                'min:1',
                'max:'.$maxBytes,
            ],
        ];
    }
}
