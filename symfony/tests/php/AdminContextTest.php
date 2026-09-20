<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Role-specific organization writes are tested only on disposable Symfony MariaDB. */
final class AdminContextTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAdminContextAndWriteAreTenantScopedAndRoleChecked(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Synthetic admin',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('only-for-isolated-ci', PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        try {
            foreach ([$mine => 'Own organization', $foreign => 'Foreign organization'] as $id => $name) {
                $db->insert('gf_identity_organizations', [
                    'id' => $id,
                    'name' => $name,
                    'slug' => 'ci-'.substr($id, 0, 30),
                    'type' => 'independent',
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $user,
                'organization_id' => $mine,
                'role' => 'admin',
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $client->request('GET', '/api/admin/context');
            self::assertResponseStatusCodeSame(401);
            $client->request('POST', '/api/admin/organization/name', [], [], ['CONTENT_TYPE' => 'application/json'], '{"name":"No"}');
            self::assertResponseStatusCodeSame(401);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test',
                'password' => 'only-for-isolated-ci',
            ]));
            self::assertResponseRedirects('/organizations');

            $client->request('GET', '/api/admin/context');
            self::assertResponseStatusCodeSame(409);

            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $context = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame($mine, $context['organization']['id']);
            self::assertSame('admin', $context['organization']['role']);
            self::assertTrue($context['permissions']['rename_organization']);
            self::assertNotEmpty($context['csrf_token']);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => 'invalid',
            ], '{"name":"Never saved"}');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $context['csrf_token'],
            ], json_encode(['name' => 'Wrong tenant', 'organization_id' => $foreign], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Foreign organization', $db->fetchOne(
                'SELECT name FROM gf_identity_organizations WHERE id = :id',
                ['id' => $foreign],
            ));

            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $context['csrf_token'],
            ], '{"name":"Updated own organization"}');
            self::assertResponseIsSuccessful();
            self::assertSame('Updated own organization', $db->fetchOne(
                'SELECT name FROM gf_identity_organizations WHERE id = :id',
                ['id' => $mine],
            ));

            $db->update('gf_identity_memberships', ['role' => 'editor'], [
                'user_id' => $user,
                'organization_id' => $mine,
            ]);
            $client->request('POST', '/api/admin/organization/name', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $context['csrf_token'],
            ], '{"name":"Denied role"}');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $after = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame('editor', $after['organization']['role']);
            self::assertFalse($after['permissions']['rename_organization']);
            self::assertNull($after['csrf_token']);
        } finally {
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }
}
