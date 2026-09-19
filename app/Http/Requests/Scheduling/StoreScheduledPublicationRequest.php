<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreScheduledPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $this->attributes->get('tenantOrganization');

        return $user instanceof User
            && $organization instanceof Organization
            && $user->canScheduleOrganization($organization);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'uuid'],
            'destination_ids' => ['required', 'array', 'min:1', 'max:20'],
            'destination_ids.*' => ['required', 'uuid', 'distinct'],
            'request_key' => ['required', 'string', 'size:36'],
            'tracked_link_id' => ['nullable', 'uuid'],
            'scheduled_for_local' => [
                'required',
                'date_format:Y-m-d\TH:i',
            ],
            'timezone' => [
                'required',
                'string',
                'max:64',
                'timezone',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('destination_ids') && $this->has('destination_id')) {
            $this->merge(['destination_ids' => [$this->input('destination_id')]]);
        }

        if (! $this->has('request_key')) {
            $this->merge(['request_key' => (string) Str::uuid()]);
        }
    }
}
