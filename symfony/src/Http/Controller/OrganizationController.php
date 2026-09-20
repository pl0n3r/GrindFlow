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

final class OrganizationController extends AbstractController
{
    #[Route('/organizations', name: 'grindflow_organizations', methods: ['GET'])]
    public function list(Connection $db, Request $request, ProductVersion $version): Response
    {
        $user = $this->identity();

        // An account's platform role does not grant any tenant membership.
        $organizations = $db->fetchAllAssociative(
            <<<'SQL'
                SELECT organization.id, organization.name, membership.role
                FROM gf_identity_memberships membership
                INNER JOIN gf_identity_organizations organization
                    ON organization.id = membership.organization_id
                WHERE membership.user_id = :user
                ORDER BY organization.name, organization.id
                SQL,
            ['user' => $user->id()],
        );

        $selected = $request->getSession()->get('grindflow_organization_id');
        if ($selected !== null && !in_array($selected, array_column($organizations, 'id'), true)) {
            $request->getSession()->remove('grindflow_organization_id');
            $selected = null;
        }

        $response = $this->render('identity/organizations.html.twig', [
            'app_version' => $version->human(),
            'organizations' => $organizations,
            'selected_id' => $selected,
            'display_name' => $user->displayName(),
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/organizations/select', name: 'grindflow_organization_select', methods: ['POST'])]
    public function select(Connection $db, Request $request): Response
    {
        $user = $this->identity();
        if (!$this->isCsrfTokenValid('select_organization', (string) $request->request->get('_csrf_token', ''))) {
            throw new AccessDeniedHttpException('Solicitud de selección inválida.');
        }

        $id = (string) $request->request->get('organization_id', '');
        $membership = $db->fetchOne(
            'SELECT id FROM gf_identity_memberships WHERE user_id = :user AND organization_id = :organization',
            ['user' => $user->id(), 'organization' => $id],
        );
        if ($membership === false) {
            throw new AccessDeniedHttpException('No tienes acceso a esa organización.');
        }

        $request->getSession()->set('grindflow_organization_id', $id);

        return $this->redirectToRoute('grindflow_admin');
    }

    private function identity(): IdentityUser
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }
}
