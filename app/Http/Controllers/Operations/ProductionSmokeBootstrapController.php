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
        } catch (Throwable) {
            abort(403);
        }

        try {
            $environment->withSmokePassword(
                $password,
                static function () use ($password): void {
                    if (Artisan::call('config:clear') !== 0) {
                        throw new RuntimeException('Configuration cache could not be invalidated.');
                    }

                    config(['grindflow.smoke_user.password' => $password]);

                    if (Artisan::call('grindflow:provision-smoke-user') !== 0) {
                        throw new RuntimeException('Synthetic smoke identity reconciliation failed.');
                    }
                },
            );
        } catch (Throwable $exception) {
            Log::error('Production smoke bootstrap reconciliation failed.', [
                'stage' => 'synthetic_reconciliation',
                'exception_class' => get_class($exception),
            ]);

            return response('', 503)
                ->header('Cache-Control', 'no-store, max-age=0')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        return response('', 204)
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
