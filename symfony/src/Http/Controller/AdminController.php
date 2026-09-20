<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
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
    public function __invoke(Request $request, Connection $db, ProductVersion $version): Response
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            throw new AccessDeniedHttpException();
        }

        $selectedId = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selectedId) || $selectedId === '') {
            return $this->redirectToRoute('grindflow_organizations');
        }

        // Session selection is reauthorized on every request, not merely login.
        $organization = $db->fetchAssociative(
            <<<'SQL'
                SELECT organization.id, organization.name, membership.role
                FROM gf_identity_memberships membership
                INNER JOIN gf_identity_organizations organization
                    ON organization.id = membership.organization_id
                WHERE membership.user_id = :user AND membership.organization_id = :organization
                SQL,
            ['user' => $user->id(), 'organization' => $selectedId],
        );

        if ($organization === false) {
            $request->getSession()->remove('grindflow_organization_id');

            throw new AccessDeniedHttpException('Tu acceso a esta organización ha cambiado.');
        }

        return $this->render('identity/admin.html.twig', [
            'app_version' => $version->human(),
            'display_name' => $user->displayName(),
            'organization' => $organization,
        ])->setPrivate();
    }
}
