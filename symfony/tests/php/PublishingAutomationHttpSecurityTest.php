<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Real HTTP guardrails exercised with disposable MariaDB fixtures only.
 * Paused rules never contact any external distribution provider.
 */
final class PublishingAutomationHttpSecurityTest extends WebTestCase
{
    private const ENDPOINT = '/api/admin/rules/automation';
    private const PASSWORD = 'gf594-disposable-fixture-password';
    private const JSON_HEADERS = ['CONTENT_TYPE' => 'application/json'];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testUnauthorizedCsrfAndCrossTenantRequestsFailClosed(): void
    {
        $client = static::createClient();
        $body = json_encode(self::pausedInput(), JSON_THROW_ON_ERROR);

        $client->request('GET', self::ENDPOINT);
        self::assertResponseStatusCodeSame(401);
        $client->request('PUT', self::ENDPOINT, [], [], self::JSON_HEADERS, $body);
        self::assertResponseStatusCodeSame(401);

        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $ids = self::seedSyntheticOrganizations($db);

        // A valid login does not grant the model role write permission.
        self::signInAndSelectOwnOrganization($client, $ids['model_email']);
        $client->request('GET', self::ENDPOINT);
        self::assertResponseIsSuccessful();
        self::assertSame('paused', self::responseJson($client)['data']['rule']['state']);
        $client->request('PUT', self::ENDPOINT, [], [], self::JSON_HEADERS, $body);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('automation_forbidden', self::responseJson($client)['error']['code']);

        $client->restart();
        self::signInAndSelectOwnOrganization($client, $ids['admin_email']);

        // A privileged role still needs the exact server-generated CSRF token.
        $client->request('PUT', self::ENDPOINT, [], [], self::JSON_HEADERS, $body);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf', self::responseJson($client)['error']['code']);
        $client->request('PUT', self::ENDPOINT, [], [], self::JSON_HEADERS + [
            'HTTP_X_CSRF_TOKEN' => 'tampered-fixture-token',
        ], $body);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf', self::responseJson($client)['error']['code']);

        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $csrf = self::responseJson($client)['data']['weekly_rule_csrf'];
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);

        // Simulate an attacker tampering with the selected-organization session.
        // The server MUST recheck membership, not trust a session organization ID.
        $session = $client->getRequest()->getSession();
        $session->set('grindflow_organization_id', $ids['foreign_org']);
        $session->save();
        $client->request('GET', self::ENDPOINT);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('organization_access_changed', self::responseJson($client)['error']['code']);
        $client->request('PUT', self::ENDPOINT, [], [], self::JSON_HEADERS + [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], $body);
        self::assertContains($client->getResponse()->getStatusCode(), [403, 409]);

        self::assertSame('["09:00"]', $db->fetchOne(
            'SELECT automation_local_times FROM gf_content_rules WHERE organization_id = :org',
            ['org' => $ids['own_org']],
        ));
        self::assertSame('["09:00"]', $db->fetchOne(
            'SELECT automation_local_times FROM gf_content_rules WHERE organization_id = :org',
            ['org' => $ids['foreign_org']],
        ));
    }

    public function testAuthorizedReadAndPausedRuleNeverTriggerProviderPublication(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $ids = self::seedSyntheticOrganizations($db);
        self::signInAndSelectOwnOrganization($client, $ids['admin_email']);

        $client->request('GET', self::ENDPOINT);
        self::assertResponseIsSuccessful();
        $get = self::responseJson($client);
        self::assertSame('paused', $get['data']['rule']['state']);
        self::assertSame(['09:00'], $get['data']['rule']['local_times']);
        self::assertFalse($get['data']['provider_calls']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $csrf = self::responseJson($client)['data']['weekly_rule_csrf'];
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);

        $before = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id = :org',
            ['org' => $ids['own_org']],
        );
        $client->request('PUT', self::ENDPOINT, [], [], self::JSON_HEADERS + [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode(self::pausedInput(), JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $updated = self::responseJson($client);
        self::assertSame('paused', $updated['data']['rule']['state']);
        self::assertSame(['10:00'], $updated['data']['rule']['local_times']);
        self::assertFalse($updated['data']['provider_calls']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('paused', $db->fetchOne(
            'SELECT automation_state FROM gf_content_rules WHERE organization_id = :org',
            ['org' => $ids['own_org']],
        ));
        self::assertSame('["10:00"]', $db->fetchOne(
            'SELECT automation_local_times FROM gf_content_rules WHERE organization_id = :org',
            ['org' => $ids['own_org']],
        ));
        self::assertSame('["09:00"]', $db->fetchOne(
            'SELECT automation_local_times FROM gf_content_rules WHERE organization_id = :org',
            ['org' => $ids['foreign_org']],
        ));
        self::assertSame($before, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id = :org',
            ['org' => $ids['own_org']],
        ));
    }

    /** @return array{state:string,source:string,local_times:list<string>,destination_id:null} */
    private static function pausedInput(): array
    {
        return [
            'state' => 'paused',
            'source' => 'eligible_assets',
            'local_times' => ['10:00'],
            'destination_id' => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function responseJson(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private static function signInAndSelectOwnOrganization(KernelBrowser $client, string $email): void
    {
        $page = $client->request('GET', '/login');
        $client->submit($page->filter('form.identity-form')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects('/organizations');

        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());
        self::assertResponseRedirects('/admin');
    }

    /** @return array{admin_email:string,model_email:string,own_org:string,foreign_org:string} */
    private static function seedSyntheticOrganizations(Connection $db): array
    {
        $admin = Uuid::v7()->toRfc4122();
        $model = Uuid::v7()->toRfc4122();
        $own = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');

        foreach ([$admin => 'GF594 admin', $model => 'GF594 viewer'] as $id => $name) {
            $db->insert('gf_identity_users', [
                'id' => $id,
                'name' => $name,
                'email' => $id.'@example.test',
                'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                'platform_role' => 'model',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([$own => 'own', $foreign => 'foreign'] as $id => $label) {
            $db->insert('gf_identity_organizations', [
                'id' => $id,
                'name' => 'GF594 '.$label,
                'slug' => 'gf594-'.$label.'-'.substr($id, 0, 20),
                'type' => 'independent',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->insert('gf_content_rules', [
                'organization_id' => $id,
                'timezone' => 'UTC',
                'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
                'local_time' => '09:00',
                'max_per_day' => 2,
                'mode' => 'review_only',
                'updated_by' => $admin,
                'created_at' => $now,
                'updated_at' => $now,
                'automation_state' => 'paused',
                'automation_local_times' => '["09:00"]',
                'automation_destination_id' => null,
            ]);
        }
        foreach ([$admin => 'admin', $model => 'model'] as $id => $role) {
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $id,
                'organization_id' => $own,
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'admin_email' => $admin.'@example.test',
            'model_email' => $model.'@example.test',
            'own_org' => $own,
            'foreign_org' => $foreign,
        ];
    }
}
