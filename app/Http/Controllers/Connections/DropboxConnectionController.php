<?php

namespace App\Http\Controllers\Connections;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Media\Connections\DropboxOAuthClient;
use App\Services\Media\Connections\MediaConnectionManager;
use App\Services\Media\Connectors\MediaConnectorException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DropboxConnectionController extends Controller
{
    private const SESSION_KEY = 'oauth.dropbox.pending';

    public function __construct(
        private readonly DropboxOAuthClient $oauth,
        private readonly MediaConnectionManager $connections,
        private readonly TenantContext $tenantContext,
    ) {}

    public function authorize(Request $request, string $organizationId): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->canManageOrganization($organizationId), 403);

        if (Schema::hasTable('media_connections') === false) {
            return $this->vaultError(
                $organizationId,
                'Las conexiones cloud aun requieren la migracion de Media Connections.',
            );
        }

        $state = bin2hex(random_bytes(32));

        $request->session()->put(self::SESSION_KEY, [
            'state_hash' => hash('sha256', $state),
            'organization_id' => $organizationId,
            'user_id' => (string) $user->getKey(),
            'issued_at' => now()->getTimestamp(),
        ]);

        try {
            $authorizationUrl = $this->oauth->authorizationUrl(
                route('connections.dropbox.callback'),
                $state,
            );
        } catch (MediaConnectorException) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->vaultError(
                $organizationId,
                'Dropbox OAuth no esta configurado en este entorno.',
            );
        }

        return redirect()->away($authorizationUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $state = $request->query('state');
        $pending = $request->session()->get(self::SESSION_KEY);

        abort_unless(
            is_string($state)
                && $state !== ''
                && $this->validPendingState($pending, $state, $user),
            419,
        );

        /** @var array{organization_id: string} $pending */
        $organizationId = $pending['organization_id'];

        $request->session()->forget(self::SESSION_KEY);

        abort_unless($user->canManageOrganization($organizationId), 403);

        $providerError = $request->query('error');

        if (is_string($providerError) && $providerError !== '') {
            return $this->vaultError(
                $organizationId,
                'La autorizacion de Dropbox no se completo.',
            );
        }

        $code = $request->query('code');

        if (is_string($code) === false || $code === '') {
            return $this->vaultError(
                $organizationId,
                'Dropbox no devolvio un codigo de autorizacion valido.',
            );
        }

        try {
            $tokens = $this->oauth->exchangeAuthorizationCode(
                $code,
                route('connections.dropbox.callback'),
            );

            $this->tenantContext->runWithinOrganization(
                $user,
                $organizationId,
                fn () => $this->connections->connectDropbox(
                    $user,
                    $tokens->accessToken,
                    'Dropbox',
                    null,
                    null,
                    $tokens->refreshToken,
                    now()->addSeconds($tokens->expiresInSeconds),
                    $tokens->scopes,
                    $tokens->accountIdentifier,
                ),
            );
        } catch (MediaConnectorException) {
            return $this->vaultError(
                $organizationId,
                'No fue posible completar la conexion segura con Dropbox.',
            );
        }

        return redirect()
            ->route('organizations.vault.index', [
                'organizationId' => $organizationId,
            ])
            ->with('status', 'Dropbox conectado correctamente.');
    }

    private function validPendingState(
        mixed $pending,
        string $state,
        User $user,
    ): bool {
        if (is_array($pending) === false) {
            return false;
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
        ) {
            return false;
        }

        if (hash_equals($userId, (string) $user->getKey()) === false) {
            return false;
        }

        $ttl = max(60, min(
            (int) config(
                'grindflow.connectors.dropbox.oauth_state_ttl_seconds',
                600,
            ),
            1800,
        ));
        $now = now()->getTimestamp();

        if ($issuedAt < ($now - $ttl) || $issuedAt > ($now + 60)) {
            return false;
        }

        return hash_equals($stateHash, hash('sha256', $state));
    }

    private function vaultError(
        string $organizationId,
        string $message,
    ): RedirectResponse {
        return redirect()
            ->route('organizations.vault.index', [
                'organizationId' => $organizationId,
            ])
            ->withErrors(['dropbox' => $message]);
    }
}
