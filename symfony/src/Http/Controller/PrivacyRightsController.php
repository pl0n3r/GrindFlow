<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrivacyRightsController extends AbstractController
{
    #[Route('/privacidad-y-mis-derechos', name: 'grindflow_privacy_rights', methods: ['GET'])]
    public function __invoke(ProductVersion $version): Response
    {
        // Symfony Dotenv populates $_SERVER/$_ENV; no contact is inferred.
        $configured = $_SERVER['GRINDFLOW_PRIVACY_CONTACT_EMAIL']
            ?? $_ENV['GRINDFLOW_PRIVACY_CONTACT_EMAIL']
            ?? getenv('GRINDFLOW_PRIVACY_CONTACT_EMAIL');
        $email = is_string($configured) ? trim($configured) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        return $this->render('privacidad/derechos.html.twig', [
            'app_version' => $version->human(),
            'contact_email' => $email,
        ], new Response('', Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }
}
