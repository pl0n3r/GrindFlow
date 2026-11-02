<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #799 consumer contract: synthetic-only integration with the #814 backend.
 * No live deletion, production credentials, external services or real users.
 * Requires #814 migration and controller to be available in the test runtime.
 */
final class DataSubjectRequestsTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function test_criterio_principal(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $actor = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $password = 'synthetic-only-dsr-799';
        $now = gmdate('Y-m-d H:i:s');
        $headers = ['CONTENT_TYPE' => 'application/json'];
        $base = '/api/admin/privacy/data-subject-requests';
        $confirm = json_encode(['confirm' => true], JSON_THROW_ON_ERROR);

        // Authentication is checked before parsing or processing any payload.
        foreach (['export', 'deletion'] as $action) {
            $client->request('POST', $base.'/'.$action, [], [], $headers, $confirm);
            self::assertResponseStatusCodeSame(401);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        }

        try {
            foreach ([$actor => 'Synthetic account', $other => 'Unrelated account'] as $id => $name) {
                $db->insert('gf_identity_users', [
                    'id' => $id,
                    'name' => $name,
                    'email' => $id.'@example.test',
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'platform_role' => 'model',
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $db->insert('gf_identity_organizations', [
                'id' => $organization,
                'name' => 'Shared synthetic tenant',
                'slug' => 'dsr799-'.substr(str_replace('-', '', $organization), 0, 22),
                'type' => 'independent',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ([$actor, $other] as $member) {
                $db->insert('gf_identity_memberships', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'user_id' => $member,
                    'organization_id' => $organization,
                    'role' => 'model',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Authenticate only the actor. Membership grants no export rights
            // over other members' records or tenant-owned content.
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $actor.'@example.test', 'password' => $password,
            ]));
            self::assertResponseRedirects('/organizations');

            $client->request('POST', $base.'/export', [], [], $headers, $confirm);
            self::assertResponseStatusCodeSame(403, 'CSRF must be required for export');

            $client->request('GET', $base.'/csrf');
            self::assertResponseIsSuccessful();
            $csrfData = json_decode((string) $client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($csrfData['data']['csrf_token']);
            $csrfHeaders = $headers + ['HTTP_X_CSRF_TOKEN' => (string) $csrfData['data']['csrf_token']];

            foreach (['export', 'deletion'] as $action) {
                $client->request('POST', $base.'/'.$action, [], [], $csrfHeaders,
                    json_encode(['confirm' => true, 'user_id' => $other], JSON_THROW_ON_ERROR));
                self::assertResponseStatusCodeSame(422, 'Actor ID must not be supplied by client');
                $client->request('POST', $base.'/'.$action, [], [], $csrfHeaders,
                    json_encode(['confirm' => true, 'organization_id' => $organization], JSON_THROW_ON_ERROR));
                self::assertResponseStatusCodeSame(422, 'Tenant ID must not be supplied by client');
            }

            $client->request('POST', $base.'/export', [], [], $csrfHeaders, $confirm);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
            $export = json_decode((string) $client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame($actor, $export['data']['user']['id']);
            self::assertSame($actor.'@example.test', $export['data']['user']['email']);
            self::assertStringNotContainsString($other, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($organization, (string) $client->getResponse()->getContent());
            self::assertArrayNotHasKey('password_hash', $export['data']['user']);

            // The request records the desire to erase but never deletes data
            // when shared ownership or legal retention remain unresolved.
            $client->request('POST', $base.'/deletion', [], [], $csrfHeaders,
                json_encode(['confirm' => false], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            $client->request('POST', $base.'/deletion', [], [], $csrfHeaders, $confirm);
            self::assertResponseStatusCodeSame(202);
            self::assertSame('pending', json_decode((string) $client->getResponse()->getContent(), true)['data']['status']);
            $client->request('POST', $base.'/deletion', [], [], $csrfHeaders, $confirm);
            self::assertResponseStatusCodeSame(202);

            $auditRef = hash_hmac('sha256', 'grindflow:dsr:v1:'.$actor,
                (string) static::getContainer()->getParameter('kernel.secret'));
            self::assertSame(1, (int) $db->fetchOne(
                "SELECT COUNT(*) FROM gf_data_subject_requests WHERE actor_ref = :ref AND kind = 'deletion' AND status = 'pending'",
                ['ref' => $auditRef],
            ));
            self::assertSame(1, (int) $db->fetchOne(
                "SELECT COUNT(*) FROM gf_data_subject_requests WHERE actor_ref = :ref AND kind = 'export' AND status = 'partial'",
                ['ref' => $auditRef],
            ));
            self::assertSame(2, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_identity_memberships WHERE organization_id = :org',
                ['org' => $organization],
            ));
            foreach ([$actor, $other] as $id) {
                self::assertSame(1, (int) $db->fetchOne(
                    'SELECT is_active FROM gf_identity_users WHERE id = :id', ['id' => $id],
                ));
            }
        } finally {
            // Only synthetic fixture rows; no broad/deployment operations.
            $auditRef = hash_hmac('sha256', 'grindflow:dsr:v1:'.$actor,
                (string) static::getContainer()->getParameter('kernel.secret'));
            $db->delete('gf_data_subject_requests', ['actor_ref' => $auditRef]);
            $db->delete('gf_identity_memberships', ['organization_id' => $organization]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
            foreach ([$actor, $other] as $id) {
                $db->delete('gf_identity_users', ['id' => $id]);
            }
        }
    }
}
