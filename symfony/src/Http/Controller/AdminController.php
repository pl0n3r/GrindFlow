<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AdminController
{
    #[Route('/admin', name: 'grindflow_admin', methods: ['GET'])]
    public function __invoke(): Response
    {
        // S1 connects real authenticated sessions/tenants. No public admin shortcut.
        throw new AccessDeniedHttpException('Acceso administrativo todavía no habilitado en Symfony.');
    }
}
