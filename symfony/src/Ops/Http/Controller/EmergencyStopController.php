<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Http\Controller;

use GrindFlow\Ops\Routing\StaffOpsRouteLoader;
use GrindFlow\Ops\Security\EmergencyStopStore;
use GrindFlow\Ops\Security\StaffOpsAuthException;
use GrindFlow\Ops\Security\StaffOpsRequestAuthenticator;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Decorates the existing ops loader: no new public/unauthenticated route imports.
 * The administrative caller must pass the existing TLS + IP + HMAC + nonce gate.
 */
final class EmergencyStopController extends Loader
{
    public function __construct(
        private readonly StaffOpsRouteLoader $inner,
        private readonly EmergencyStopStore $store,
        private readonly StaffOpsRequestAuthenticator $authenticator,
    ) {}

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $this->inner->supports($resource, $type);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = $this->inner->load($resource, $type);
        if (!$this->authenticator->enabled()) {
            return $routes;
        }
        foreach (['stop' => 'stop', 'resume' => 'resume'] as $suffix => $method) {
            $route = new Route(
                '/ops/emergency/'.$suffix,
                ['_controller' => self::class.'::'.$method]
            );
            $route->setMethods(['POST']);
            $routes->add('grindflow_ops_emergency_'.$suffix, $route);
        }
        return $routes;
    }

    public function stop(Request $request): JsonResponse
    {
        try {
            $actor = $this->authenticator->authenticate($request);
            $reason = $this->reason($request);
            $revision = $this->store->stop($actor, $reason);
            // Failures are intentionally not recovered by switching to RUNNING.
            $this->store->finishRevocation();
            return new JsonResponse(['data' => ['state' => 'STOP', 'revision' => $revision]]);
        } catch (StaffOpsAuthException $exception) {
            return new JsonResponse(['error' => ['code' => $exception->errorCode]], $exception->status);
        } catch (\Throwable) {
            return new JsonResponse(['error' => ['code' => 'emergency_stop_unavailable']], 503);
        }
    }

    public function resume(Request $request): JsonResponse
    {
        try {
            $actor = $this->authenticator->authenticate($request);
            $revision = $this->store->resume($actor, $this->reason($request));
            return new JsonResponse(['data' => ['state' => 'RUNNING', 'revision' => $revision]]);
        } catch (StaffOpsAuthException $exception) {
            return new JsonResponse(['error' => ['code' => $exception->errorCode]], $exception->status);
        } catch (\Throwable) {
            return new JsonResponse(['error' => ['code' => 'emergency_resume_denied']], 409);
        }
    }

    private function reason(Request $request): string
    {
        try {
            $body = json_decode((string) $request->getContent(), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new StaffOpsAuthException(422, 'invalid_reason');
        }
        if (!is_array($body) || array_keys($body) !== ['reason_code']
            || !is_string($body['reason_code'])
            || preg_match('/\A[a-zA-Z0-9._:-]{1,120}\z/D', $body['reason_code']) !== 1) {
            throw new StaffOpsAuthException(422, 'invalid_reason');
        }
        return $body['reason_code'];
    }
}
