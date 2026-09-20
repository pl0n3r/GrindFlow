<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Disposable MariaDB identities only. No Laravel or Hostinger credentials. */
final class IdentityLoginTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testLoginSelectTenantReauthorizeAndLogout(): void
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
            'name' => 'S1 Test User',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('only-for-isolated-ci', PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        try {
            foreach ([$mine => 'My isolated org', $foreign => 'Unassigned org'] as $id => $name) {
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
                'role' => 'editor',
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/login');

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test',
                'password' => 'only-for-isolated-ci',
            ]));
            self::assertResponseRedirects('/organizations');

            $list = $client->request('GET', '/organizations');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Elige tu organización');
            self::assertSelectorTextContains('.identity-orgs', 'My isolated org');
            self::assertSelectorTextNotContains('body', 'Unassigned org');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/organizations');

            $client->request('POST', '/organizations/select', [
                '_csrf_token' => 'invalid-token',
                'organization_id' => $mine,
            ]);
            self::assertResponseStatusCodeSame(403);

            $selector = $client->request('GET', '/organizations');
            $form = $selector->filter('.identity-orgs form')->form([
                'organization_id' => $foreign,
            ]);
            $client->submit($form);
            self::assertResponseStatusCodeSame(403);

            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'My isolated org');

            $db->delete('gf_identity_memberships', [
                'user_id' => $user,
                'organization_id' => $mine,
            ]);
            $client->request('GET', '/admin');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }

    public function testInactiveUserCannotLogIn(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $id = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $id,
            'name' => 'Inactive synthetic user',
            'email' => $id.'@example.test',
            'password_hash' => password_hash('only-for-isolated-ci', PASSWORD_BCRYPT),
            'platform_role' => 'admin',
            'is_active' => 0,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        try {
            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->filter('form.identity-form')->form([
                'email' => $id.'@example.test',
                'password' => 'only-for-isolated-ci',
            ]));
            self::assertResponseRedirects('/login');
            $client->request('GET', '/organizations');
            self::assertResponseRedirects('/login');
        } finally {
            $db->delete('gf_identity_users', ['id' => $id]);
        }
    }

    public function testAuthenticatedAccountWithoutMembershipSeesHonestEmptyState(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $id = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $id,
            'name' => 'Unassigned user',
            'email' => $id.'@example.test',
            'password_hash' => password_hash('only-for-isolated-ci', PASSWORD_BCRYPT),
            'platform_role' => 'admin',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        try {
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $id.'@example.test',
                'password' => 'only-for-isolated-ci',
            ]));
            self::assertResponseRedirects('/organizations');
            $client->request('GET', '/organizations');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'no tiene organizaciones asignadas');
            $client->request('GET', '/admin');
            self::assertResponseRedirects('/organizations');
        } finally {
            $db->delete('gf_identity_users', ['id' => $id]);
        }
    }
}
