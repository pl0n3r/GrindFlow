<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'grindflow_health', methods: ['GET'])]
    public function __invoke(ProductVersion $version): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'version' => $version->human(),
            'stage' => 's0-preview',
        ], 200, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
