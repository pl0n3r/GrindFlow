<?php

namespace App\Http\Controllers\Connections;

use App\Http\Controllers\Controller;
use App\Models\MediaConnection;
use App\Models\User;
use App\Services\Media\Connections\GoogleOAuthClient;
use App\Services\Media\Connections\MediaConnectionManager;
use App\Services\Media\Connections\OAuthConnectionCoordinator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleDriveConnectionController extends Controller
{
    public function __construct(
        private readonly OAuthConnectionCoordinator $oauthFlow,
        private readonly GoogleOAuthClient $oauth,
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
            MediaConnection::PROVIDER_GOOGLE_DRIVE,
            fn (string $state): string => $this->oauth->authorizationUrl(
                route('connections.google-drive.callback'),
                $state,
            ),
            'Google Drive OAuth no esta configurado en este entorno.',
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        return $this->oauthFlow->callback(
            $request,
            MediaConnection::PROVIDER_GOOGLE_DRIVE,
            (int) config(
                'grindflow.connectors.google_drive.oauth_state_ttl_seconds',
                600,
            ),
            function (User $user, string $organizationId, string $code): void {
                $tokens = $this->oauth->exchangeAuthorizationCode(
                    $code,
                    route('connections.google-drive.callback'),
                );

                $this->tenantContext->runWithinOrganization(
                    $user,
                    $organizationId,
                    fn () => $this->connections->connectGoogleDrive(
                        $user,
                        $tokens->accessToken,
                        'Google Drive',
                        null,
                        null,
                        $tokens->refreshToken,
                        now()->addSeconds($tokens->expiresInSeconds),
                        $tokens->scopes,
                        $tokens->accountIdentifier,
                    ),
                );
            },
            'Google Drive',
        );
    }
}
