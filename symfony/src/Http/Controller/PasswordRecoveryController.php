<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Identity\Security\PasswordPolicy;
use GrindFlow\Identity\Security\PasswordRecoveryNotifier;
use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PasswordRecoveryController extends AbstractController
{
    private const GENERIC_MESSAGE = 'Si existe una cuenta activa para ese correo, enviaremos instrucciones de recuperación.';

    #[Route('/forgot-password', name: 'grindflow_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(
        Request $request,
        Connection $db,
        PasswordRecoveryNotifier $notifier,
        ProductVersion $version,
        #[Target('password_recovery_ip')] RateLimiterFactoryInterface $ipLimiter,
        #[Target('password_recovery_account')] RateLimiterFactoryInterface $accountLimiter,
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->privatePage('identity/forgot-password.html.twig', [
                'app_version' => $version->human(), 'sent' => false, 'message' => self::GENERIC_MESSAGE,
            ]);
        }

        $started = hrtime(true);
        if (!$this->isCsrfTokenValid('grindflow_password_recovery_request', (string) $request->request->get('_csrf_token', ''))) {
            return $this->privatePage('identity/forgot-password.html.twig', [
                'app_version' => $version->human(), 'sent' => false, 'message' => self::GENERIC_MESSAGE,
            ], 403);
        }

        $email = strtolower(trim((string) $request->request->get('email', '')));
        $ipKey = hash('sha256', (string) ($request->getClientIp() ?? 'unknown'));
        $accountKey = hash('sha256', $email);
        $ipLimit = $ipLimiter->create($ipKey)->consume();
        $accountLimit = $accountLimiter->create($accountKey)->consume();
        if (!$ipLimit->isAccepted() || !$accountLimit->isAccepted()) {
            $this->padRecoveryResponse($started);
            $response = $this->privatePage('identity/forgot-password.html.twig', [
                'app_version' => $version->human(), 'sent' => true, 'message' => self::GENERIC_MESSAGE,
            ], 429);
            $response->headers->set('Retry-After', '60');

            return $response;
        }

        // Generate equivalent cryptographic work whether the account exists or not.
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $account = filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 255
            ? $db->fetchAssociative(
                'SELECT id, name, email FROM gf_identity_users WHERE email = :email AND is_active = 1 LIMIT 1',
                ['email' => $email],
            )
            : false;

        if ($account !== false) {
            $userId = (string) $account['id'];
            $now = gmdate('Y-m-d H:i:s');
            $expires = gmdate('Y-m-d H:i:s', time() + 3600);
            $db->transactional(function (Connection $db) use ($userId, $hash, $now, $expires): void {
                $db->delete('gf_password_reset_tokens', ['user_id' => $userId]);
                $db->insert('gf_password_reset_tokens', [
                    'user_id' => $userId, 'token_hash' => $hash,
                    'expires_at' => $expires, 'created_at' => $now,
                ]);
                $this->audit($db, $userId, 'password_recovery_requested');
            });

            if (!$notifier->sendReset((string) $account['email'], (string) $account['name'], $token)) {
                // Fail closed: a reset token that was never delivered must not remain usable.
                $db->delete('gf_password_reset_tokens', ['user_id' => $userId]);
            }
        }

        $this->padRecoveryResponse($started);

        return $this->privatePage('identity/forgot-password.html.twig', [
            'app_version' => $version->human(), 'sent' => true, 'message' => self::GENERIC_MESSAGE,
        ]);
    }

    #[Route('/recover-password', name: 'grindflow_password_recover', methods: ['GET', 'POST'])]
    public function recover(
        Request $request,
        Connection $db,
        EntityManagerInterface $entities,
        UserPasswordHasherInterface $hasher,
        PasswordPolicy $passwordPolicy,
        PasswordRecoveryNotifier $notifier,
        ProductVersion $version,
        #[Target('password_reset_ip')] RateLimiterFactoryInterface $ipLimiter,
        #[Target('password_reset_account')] RateLimiterFactoryInterface $accountLimiter,
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->privateRecoveryPage($version, false, null);
        }
        if (!$this->isCsrfTokenValid('grindflow_password_recovery_reset', (string) $request->request->get('_csrf_token', ''))) {
            return $this->privateRecoveryPage($version, false, 'Solicitud inválida o vencida.', 403);
        }

        $ipLimit = $ipLimiter->create(hash('sha256', (string) ($request->getClientIp() ?? 'unknown')))->consume();
        if (!$ipLimit->isAccepted()) {
            return $this->privateRecoveryPage($version, false, 'Demasiados intentos. Inténtalo más tarde.', 429);
        }

        $token = trim((string) $request->request->get('token', ''));
        $new = (string) $request->request->get('new_password', '');
        $confirm = (string) $request->request->get('confirm_password', '');
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) !== 1
            || !$passwordPolicy->isAcceptable($new)
            || !hash_equals($new, $confirm)) {
            return $this->privateRecoveryPage($version, false, 'El enlace o la nueva contraseña no son válidos.', 422);
        }

        $tokenHash = hash('sha256', $token);
        $record = $db->fetchAssociative(
            'SELECT t.user_id, t.expires_at FROM gf_password_reset_tokens t '
            .'JOIN gf_identity_users u ON u.id = t.user_id '
            .'WHERE t.token_hash = :hash AND u.is_active = 1 LIMIT 1',
            ['hash' => $tokenHash],
        );
        if ($record === false || strtotime((string) $record['expires_at']) <= time()) {
            return $this->privateRecoveryPage($version, false, 'El enlace de recuperación no es válido o ya venció.', 422);
        }

        $userId = (string) $record['user_id'];
        if (!$accountLimiter->create($userId)->consume()->isAccepted()) {
            return $this->privateRecoveryPage($version, false, 'Demasiados intentos. Inténtalo más tarde.', 429);
        }
        $user = $entities->find(IdentityUser::class, $userId);
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->privateRecoveryPage($version, false, 'El enlace de recuperación no es válido o ya venció.', 422);
        }
        if ($hasher->isPasswordValid($user, $new)) {
            return $this->privateRecoveryPage($version, false, 'Elige una contraseña diferente de la anterior.', 422);
        }
        $newHash = $hasher->hashPassword($user, $new);

        $changed = $db->transactional(function (Connection $db) use ($userId, $tokenHash, $newHash): bool {
            $locked = $db->fetchAssociative(
                'SELECT t.token_hash, t.expires_at, u.is_active FROM gf_password_reset_tokens t '
                .'JOIN gf_identity_users u ON u.id = t.user_id '
                .'WHERE t.user_id = :id FOR UPDATE',
                ['id' => $userId],
            );
            if ($locked === false || !hash_equals((string) $locked['token_hash'], $tokenHash)
                || (int) $locked['is_active'] !== 1 || strtotime((string) $locked['expires_at']) <= time()) {
                return false;
            }
            $written = $db->update('gf_identity_users', [
                'password_hash' => $newHash, 'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $userId, 'is_active' => 1]);
            if ($written !== 1) {
                return false;
            }
            $db->delete('gf_password_reset_tokens', ['user_id' => $userId]);
            $this->audit($db, $userId, 'password_reset');

            return true;
        });

        if (!$changed) {
            return $this->privateRecoveryPage($version, false, 'El enlace de recuperación no es válido o ya fue usado.', 422);
        }

        $notifier->sendPasswordChanged($user->email(), $user->displayName());

        return $this->privateRecoveryPage($version, true, null);
    }

    private function audit(Connection $db, string $userId, string $event): void
    {
        $db->insert('gf_identity_security_audit', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $userId,
            'event' => $event, 'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function padRecoveryResponse(int $started): void
    {
        $remaining = 150_000_000 - (hrtime(true) - $started);
        if ($remaining > 0) {
            usleep((int) ceil($remaining / 1000));
        }
    }

    /** @param array<string,mixed> $context */
    private function privatePage(string $template, array $context, int $status = 200): Response
    {
        $response = $this->render($template, $context, new Response(status: $status));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function privateRecoveryPage(ProductVersion $version, bool $changed, ?string $error, int $status = 200): Response
    {
        return $this->privatePage('identity/recover-password.html.twig', [
            'app_version' => $version->human(), 'changed' => $changed, 'recovery_error' => $error,
        ], $status);
    }
}
