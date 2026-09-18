<?php

namespace App\Services\Media\Connections;

use App\Models\User;
use Illuminate\Http\Request;

class OAuthPendingState
{
    public function issue(
        Request $request,
        string $provider,
        User $user,
        string $organizationId,
    ): string {
        $state = bin2hex(random_bytes(32));

        $request->session()->put($this->sessionKey($provider), [
            'state_hash' => hash('sha256', $state),
            'organization_id' => $organizationId,
            'user_id' => (string) $user->getKey(),
            'issued_at' => now()->getTimestamp(),
        ]);

        return $state;
    }

    public function forget(Request $request, string $provider): void
    {
        $request->session()->forget($this->sessionKey($provider));
    }

    public function consume(
        Request $request,
        string $provider,
        User $user,
        int $ttlSeconds,
    ): ?string {
        $state = $request->query('state');
        $pending = $request->session()->get($this->sessionKey($provider));

        if (
            is_string($state) === false
            || $state === ''
            || is_array($pending) === false
        ) {
            return null;
        }

        $stateHash = $pending['state_hash'] ?? null;
        $organizationId = $pending['organization_id'] ?? null;
        $userId = $pending['user_id'] ?? null;
        $issuedAt = $pending['issued_at'] ?? null;

        if (
            is_string($stateHash) === false
            || is_string($organizationId) === false
            || $organizationId === ''
            || is_string($userId) === false
            || is_int($issuedAt) === false
            || hash_equals($userId, (string) $user->getKey()) === false
        ) {
            return null;
        }

        $ttl = max(60, min($ttlSeconds, 1800));
        $now = now()->getTimestamp();

        if (
            $issuedAt < ($now - $ttl)
            || $issuedAt > ($now + 60)
            || hash_equals($stateHash, hash('sha256', $state)) === false
        ) {
            return null;
        }

        $this->forget($request, $provider);

        return $organizationId;
    }

    private function sessionKey(string $provider): string
    {
        return 'oauth.'.$provider.'.pending';
    }
}
