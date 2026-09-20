<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Http\AssetManifest;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    #[Route('/admin', name: 'grindflow_admin', methods: ['GET'])]
    public function __invoke(
        Request $request,
        MembershipContext $memberships,
        ProductVersion $version,
        AssetManifest $assets,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            throw new AccessDeniedHttpException();
        }

        $selectedId = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selectedId) || $selectedId === '') {
            return $this->redirectToRoute('grindflow_organizations');
        }

        $organization = $memberships->find($user->id(), $selectedId);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');

            throw new AccessDeniedHttpException('Tu acceso a esta organización ha cambiado.');
        }

        $response = $this->render('identity/admin.html.twig', [
            'app_version' => $version->human(),
            'organization' => $organization,
            'build' => $assets->preview(),
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
