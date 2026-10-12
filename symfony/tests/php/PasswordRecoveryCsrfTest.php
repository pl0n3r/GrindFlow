<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Infrastructure\Mail\InMemoryPasswordRecoveryNotifier;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Solo base de CI aislada, cuentas y tokens sinteticos. */
final class PasswordRecoveryCsrfTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testForgotPasswordWithoutValidCsrfReturns403AndDoesNotSendMail(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        /** @var InMemoryPasswordRecoveryNotifier $mailer */
        $mailer = static::getContainer()->get(InMemoryPasswordRecoveryNotifier::class);
        $mailer->reset();
        $id = Uuid::v7()->toRfc4122();
        $email = $id.'@example.test';
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $id, 'name' => 'Synthetic CSRF identity', 'email' => $email,
            'password_hash' => password_hash('synthetic-original-password-123', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            foreach ([[], ['_csrf_token' => 'synthetic-invalid-csrf-token']] as $csrf) {
                $client->request('POST', '/forgot-password', ['email' => $email] + $csrf);
                self::assertResponseStatusCodeSame(403);
                self::assertStringContainsString(
                    'La respuesta es la misma exista o no una cuenta activa.', (string) $client->getResponse()->getContent(),
                );
                self::assertSame(0, (int) $db->fetchOne(
                    'SELECT COUNT(*) FROM gf_password_recovery_outbox WHERE user_id = ?', [$id],
                ));
                self::assertSame(0, (int) $db->fetchOne(
                    'SELECT COUNT(*) FROM gf_password_reset_tokens WHERE user_id = ?', [$id],
                ));
                self::assertSame([], $mailer->messages());
            }
        } finally {
            $db->delete('gf_identity_security_audit', ['user_id' => $id]);
            $db->delete('gf_password_recovery_outbox', ['user_id' => $id]);
            $db->delete('gf_password_reset_tokens', ['user_id' => $id]);
            $db->delete('gf_identity_users', ['id' => $id]);
            $mailer->reset();
        }
    }

    public function testRecoverPasswordWithoutValidCsrfReturns403AndKeepsPasswordAndToken(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        /** @var InMemoryPasswordRecoveryNotifier $mailer */
        $mailer = static::getContainer()->get(InMemoryPasswordRecoveryNotifier::class);
        $mailer->reset();
        $id = Uuid::v7()->toRfc4122();
        $email = $id.'@example.test';
        $originalPassword = 'synthetic-original-password-123';
        $nextPassword = 'synthetic-next-password-456';
        $token = str_repeat('x', 43);
        $tokenHash = hash('sha256', $token);
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $id, 'name' => 'Synthetic reset CSRF', 'email' => $email,
            'password_hash' => password_hash($originalPassword, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            $db->insert('gf_password_reset_tokens', [
                'user_id' => $id, 'token_hash' => $tokenHash,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
                'created_at' => $now,
            ]);
            $initialHash = (string) $db->fetchOne(
                'SELECT password_hash FROM gf_identity_users WHERE id = ?', [$id],
            );
            foreach ([[], ['_csrf_token' => 'synthetic-invalid-csrf-token']] as $csrf) {
                $client->request('POST', '/recover-password', [
                    'token' => $token, 'new_password' => $nextPassword,
                    'confirm_password' => $nextPassword,
                ] + $csrf);
                self::assertResponseStatusCodeSame(403);
                self::assertSame($initialHash, $db->fetchOne(
                    'SELECT password_hash FROM gf_identity_users WHERE id = ?', [$id],
                ));
                self::assertTrue(password_verify($originalPassword, $initialHash));
                self::assertSame($tokenHash, $db->fetchOne(
                    'SELECT token_hash FROM gf_password_reset_tokens WHERE user_id = ?', [$id],
                ));
                self::assertSame(1, (int) $db->fetchOne(
                    'SELECT COUNT(*) FROM gf_password_reset_tokens WHERE user_id = ?', [$id],
                ));
                self::assertSame([], $mailer->messages());
            }
        } finally {
            $db->delete('gf_identity_security_audit', ['user_id' => $id]);
            $db->delete('gf_password_recovery_outbox', ['user_id' => $id]);
            $db->delete('gf_password_reset_tokens', ['user_id' => $id]);
            $db->delete('gf_identity_users', ['id' => $id]);
            $mailer->reset();
        }
    }

    public function testForgotCsrfRejectionDoesNotEnumerateAccounts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        /** @var InMemoryPasswordRecoveryNotifier $mailer */
        $mailer = static::getContainer()->get(InMemoryPasswordRecoveryNotifier::class);
        $mailer->reset();
        $id = Uuid::v7()->toRfc4122();
        $email = $id.'@example.test';
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $id, 'name' => 'Synthetic non enumeration', 'email' => $email,
            'password_hash' => password_hash('synthetic-original-password-123', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            foreach ([[], ['_csrf_token' => 'synthetic-invalid-csrf-token']] as $csrf) {
                $client->request('POST', '/forgot-password', ['email' => $email] + $csrf);
                self::assertResponseStatusCodeSame(403);
                $known = (string) $client->getResponse()->getContent();
                $client->request('POST', '/forgot-password', ['email' => 'missing-'.$email] + $csrf);
                self::assertResponseStatusCodeSame(403);
                $unknown = (string) $client->getResponse()->getContent();
                self::assertSame($known, $unknown);
                self::assertStringContainsString('La respuesta es la misma exista o no una cuenta activa.', $known);
            }
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_password_recovery_outbox WHERE user_id = ?', [$id],
            ));
            self::assertSame([], $mailer->messages());
        } finally {
            $db->delete('gf_identity_security_audit', ['user_id' => $id]);
            $db->delete('gf_password_recovery_outbox', ['user_id' => $id]);
            $db->delete('gf_password_reset_tokens', ['user_id' => $id]);
            $db->delete('gf_identity_users', ['id' => $id]);
            $mailer->reset();
        }
    }
}
