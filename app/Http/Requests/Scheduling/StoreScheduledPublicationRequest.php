<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

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
            'destination_id' => ['required', 'uuid'],
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
}
