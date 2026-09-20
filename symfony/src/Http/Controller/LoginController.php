<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    #[Route('/login', name: 'grindflow_login', methods: ['GET', 'POST'])]
    public function __invoke(AuthenticationUtils $auth, ProductVersion $version): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('grindflow_organizations');
        }

        return $this->render('identity/login.html.twig', [
            'app_version' => $version->human(),
            'last_email' => $auth->getLastUsername(),
            'login_error' => $auth->getLastAuthenticationError(),
        ])->setPrivate();
    }

    #[Route('/logout', name: 'grindflow_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Symfony Security controla el cierre de sesión.');
    }
}
