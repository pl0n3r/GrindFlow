<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Infrastructure\Runtime\RuntimeReadiness;
use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'grindflow_health', methods: ['GET'])]
    public function __invoke(ProductVersion $version, RuntimeReadiness $runtime): JsonResponse
    {
        $runtimeSummary = $runtime->publicSummary();
        $compatible = $runtimeSummary['compatible'];

        return new JsonResponse([
            'status' => $compatible ? 'ok' : 'degraded',
            'version' => $version->human(),
            'stage' => 's0-preview',
            'runtime' => $runtimeSummary,
        ], $compatible ? 200 : 503, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
