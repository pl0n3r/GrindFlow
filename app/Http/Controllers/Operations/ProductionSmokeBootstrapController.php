<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Support\Deployment\CheckoutIdentity;
use App\Support\Deployment\GitHubActionsOidcVerifier;
use App\Support\Deployment\ProductionEnvironmentWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProductionSmokeBootstrapController extends Controller
{
    public function __invoke(
        Request $request,
        GitHubActionsOidcVerifier $verifier,
        ProductionEnvironmentWriter $environment,
        CheckoutIdentity $identity,
    ): Response {
        if (
            ! app()->environment('production')
            || config('grindflow.phase') !== 'construccion'
        ) {
            abort(404);
        }

        $expectedSha = trim((string) $request->header('X-GrindFlow-Expected-Sha'));
        $token = (string) $request->bearerToken();
        $password = $request->json('password');

        if (
            $token === ''
            || preg_match('/^[0-9a-f]{40}$/', $expectedSha) !== 1
            || ! is_string($password)
            || $password === ''
        ) {
            abort(403);
        }

        if (! hash_equals($expectedSha, (string) $identity->commit())) {
            abort(409);
        }

        try {
            $verifier->verify($token, $expectedSha);
        } catch (Throwable $exception) {
            // Only fixed context and class: exception messages can contain secrets.
            Log::warning('Production smoke bootstrap OIDC verification failed.', [
                'stage' => 'oidc_verification',
                'exception_class' => $exception::class,
            ]);

            abort(403);
        }

        $failureStage = 'environment';

        try {
            $environment->withSmokePassword(
                $password,
                static function () use ($password, &$failureStage): void {
                    $failureStage = 'config-clear';

                    if (Artisan::call('config:clear') !== 0) {
                        throw new RuntimeException('Configuration cache could not be invalidated.');
                    }

                    config([
                        'grindflow.smoke_user.password' => $password,
                        'grindflow.smoke_user.email' => ProductionEnvironmentWriter::DEDICATED_SMOKE_EMAIL,
                    ]);

                    $failureStage = 'provision-user';
                    config(['grindflow.smoke_provision_failure_code' => null]);

                    if (Artisan::call('grindflow:provision-smoke-user') !== 0) {
                        throw new RuntimeException('Synthetic smoke identity reconciliation failed.');
                    }
                },
            );
        } catch (Throwable $exception) {
            // Only allowlisted fixed codes reach the signed workflow; never return exception text.
            $failureCode = match ($exception->getMessage()) {
                'Synthetic smoke password format is invalid.' => 'password-invalid',
                'Production environment file is unavailable.' => 'env-unavailable',
                'Unable to open production environment lock.' => 'lock-unavailable',
                'Unable to lock production environment.' => 'lock-failed',
                'Unable to read production environment.' => 'env-read-failed',
                'Unable to write production environment backup.' => 'backup-write-failed',
                'Unable to secure production environment backup.' => 'backup-permission-failed',
                'Unable to stage production environment update.' => 'stage-unavailable',
                'Unable to write staged production environment.' => 'stage-write-failed',
                'Unable to secure staged production environment.' => 'stage-permission-failed',
                'Unable to publish production environment update.' => 'env-publish-failed',
                'Configuration cache could not be invalidated.' => 'config-clear-failed',
                'Synthetic smoke identity reconciliation failed.' => 'provision-failed',
                default => 'unexpected',
            };

            if ($failureStage === 'provision-user' && $failureCode === 'provision-failed') {
                // Treat the command's in-process diagnostic as untrusted until allowlisted.
                $provisionCode = (string) config('grindflow.smoke_provision_failure_code', '');
                $failureCode = in_array($provisionCode, [
                    'provision-phase-disabled',
                    'provision-password-missing',
                    'provision-email-invalid',
                    'provision-name-invalid',
                    'provision-lock-directory-failed',
                    'provision-lock-open-failed',
                    'provision-lock-timeout',
                    'provision-membership-conflict',
                    'provision-backup-write-failed',
                    'provision-backup-permission-failed',
                    'provision-database-failed',
                ], true) ? $provisionCode : 'provision-failed';
            }

            Log::error('Production smoke bootstrap reconciliation failed.', [
                'stage' => $failureStage,
                'code' => $failureCode,
                'exception_class' => $exception::class,
            ]);

            return response('', 503)
                ->header('Cache-Control', 'no-store, max-age=0')
                ->header('X-Content-Type-Options', 'nosniff')
                ->header('X-GrindFlow-Smoke-Failure-Stage', $failureStage)
                ->header('X-GrindFlow-Smoke-Failure-Code', $failureCode);
        }

        return response('', 204)
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
