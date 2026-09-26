<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Http\BoundedJsonBody;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Identity\Security\PasswordPolicy;
use GrindFlow\Identity\Security\PasswordRecoveryNotifier;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A personal account action: organization roles must not grant password access.
 * Never accept an actor ID or organization ID from the browser.
 */
final class AccountSecurityController extends AbstractController
{
    #[Route('/api/admin/profile/password', name: 'grindflow_profile_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        Connection $db,
        UserPasswordHasherInterface $hasher,
        TokenStorageInterface $tokens,
        PasswordPolicy $passwordPolicy,
        PasswordRecoveryNotifier $notifier,
        #[Target('profile_password')] RateLimiterFactoryInterface $attemptLimiter,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }
        if (!$this->isCsrfTokenValid('grindflow_profile_password', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }

        // Bound password-hash work across sessions for this identity, even for malformed payloads.
        // The limiter's cache is server-side and never derived from a browser-provided actor ID.
        $limit = $attemptLimiter->create($user->id())->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $response = $this->error(429, 'password_change_rate_limited', 'Demasiados intentos. Inténtalo más tarde.');
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        // Authenticate, validate CSRF and consume the per-identity attempt before parsing.
        // The shared reader enforces the same byte and depth contract as profile rename.
        $body = BoundedJsonBody::decode($request);
        if (!is_array($body) || $request->request->all() !== [] || $request->files->all() !== []) {
            return $this->error(422, 'invalid_password', 'Completa los tres campos de contraseña.');
        }
        $keys = array_keys($body);
        sort($keys);
        if ($keys !== ['confirm_password', 'current_password', 'new_password']
            || !is_string($body['current_password']) || !is_string($body['new_password'])
            || !is_string($body['confirm_password'])) {
            return $this->error(422, 'invalid_password', 'Completa los tres campos de contraseña.');
        }

        $old = $body['current_password'];
        $new = $body['new_password'];
        if ($old === '' || strlen($old) > 1024 || !$passwordPolicy->isAcceptable($new)) {
            return $this->error(422, 'invalid_password', 'Elige una contraseña segura de 12 a 128 caracteres.');
        }
        if (!hash_equals($new, $body['confirm_password'])) {
            return $this->error(422, 'password_confirmation_mismatch', 'Las nuevas contraseñas no coinciden.');
        }
        // Hash before acquiring the row lock: avoid holding a DB lock during KDF work.
        $newHash = $hasher->hashPassword($user, $new);
        $result = $db->transactional(function (Connection $db) use ($user, $old, $new, $newHash): string {
            $account = $db->fetchAssociative(
                'SELECT password_hash, is_active FROM gf_identity_users WHERE id = :id FOR UPDATE',
                ['id' => $user->id()],
            );
            if ($account === false || (int) $account['is_active'] !== 1) {
                return 'revoked';
            }
            $storedHash = (string) $account['password_hash'];
            // Read the live hash under lock, not the possibly stale serialized user.
            if (!password_verify($old, $storedHash)) {
                return 'incorrect';
            }
            if (hash_equals($old, $new) || password_verify($new, $storedHash)) {
                return 'reused';
            }
            $written = $db->executeStatement(
                <<<'SQL'
                    UPDATE gf_identity_users
                    SET password_hash = :new_hash, updated_at = :updated
                    WHERE id = :id AND is_active = 1 AND password_hash = :old_hash
                    SQL,
                [
                    'new_hash' => $newHash, 'updated' => gmdate('Y-m-d H:i:s'),
                    'id' => $user->id(), 'old_hash' => $storedHash,
                ],
            );

            if ($written !== 1) {
                return 'revoked';
            }
            $db->insert('gf_identity_security_audit', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $user->id(),
                'event' => 'password_changed',
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return 'changed';
        });

        if ($result === 'incorrect') {
            return $this->error(422, 'current_password_invalid', 'La contraseña actual no coincide.');
        }
        if ($result === 'reused') {
            return $this->error(422, 'password_reused', 'Elige una contraseña diferente de la actual.');
        }
        if ($result !== 'changed') {
            return $this->error(403, 'account_access_changed', 'Tu cuenta ya no está disponible.');
        }

        // Notification failure must never roll the credential back.
        $notifier->sendPasswordChanged($user->email(), $user->displayName());

        // The current authenticated session must not continue after the change.
        $tokens->setToken(null);
        $request->getSession()->invalidate();

        return $this->privateJson(['data' => ['reauthentication_required' => true]]);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @param array<string, mixed> $payload */
    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
