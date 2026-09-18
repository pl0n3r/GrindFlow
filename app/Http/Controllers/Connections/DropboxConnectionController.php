<?php

namespace App\Http\Controllers\Connections;

use App\Http\Controllers\Controller;
use App\Models\MediaConnection;
use App\Models\User;
use App\Services\Media\Connections\DropboxOAuthClient;
use App\Services\Media\Connections\MediaConnectionManager;
use App\Services\Media\Connections\OAuthConnectionCoordinator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DropboxConnectionController extends Controller
{
    public function __construct(
        private readonly OAuthConnectionCoordinator $oauthFlow,
        private readonly DropboxOAuthClient $oauth,
        private readonly MediaConnectionManager $connections,
        private readonly TenantContext $tenantContext,
    ) {}

    public function authorize(
        Request $request,
        string $organizationId,
    ): RedirectResponse {
        return $this->oauthFlow->authorize(
            $request,
            $organizationId,
            MediaConnection::PROVIDER_DROPBOX,
            fn (string $state): string => $this->oauth->authorizationUrl(
                route('connections.dropbox.callback'),
                $state,
            ),
            'Dropbox OAuth no esta configurado en este entorno.',
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        return $this->oauthFlow->callback(
            $request,
            MediaConnection::PROVIDER_DROPBOX,
            (int) config(
                'grindflow.connectors.dropbox.oauth_state_ttl_seconds',
                600,
            ),
            function (User $user, string $organizationId, string $code): void {
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
            },
            'Dropbox',
        );
    }
}
