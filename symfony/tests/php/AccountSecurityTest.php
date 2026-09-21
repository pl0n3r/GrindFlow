<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** All credentials and writes below belong to the disposable Symfony DB. */
final class AccountSecurityTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAuthenticatedUserCanChangeOnlyOwnPasswordAndMustSignInAgain(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $actor = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $old = 'old-synthetic-password-123';
        $next = 'new-synthetic-password-456';
        $endpoint = '/api/admin/profile/password';
        $jsonHeaders = ['CONTENT_TYPE' => 'application/json'];

        $client->request('POST', $endpoint, [], [], $jsonHeaders, '{}');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('authentication_required', json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);

        foreach ([$actor => 'Propia cuenta', $other => 'Cuenta ajena'] as $id => $name) {
            $db->insert('gf_identity_users', [
                'id' => $id, 'name' => $name, 'email' => $id.'@example.test',
                'password_hash' => password_hash($old, PASSWORD_BCRYPT),
                'platform_role' => 'model', 'is_active' => 1,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        $otherHash = (string) $db->fetchOne('SELECT password_hash FROM gf_identity_users WHERE id = ?', [$other]);
        try {
            $db->insert('gf_identity_organizations', [
                'id' => $organization, 'name' => 'Organización de prueba',
                'slug' => 'ci-'.substr($organization, 0, 30), 'type' => 'independent',
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $actor,
                'organization_id' => $organization, 'role' => 'model',
                'created_at' => $at, 'updated_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $actor.'@example.test', 'password' => $old,
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $context = json_decode((string) $client->getResponse()->getContent(), true);
            $csrf = $context['data']['profile_password_csrf'];
            self::assertNotEmpty($csrf);
            self::assertFalse($context['data']['permissions']['organization_manage']);
            $headers = $jsonHeaders + ['HTTP_X_CSRF_TOKEN' => $csrf];
            $valid = ['current_password' => $old, 'new_password' => $next, 'confirm_password' => $next];

            $client->request('POST', $endpoint, [], [], $jsonHeaders, json_encode($valid, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $endpoint, [], [], $headers, json_encode($valid + ['user_id' => $other], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            $client->request('POST', $endpoint, [], [], $headers, json_encode([
                'current_password' => 'wrong-current', 'new_password' => $next, 'confirm_password' => $next,
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            self::assertSame('current_password_invalid', json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
            $client->request('POST', $endpoint, [], [], $headers, json_encode([
                'current_password' => $old, 'new_password' => $next, 'confirm_password' => 'different',
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            $client->request('POST', $endpoint, [], [], $headers, json_encode([
                'current_password' => $old, 'new_password' => 'short', 'confirm_password' => 'short',
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            $client->request('POST', $endpoint, [], [], $headers, json_encode([
                'current_password' => $old, 'new_password' => $old, 'confirm_password' => $old,
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);

            self::assertTrue(password_verify($old, (string) $db->fetchOne(
                'SELECT password_hash FROM gf_identity_users WHERE id = ?', [$actor],
            )));
            $client->request('POST', $endpoint, [], [], $headers, json_encode($valid, JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertSame(['data' => ['reauthentication_required' => true]], json_decode((string) $client->getResponse()->getContent(), true));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringNotContainsString($next, (string) $client->getResponse()->getContent());
            self::assertTrue(password_verify($next, (string) $db->fetchOne(
                'SELECT password_hash FROM gf_identity_users WHERE id = ?', [$actor],
            )));
            self::assertSame($otherHash, $db->fetchOne('SELECT password_hash FROM gf_identity_users WHERE id = ?', [$other]));

            $client->request('POST', $endpoint, [], [], $headers, json_encode($valid, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(401);
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $actor.'@example.test', 'password' => $old,
            ]));
            self::assertResponseRedirects('/login');
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $actor.'@example.test', 'password' => $next,
            ]));
            self::assertResponseRedirects('/organizations');
        } finally {
            $db->delete('gf_identity_memberships', ['user_id' => $actor]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
            $db->delete('gf_identity_users', ['id' => $actor]);
            $db->delete('gf_identity_users', ['id' => $other]);
        }
    }
}
