<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Http\BoundedJsonBody;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Infrastructure\Privacy\DataSubjectRequests;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Self-service identity requests only. No tenant exports or live deletions. */
final class DataSubjectRequestsController extends AbstractController
{
    private const CSRF_ID = 'grindflow_data_subject_requests';

    public function __construct(
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    #[Route('/api/admin/privacy/data-subject-requests/csrf', name: 'grindflow_dsr_csrf', methods: ['GET'])]
    public function csrf(Request $request, CsrfTokenManagerInterface $tokens): JsonResponse
    {
        if (!$this->enabled()) {
            return $this->error(404, 'feature_unavailable');
        }
        if (!$this->authenticatedUser() instanceof IdentityUser) {
            return $this->error(401, 'authentication_required');
        }

        return $this->privateJson(['data' => ['csrf_token' => $tokens->getToken(self::CSRF_ID)->getValue()]]);
    }

    #[Route('/api/admin/privacy/data-subject-requests/export', name: 'grindflow_dsr_export', methods: ['POST'])]
    public function export(Request $request, DataSubjectRequests $requests): Response
    {
        if (!$this->enabled()) {
            return $this->error(404, 'feature_unavailable');
        }
        $actor = $this->authenticatedUser();
        if ($actor === null) {
            return $this->error(401, 'authentication_required');
        }
        if (!$this->validCsrf($request)) {
            return $this->error(403, 'invalid_csrf');
        }
        if (!$this->validBody($request)) {
            return $this->error(422, 'invalid_request');
        }

        $record = $requests->exportOwn($actor->id());
        if ($record === null) {
            return $this->error(403, 'account_access_changed');
        }

        $response = new Response(
            json_encode([
                'data' => ['user' => $record],
                'meta' => ['scope' => 'identity_only', 'complete' => false],
            ], JSON_THROW_ON_ERROR),
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="grindflow-identity-partial.json"',
            ],
        );
        $this->privateHeaders($response);

        return $response;
    }

    #[Route('/api/admin/privacy/data-subject-requests/deletion', name: 'grindflow_dsr_deletion', methods: ['POST'])]
    public function deletion(Request $request, DataSubjectRequests $requests): JsonResponse
    {
        if (!$this->enabled()) {
            return $this->error(404, 'feature_unavailable');
        }
        $actor = $this->authenticatedUser();
        if ($actor === null) {
            return $this->error(401, 'authentication_required');
        }
        if (!$this->validCsrf($request)) {
            return $this->error(403, 'invalid_csrf');
        }
        if (!$this->validBody($request)) {
            return $this->error(422, 'invalid_request');
        }
        if ($requests->requestDeletion($actor->id()) === 'revoked') {
            return $this->error(403, 'account_access_changed');
        }

        return $this->privateJson(['data' => ['status' => 'pending', 'deleted' => false]], 202);
    }

    private function enabled(): bool
    {
        // Pre-live synthetic test only. No production export or deletion routes.
        return $this->environment === 'test';
    }

    private function authenticatedUser(): ?IdentityUser
    {
        $user = $this->getUser();

        return $user instanceof IdentityUser && $user->isActive() ? $user : null;
    }

    private function validCsrf(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token', ''));
    }

    private function validBody(Request $request): bool
    {
        // Explicitly require JSON: otherwise text/plain or form submissions
        // could satisfy the same mutation contract with a JSON-looking body.
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''), 2)[0]));
        if ($mediaType !== 'application/json') {
            return false;
        }

        $body = BoundedJsonBody::decode($request);

        return $body === ['confirm' => true]
            && $request->request->all() === []
            && $request->files->all() === [];
    }

    private function error(int $status, string $code): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $code]], $status);
    }

    /** @param array<string, mixed> $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $this->privateHeaders($response);

        return $response;
    }

    private function privateHeaders(Response $response): void
    {
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
    }
}
