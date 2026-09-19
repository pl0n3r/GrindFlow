<?php

namespace App\Services\Traffic;

use App\Models\TrackedLink;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class TrackedLinkManager
{
    private const TOKEN_LENGTH = 22;

    private const TOKEN_ATTEMPTS = 8;

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function create(
        User $actor,
        string $label,
        string $destinationUrl,
        ?string $channel,
        ?string $campaign,
    ): TrackedLink {
        $organizationId = $this->tenantContext->organizationId();

        if (
            $organizationId === null
            || $actor->canManageTrafficOrganization(
                $organizationId,
            ) === false
        ) {
            throw new AuthorizationException(
                'The user cannot manage traffic for this organization.',
            );
        }

        return TrackedLink::query()->create([
            'created_by_user_id' => $actor->getKey(),
            'token' => $this->uniqueToken(),
            'label' => trim($label),
            'destination_url' => trim($destinationUrl),
            'channel' => $this->nullableTrim($channel),
            'campaign' => $this->nullableTrim($campaign),
            'status' => TrackedLink::STATUS_ACTIVE,
        ]);
    }

    /**
     * A reversible pause/resume never rotates the public token or deletes
     * historical aggregates. Scoped lookup must not reveal foreign link IDs.
     */
    public function setStatus(
        User $actor,
        string $linkId,
        string $status,
    ): void {
        $organizationId = $this->tenantContext->organizationId();

        if (
            $organizationId === null
            || $actor->canManageTrafficOrganization($organizationId) === false
        ) {
            throw new AuthorizationException(
                'The user cannot manage traffic for this organization.',
            );
        }

        if (
            in_array(
                $status,
                [
                    TrackedLink::STATUS_ACTIVE,
                    TrackedLink::STATUS_DISABLED,
                ],
                true,
            ) === false
        ) {
            throw new InvalidArgumentException('Unsupported tracked-link status.');
        }

        DB::transaction(static function () use ($linkId, $status): void {
            $link = TrackedLink::query()
                ->lockForUpdate()
                ->findOrFail($linkId);

            if ($link->status === $status) {
                return;
            }

            $link->forceFill(['status' => $status])->save();
        });
    }

    private function uniqueToken(): string
    {
        for ($attempt = 0; $attempt < self::TOKEN_ATTEMPTS; $attempt++) {
            $token = Str::random(self::TOKEN_LENGTH);

            if (
                TrackedLink::query()
                    ->withoutGlobalScopes()
                    ->where('token', $token)
                    ->doesntExist()
            ) {
                return $token;
            }
        }

        throw new RuntimeException(
            'Unable to allocate a unique tracked-link token.',
        );
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
