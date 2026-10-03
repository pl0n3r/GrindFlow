<?php

declare(strict_types=1);

namespace App\Http\Requests\Vault;

use Illuminate\Foundation\Http\FormRequest;

class GuestMobileUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'grant' => ['required', 'string', 'max:4096'],
            'profile_id' => ['nullable', 'uuid'],
            'media' => ['required', 'array', 'min:1', 'max:25'],
            'media.*' => ['required', 'file'],
        ];
    }
}
