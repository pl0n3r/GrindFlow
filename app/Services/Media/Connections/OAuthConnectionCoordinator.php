<?php

namespace App\Services\Media\Connections;

use App\Models\User;
use App\Services\Media\Connectors\MediaConnectorException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OAuthConnectionCoordinator
{
    public function __construct(
        private readonly OAuthPendingState $pendingState,
    ) {}

    /**
     * @param  Closure(string): string  $authorizationUrl
     */
    public function authorize(
        Request $request,
        string $organizationId,
        string $provider,
        Closure $authorizationUrl,
        string $notConfiguredMessage,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->canManageOrganization($organizationId), 403);

        if (Schema::hasTable('media_connections') === false) {
            return $this->vaultError(
                $organizationId,
                'Las conexiones cloud aun requieren la migracion de Media Connections.',
                $provider,
            );
        }

        $state = $this->pendingState->issue(
            $request,
            $provider,
            $user,
            $organizationId,
        );

        try {
            $url = $authorizationUrl($state);
        } catch (MediaConnectorException) {
            $this->pendingState->forget($request, $provider);

            return $this->vaultError(
                $organizationId,
                $notConfiguredMessage,
                $provider,
            );
        }

        return redirect()->away($url);
    }

    /**
     * @param  Closure(User, string, string): void  $connect
     */
    public function callback(
        Request $request,
        string $provider,
        int $stateTtlSeconds,
        Closure $connect,
        string $providerName,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $organizationId = $this->pendingState->consume(
            $request,
            $provider,
            $user,
            $stateTtlSeconds,
        );

        abort_unless($organizationId !== null, 419);
        abort_unless($user->canManageOrganization($organizationId), 403);

        $providerError = $request->query('error');

        if (is_string($providerError) && $providerError !== '') {
            return $this->vaultError(
                $organizationId,
                'La autorizacion de '.$providerName.' no se completo.',
                $provider,
            );
        }

        $code = $request->query('code');

        if (is_string($code) === false || $code === '') {
            return $this->vaultError(
                $organizationId,
                $providerName.' no devolvio un codigo de autorizacion valido.',
                $provider,
            );
        }

        try {
            $connect($user, $organizationId, $code);
        } catch (MediaConnectorException) {
            return $this->vaultError(
                $organizationId,
                'No fue posible completar la conexion segura con '.$providerName.'.',
                $provider,
            );
        }

        return redirect()
            ->route('organizations.vault.index', [
                'organizationId' => $organizationId,
            ])
            ->with('status', $providerName.' conectado correctamente.');
    }

    private function vaultError(
        string $organizationId,
        string $message,
        string $provider,
    ): RedirectResponse {
        return redirect()
            ->route('organizations.vault.index', [
                'organizationId' => $organizationId,
            ])
            ->withErrors([$provider => $message]);
    }
}
