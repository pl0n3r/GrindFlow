<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Security\PasswordRecoveryNotifier;
use GrindFlow\Infrastructure\Mail\InMemoryPasswordRecoveryNotifier;
use GrindFlow\Infrastructure\Mail\PasswordRecoveryDeliverCommand;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class PasswordRecoveryTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testRecoveryIsNonEnumeratingHashedOneUseAndChangesPassword(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        /** @var InMemoryPasswordRecoveryNotifier $mailer */
        $mailer = static::getContainer()->get(InMemoryPasswordRecoveryNotifier::class);
        $mailer->reset();

        $userId = Uuid::v7()->toRfc4122();
        $email = $userId.'@example.test';
        $old = 'synthetic-old-password-123';
        $next = 'synthetic-new-password-456';
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $userId, 'name' => 'Cuenta recuperación', 'email' => $email,
            'password_hash' => password_hash($old, PASSWORD_BCRYPT),
            'platform_role' => 'admin', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            $unknown = $client->request('GET', '/forgot-password');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-referrer', (string) $client->getResponse()->headers->get('Referrer-Policy'));
            $client->submit($unknown->filter('form')->form(['email' => 'missing-'.$email]));
            self::assertResponseIsSuccessful();
            $unknownBody = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Si existe una cuenta activa', $unknownBody);
            self::assertSame([], $mailer->messages());

            $known = $client->request('GET', '/forgot-password');
            $client->submit($known->filter('form')->form(['email' => $email]));
            self::assertResponseIsSuccessful();
            $knownBody = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Si existe una cuenta activa', $knownBody);
            // Both outward responses use the same generic status text.
            self::assertSame(
                preg_match('/Si existe una cuenta activa/', $unknownBody),
                preg_match('/Si existe una cuenta activa/', $knownBody),
            );

            self::assertSame([], $mailer->messages());
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_password_recovery_outbox WHERE user_id = ?', [$userId],
            ));
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_password_reset_tokens WHERE user_id = ?', [$userId],
            ));
            self::deliverPending($db, $mailer);
            $messages = $mailer->messages();
            self::assertCount(1, $messages);
            self::assertSame('reset', $messages[0]['type']);
            self::assertSame($email, $messages[0]['email']);
            $token = (string) $messages[0]['token'];
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $token);
            $row = $db->fetchAssociative('SELECT token_hash, expires_at FROM gf_password_reset_tokens WHERE user_id = ?', [$userId]);
            self::assertNotFalse($row);
            self::assertSame(hash('sha256', $token), $row['token_hash']);
            self::assertNotSame($token, $row['token_hash']);
            self::assertGreaterThan(time(), strtotime((string) $row['expires_at']));
            self::assertLessThanOrEqual(time() + 3600, strtotime((string) $row['expires_at']));

            $reset = $client->request('GET', '/recover-password');
            self::assertResponseIsSuccessful();
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
            self::assertSame('', $reset->filter('#password-recovery-token')->attr('value'));
            self::assertStringContainsString('/assets/password-recovery.js', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('<script>', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent());

            $client->submit($reset->filter('form')->form([
                'token' => $token,
                'new_password' => $next,
                'confirm_password' => $next,
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'contraseña cambió');
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_password_reset_tokens WHERE user_id = ?', [$userId]));
            self::assertTrue(password_verify($next, (string) $db->fetchOne(
                'SELECT password_hash FROM gf_identity_users WHERE id = ?', [$userId],
            )));
            self::assertSame(
                ['password_recovery_requested', 'password_reset'],
                $db->fetchFirstColumn(
                    'SELECT event FROM gf_identity_security_audit WHERE user_id = ? ORDER BY occurred_at, event',
                    [$userId],
                ),
            );
            $messages = $mailer->messages();
            self::assertCount(2, $messages);
            self::assertSame('changed', $messages[1]['type']);

            $reuse = $client->request('GET', '/recover-password');
            $client->submit($reuse->filter('form')->form([
                'token' => $token,
                'new_password' => 'third-synthetic-password-789',
                'confirm_password' => 'third-synthetic-password-789',
            ]));
            self::assertResponseStatusCodeSame(422);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form(['email' => $email, 'password' => $old]));
            self::assertResponseRedirects('/login');
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form(['email' => $email, 'password' => $next]));
            self::assertResponseRedirects('/organizations');
        } finally {
            $db->delete('gf_identity_security_audit', ['user_id' => $userId]);
            $db->delete('gf_password_recovery_outbox', ['user_id' => $userId]);
            $db->delete('gf_password_reset_tokens', ['user_id' => $userId]);
            $db->delete('gf_identity_users', ['id' => $userId]);
        }
    }


    public function testResetInvalidatesPreviouslyAuthenticatedSession(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        /** @var InMemoryPasswordRecoveryNotifier $mailer */
        $mailer = static::getContainer()->get(InMemoryPasswordRecoveryNotifier::class);
        $mailer->reset();

        $userId = Uuid::v7()->toRfc4122();
        $organizationId = Uuid::v7()->toRfc4122();
        $membershipId = Uuid::v7()->toRfc4122();
        $email = $userId.'@example.test';
        $old = 'synthetic-session-old-password-123';
        $next = 'synthetic-session-new-password-456';
        $now = gmdate('Y-m-d H:i:s');

        $db->insert('gf_identity_users', [
            'id' => $userId, 'name' => 'Sesión previa', 'email' => $email,
            'password_hash' => password_hash($old, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('gf_identity_organizations', [
            'id' => $organizationId, 'name' => 'Organización sesión previa',
            'slug' => 'session-'.substr($organizationId, 0, 24), 'type' => 'independent',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('gf_identity_memberships', [
            'id' => $membershipId, 'user_id' => $userId,
            'organization_id' => $organizationId, 'role' => 'model',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $email, 'password' => $old,
            ]));
            self::assertResponseRedirects('/organizations');
            $client->request('GET', '/organizations');
            self::assertResponseIsSuccessful();

            $staleCookies = array_map(
                static fn ($cookie) => clone $cookie,
                $client->getCookieJar()->all(),
            );
            self::assertNotEmpty($staleCookies);

            // Perform the reset from another browser/session.
            $client->getCookieJar()->clear();
            $forgot = $client->request('GET', '/forgot-password');
            $client->submit($forgot->filter('form')->form(['email' => $email]));
            self::assertResponseIsSuccessful();
            self::assertSame([], $mailer->messages());
            self::deliverPending($db, $mailer);
            $messages = $mailer->messages();
            self::assertCount(1, $messages);
            $token = (string) $messages[0]['token'];

            $reset = $client->request('GET', '/recover-password');
            $client->submit($reset->filter('form')->form([
                'token' => $token,
                'new_password' => $next,
                'confirm_password' => $next,
            ]));
            self::assertResponseIsSuccessful();

            // Replaying the old authenticated session must fail after the
            // password hash changes. Symfony refreshes the user from the
            // provider and invalidates the token when the password differs.
            $client->getCookieJar()->clear();
            foreach ($staleCookies as $cookie) {
                $client->getCookieJar()->set($cookie);
            }
            $client->request('GET', '/organizations');
            self::assertResponseRedirects('/login');
        } finally {
            $db->delete('gf_identity_security_audit', ['user_id' => $userId]);
            $db->delete('gf_password_recovery_outbox', ['user_id' => $userId]);
            $db->delete('gf_password_reset_tokens', ['user_id' => $userId]);
            $db->delete('gf_identity_memberships', ['id' => $membershipId]);
            $db->delete('gf_identity_organizations', ['id' => $organizationId]);
            $db->delete('gf_identity_users', ['id' => $userId]);
        }
    }

public function testReissueInvalidatesPreviousTokenAndCommonPasswordIsRejected(): void
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
            'id' => $id, 'name' => 'Reissue test', 'email' => $email,
            'password_hash' => password_hash('synthetic-source-password-123', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            for ($i = 0; $i < 2; $i++) {
                $page = $client->request('GET', '/forgot-password');
                $client->submit($page->filter('form')->form(['email' => $email]));
                self::assertResponseIsSuccessful();
                self::deliverPending($db, $mailer);
            }
            $messages = $mailer->messages();
            self::assertCount(2, $messages);
            $first = (string) $messages[0]['token'];
            $second = (string) $messages[1]['token'];
            self::assertNotSame($first, $second);
            self::assertSame(hash('sha256', $second), $db->fetchOne(
                'SELECT token_hash FROM gf_password_reset_tokens WHERE user_id = ?', [$id],
            ));

            $page = $client->request('GET', '/recover-password');
            $client->submit($page->filter('form')->form([
                'token' => $first, 'new_password' => 'password1234', 'confirm_password' => 'password1234',
            ]));
            self::assertResponseStatusCodeSame(422);
            self::assertTrue(password_verify('synthetic-source-password-123', (string) $db->fetchOne(
                'SELECT password_hash FROM gf_identity_users WHERE id = ?', [$id],
            )));
        } finally {
            $db->delete('gf_identity_security_audit', ['user_id' => $id]);
            $db->delete('gf_password_recovery_outbox', ['user_id' => $id]);
            $db->delete('gf_password_reset_tokens', ['user_id' => $id]);
            $db->delete('gf_identity_users', ['id' => $id]);
        }
    }
    public function testDeliveryFailureCannotDeleteANewerResetToken(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $userId = Uuid::v7()->toRfc4122();
        $email = $userId.'@example.test';
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $userId, 'name' => 'Compare delete', 'email' => $email,
            'password_hash' => password_hash('synthetic-source-password-123', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            $page = $client->request('GET', '/forgot-password');
            $client->submit($page->filter('form')->form(['email' => $email]));
            self::assertResponseIsSuccessful();

            $newerHash = str_repeat('a', 64);
            $failing = new class($db, $userId, $newerHash) implements PasswordRecoveryNotifier {
                public function __construct(
                    private readonly Connection $db,
                    private readonly string $userId,
                    private readonly string $newerHash,
                ) {
                }

                public function sendReset(string $email, string $displayName, string $token): bool
                {
                    $this->db->update('gf_password_reset_tokens', [
                        'token_hash' => $this->newerHash,
                        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
                    ], ['user_id' => $this->userId]);

                    return false;
                }

                public function sendPasswordChanged(string $email, string $displayName): bool
                {
                    return false;
                }
            };

            $tester = new CommandTester(new PasswordRecoveryDeliverCommand($db, $failing));
            self::assertSame(1, $tester->execute(['--limit' => '1']));
            self::assertSame($newerHash, $db->fetchOne(
                'SELECT token_hash FROM gf_password_reset_tokens WHERE user_id = ?', [$userId],
            ));
            self::assertSame('delivery_failed', $db->fetchOne(
                'SELECT last_error_code FROM gf_password_recovery_outbox WHERE user_id = ?', [$userId],
            ));
        } finally {
            $db->delete('gf_password_recovery_outbox', ['user_id' => $userId]);
            $db->delete('gf_password_reset_tokens', ['user_id' => $userId]);
            $db->delete('gf_identity_security_audit', ['user_id' => $userId]);
            $db->delete('gf_identity_users', ['id' => $userId]);
        }
    }

    private static function deliverPending(
        Connection $db,
        InMemoryPasswordRecoveryNotifier $mailer,
    ): void {
        $tester = new CommandTester(new PasswordRecoveryDeliverCommand($db, $mailer));
        self::assertSame(0, $tester->execute(['--limit' => '10']));
    }

}
