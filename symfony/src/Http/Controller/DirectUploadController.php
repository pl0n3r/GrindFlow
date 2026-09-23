<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Http\BoundedJsonBody;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Infrastructure\Storage\DirectUploadCompletionVerifier;
use GrindFlow\Infrastructure\Storage\DirectUploadIntentIssuer;
use GrindFlow\Infrastructure\Storage\DirectUploadReadiness;
use GrindFlow\Infrastructure\Storage\DirectUploadTokenCipher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tenant-bound HTTP boundary for large-file transport, independent of the
 * local quick-upload endpoint. Completion verifies staging only: it never
 * claims to have promoted bytes or created a durable catalog asset.
 */
final class DirectUploadController extends AbstractController
{
    private const QUICK_MAX_BYTES = 8 * 1024 * 1024;
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'];

    public function __construct(
        private readonly DirectUploadReadiness $readiness,
        private readonly DirectUploadIntentIssuer $issuer,
        private readonly DirectUploadCompletionVerifier $verifier,
    ) {
    }

    #[Route('/api/admin/vault/direct-upload/intent', name: 'grindflow_vault_direct_intent', methods: ['POST'])]
    public function intent(Request $request, MembershipContext $memberships): JsonResponse
    {
        $context = $this->authorizedContext($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $body = BoundedJsonBody::decode($request);
        if (!is_array($body) || !$this->hasExactKeys($body, ['filename', 'mime_type', 'byte_size'])) {
            return $this->error(422, 'invalid_direct_upload', 'Indica nombre, formato y tamaño del archivo.');
        }
        $filename = $body['filename'];
        $mime = $body['mime_type'];
        $size = $body['byte_size'];
        if (!is_string($filename) || !is_string($mime) || !is_int($size)
            || $size <= self::QUICK_MAX_BYTES || $size > DirectUploadTokenCipher::MAX_BYTES
            || !in_array($mime, self::MIMES, true) || !$this->validFilename($filename)) {
            return $this->error(422, 'invalid_direct_upload', 'Indica un archivo permitido de más de 8 MiB y hasta 2 GiB.');
        }
        if (!$this->readiness->publicSummary()['configured']) {
            return $this->error(503, 'direct_upload_unavailable', 'La carga de archivos grandes no está configurada.');
        }

        try {
            $intent = $this->issuer->issue(
                $context['organization']['id'],
                $context['user']->id(),
                $filename,
                $mime,
                $size,
            );
        } catch (\InvalidArgumentException) {
            return $this->error(422, 'invalid_direct_upload', 'Los metadatos del archivo no son válidos.');
        } catch (\RuntimeException) {
            return $this->error(503, 'direct_upload_unavailable', 'La carga de archivos grandes no está disponible.');
        }

        return $this->privateJson(['data' => $intent], 201);
    }

    #[Route('/api/admin/vault/direct-upload/complete', name: 'grindflow_vault_direct_complete', methods: ['POST'])]
    public function complete(Request $request, MembershipContext $memberships): JsonResponse
    {
        $context = $this->authorizedContext($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $body = BoundedJsonBody::decode($request);
        if (!is_array($body) || !$this->hasExactKeys($body, ['upload_token'])
            || !is_string($body['upload_token']) || $body['upload_token'] === ''
            || strlen($body['upload_token']) > 4096) {
            return $this->error(422, 'invalid_upload_token', 'Indica un token de carga válido.');
        }
        if (!$this->readiness->publicSummary()['configured']) {
            return $this->error(503, 'direct_upload_unavailable', 'La carga de archivos grandes no está configurada.');
        }

        try {
            $verified = $this->verifier->verify(
                $body['upload_token'],
                $context['organization']['id'],
                $context['user']->id(),
            );
        } catch (\InvalidArgumentException) {
            return $this->error(422, 'invalid_upload_token', 'La carga no pudo verificarse.');
        } catch (\RuntimeException) {
            return $this->error(503, 'direct_upload_unavailable', 'No fue posible verificar la carga.');
        }

        // Stream verification can take time; recheck current membership and
        // selected tenant before returning even a read-only result.
        $fresh = $this->authorizedContext($request, $memberships);
        if ($fresh instanceof JsonResponse) {
            return $fresh;
        }
        if ($fresh['organization']['id'] !== $context['organization']['id']
            || $fresh['user']->id() !== $context['user']->id()) {
            return $this->error(403, 'organization_access_changed', 'El contexto de tu organización ha cambiado.');
        }

        return $this->privateJson(['data' => [
            'status' => 'verified_staging_only',
            'registered' => false,
            'sha256' => $verified['sha256'],
            'size_bytes' => $verified['byte_size'],
            'mime_type_declared' => $verified['mime_type'],
        ]]);
    }

    /** @return array{user: IdentityUser, organization: array<string,mixed>}|JsonResponse */
    private function authorizedContext(Request $request, MembershipContext $memberships): array|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }
        $selected = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selected) || $selected === '') {
            return $this->error(409, 'organization_required', 'Selecciona una organización.');
        }
        $organization = $memberships->find($user->id(), $selected);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu acceso a esta organización ha cambiado.');
        }
        if (!$memberships->permissions($organization['role'])['content_prepare']) {
            return $this->error(403, 'upload_forbidden', 'Tu rol no permite añadir archivos.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_upload', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }

        return ['user' => $user, 'organization' => $organization];
    }

    /** @param array<array-key,mixed> $body
     *  @param list<string> $names
     */
    private function hasExactKeys(array $body, array $names): bool
    {
        return count($body) === count($names)
            && array_diff(array_keys($body), $names) === []
            && array_diff($names, array_keys($body)) === [];
    }

    private function validFilename(string $filename): bool
    {
        return $filename !== '.' && $filename !== '..'
            && trim($filename) !== '' && strlen($filename) <= 180
            && preg_match('//u', $filename) === 1
            && preg_match('/[\x00-\x1F\x7F\/\\\\]/u', $filename) !== 1;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @param array<string,mixed> $payload */
    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
