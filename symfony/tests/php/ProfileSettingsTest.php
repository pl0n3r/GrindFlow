<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Synthetic account fixtures live only in the isolated Symfony MariaDB. */
final class ProfileSettingsTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testOnlyAuthenticatedActiveActorCanRenameTheirOwnProfile(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $actor = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $membership = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');

        $client->request('POST', '/api/admin/profile/name', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{"name":"Anonymous"}');
        self::assertResponseStatusCodeSame(401);

        foreach ([$actor => 'Cuenta original', $other => 'Cuenta ajena'] as $id => $name) {
            $db->insert('gf_identity_users', [
                'id' => $id,
                'name' => $name,
                'email' => $id.'@example.test',
                'password_hash' => password_hash('isolated-profile-only', PASSWORD_BCRYPT),
                'platform_role' => 'model',
                'is_active' => 1,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        try {
            $db->insert('gf_identity_organizations', [
                'id' => $organization,
                'name' => 'Espacio original',
                'slug' => 'ci-'.substr($organization, 0, 30),
                'type' => 'independent',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $db->insert('gf_identity_memberships', [
                'id' => $membership,
                'user_id' => $actor,
                'organization_id' => $organization,
                'role' => 'editor',
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $actor.'@example.test',
                'password' => 'isolated-profile-only',
            ]));
            self::assertResponseRedirects('/organizations');

            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $context = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame('Cuenta original', $context['data']['user']['display_name']);
            self::assertFalse($context['data']['permissions']['organization_manage']);
            self::assertNotEmpty($context['data']['profile_name_csrf']);
            $token = $context['data']['profile_name_csrf'];

            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => 'invalid-token',
            ], '{"name":"Sin CSRF"}');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], json_encode(['name' => 'IDOR', 'user_id' => $other], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);

            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], '{"name":"A"}');
            self::assertResponseStatusCodeSame(422);

            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], '{"name":"Nombre\\ninyectado"}');
            self::assertResponseStatusCodeSame(422);

            $normalName = json_encode(['name' => 'Perfil actualizado'], JSON_THROW_ON_ERROR);
            $tooLarge = $normalName.str_repeat(' ', 4097 - strlen($normalName));
            self::assertSame(4097, strlen($tooLarge));
            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
                'CONTENT_LENGTH' => '1',
            ], $tooLarge);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Cuenta original', $db->fetchOne('SELECT name FROM gf_identity_users WHERE id = ?', [$actor]));

            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
                'CONTENT_LENGTH' => '8192',
            ], $normalName);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Cuenta original', $db->fetchOne('SELECT name FROM gf_identity_users WHERE id = ?', [$actor]));

            $deepValue = 'no permitir';
            for ($depth = 0; $depth < 17; ++$depth) {
                $deepValue = [$deepValue];
            }
            $deepJson = '{"name":'.json_encode($deepValue, JSON_THROW_ON_ERROR)
                .',"name":"Perfil actualizado"}';
            self::assertSame('Perfil actualizado', json_decode($deepJson, true, 512, JSON_THROW_ON_ERROR)['name']);
            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], $deepJson);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Cuenta original', $db->fetchOne('SELECT name FROM gf_identity_users WHERE id = ?', [$actor]));

            $atLimit = $normalName.str_repeat(' ', 4096 - strlen($normalName));
            self::assertSame(4096, strlen($atLimit));
            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], $atLimit);
            self::assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame('Perfil actualizado', $payload['data']['user']['display_name']);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('Perfil actualizado', $db->fetchOne('SELECT name FROM gf_identity_users WHERE id = ?', [$actor]));
            self::assertSame('Cuenta ajena', $db->fetchOne('SELECT name FROM gf_identity_users WHERE id = ?', [$other]));
            self::assertSame('Espacio original', $db->fetchOne('SELECT name FROM gf_identity_organizations WHERE id = ?', [$organization]));

            $client->request('GET', '/api/admin/context', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $after = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame('Perfil actualizado', $after['data']['user']['display_name']);

            $db->update('gf_identity_users', ['is_active' => 0], ['id' => $actor]);
            $client->request('POST', '/api/admin/profile/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], '{"name":"Sin acceso"}');
            self::assertNotSame(200, $client->getResponse()->getStatusCode());
            self::assertSame('Perfil actualizado', $db->fetchOne('SELECT name FROM gf_identity_users WHERE id = ?', [$actor]));
        } finally {
            $db->delete('gf_identity_memberships', ['id' => $membership]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
            $db->delete('gf_identity_users', ['id' => $actor]);
            $db->delete('gf_identity_users', ['id' => $other]);
        }
    }
}
