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

    /**
     * Editing metadata or its redirect destination must preserve the public
     * token, active/disabled state, scheduler assignments and click history.
     * Historical CSV labels/tags show current metadata, not old snapshots.
     */
    public function updateDetails(
        User $actor,
        string $linkId,
        string $label,
        string $destinationUrl,
        ?string $channel,
        ?string $campaign,
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

        $label = trim($label);
        $destinationUrl = trim($destinationUrl);

        if ($label === '' || mb_strlen($label) > 191) {
            throw new InvalidArgumentException('A valid link label is required.');
        }

        if (
            mb_strlen($destinationUrl) > 2048
            || filter_var($destinationUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(
                strtolower((string) parse_url($destinationUrl, PHP_URL_SCHEME)),
                ['http', 'https'],
                true,
            )
        ) {
            throw new InvalidArgumentException('The destination must be a valid HTTP(S) URL.');
        }

        $channel = $this->nullableTrim($channel);
        $campaign = $this->nullableTrim($campaign);

        if (
            ($channel !== null && mb_strlen($channel) > 64)
            || ($campaign !== null && mb_strlen($campaign) > 128)
        ) {
            throw new InvalidArgumentException('Link metadata exceeds its maximum length.');
        }

        DB::transaction(static function () use (
            $linkId,
            $label,
            $destinationUrl,
            $channel,
            $campaign,
        ): void {
            $link = TrackedLink::query()
                ->lockForUpdate()
                ->findOrFail($linkId);

            $link->forceFill([
                'label' => $label,
                'destination_url' => $destinationUrl,
                'channel' => $channel,
                'campaign' => $campaign,
            ])->save();
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
