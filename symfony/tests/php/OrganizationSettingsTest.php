<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** All writes occur against the disposable Symfony MariaDB, never production. */
final class OrganizationSettingsTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testOrganizationSettingsRejectCrossTenantRoleAndCsrfAndAllowOwnAdmin(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');

        $client->request('POST', '/api/admin/organization/name', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{"name":"Denied"}');
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Synthetic settings actor',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('isolated-fixture-only', PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        try {
            foreach ([$mine => 'Own studio', $foreign => 'Foreign studio'] as $id => $name) {
                $db->insert('gf_identity_organizations', [
                    'id' => $id,
                    'name' => $name,
                    'slug' => 'ci-'.substr($id, 0, 30),
                    'type' => 'studio',
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $user,
                'organization_id' => $mine,
                'role' => 'editor',
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test',
                'password' => 'isolated-fixture-only',
            ]));
            self::assertResponseRedirects('/organizations');

            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], '{"name":"No tenant"}');
            self::assertResponseStatusCodeSame(409);

            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $context = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertFalse($context['data']['permissions']['organization_manage']);
            self::assertNull($context['data']['organization_name_csrf']);

            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], '{"name":"Editor cannot"}');
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Own studio', $db->fetchOne('SELECT name FROM gf_identity_organizations WHERE id = ?', [$mine]));

            $db->update('gf_identity_memberships', ['role' => 'studio'], [
                'user_id' => $user, 'organization_id' => $mine,
            ]);
            $client->request('GET', '/api/admin/context', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $context = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertTrue($context['data']['permissions']['organization_manage']);
            $token = $context['data']['organization_name_csrf'];
            self::assertNotEmpty($token);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => 'invalid',
            ], '{"name":"Invalid CSRF"}');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], json_encode(['name' => 'IDOR blocked', 'organization_id' => $foreign], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);

            $updatedJson = '{"name":"Updated own studio"}';
            $oversized = $updatedJson.str_repeat(' ', 4097 - strlen($updatedJson));
            self::assertSame(4097, strlen($oversized));
            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
                'CONTENT_LENGTH' => '1',
            ], $oversized);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Own studio', $db->fetchOne('SELECT name FROM gf_identity_organizations WHERE id = ?', [$mine]));

            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
                'CONTENT_LENGTH' => '8192',
            ], $updatedJson);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Own studio', $db->fetchOne('SELECT name FROM gf_identity_organizations WHERE id = ?', [$mine]));

            $deepValue = 'nested';
            for ($depth = 0; $depth < 17; ++$depth) {
                $deepValue = [$deepValue];
            }
            $deepJson = '{"name":'.json_encode($deepValue, JSON_THROW_ON_ERROR)
                .',"name":"Updated own studio"}';
            self::assertSame('Updated own studio', json_decode($deepJson, true, 512, JSON_THROW_ON_ERROR)['name']);
            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], $deepJson);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Own studio', $db->fetchOne('SELECT name FROM gf_identity_organizations WHERE id = ?', [$mine]));

            $atLimit = $updatedJson.str_repeat(' ', 4096 - strlen($updatedJson));
            self::assertSame(4096, strlen($atLimit));
            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], $atLimit);
            self::assertResponseIsSuccessful();
            $result = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame('Updated own studio', $result['data']['organization']['name']);
            self::assertSame('Updated own studio', $db->fetchOne(
                'SELECT name FROM gf_identity_organizations WHERE id = ?', [$mine],
            ));
            self::assertSame('Foreign studio', $db->fetchOne(
                'SELECT name FROM gf_identity_organizations WHERE id = ?', [$foreign],
            ));

            $db->update('gf_identity_memberships', ['role' => 'editor'], [
                'user_id' => $user, 'organization_id' => $mine,
            ]);
            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], '{"name":"Revoked role cannot"}');
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Updated own studio', $db->fetchOne(
                'SELECT name FROM gf_identity_organizations WHERE id = ?', [$mine],
            ));

            $db->update('gf_identity_users', ['is_active' => 0], ['id' => $user]);
            $client->request('GET', '/api/admin/context', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertNotSame(200, $client->getResponse()->getStatusCode());
        } finally {
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }
}
