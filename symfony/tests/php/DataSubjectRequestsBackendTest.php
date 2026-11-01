<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Synthetic-only regression contract for the isolated DSR backend.
 * No production credentials, real persons or live erasure are used.
 */
final class DataSubjectRequestsBackendTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testLedgerRejectsCrossedStatusValues(): void
    {
        // Database invariant: status must agree with request kind even if a
        // caller bypasses the HTTP boundary. No real accounts are touched.
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $actorRef = hash('sha256', Uuid::v7()->toRfc4122());
        try {
            foreach ([['export', 'partial'], ['deletion', 'pending']] as [$kind, $status]) {
                $db->insert('gf_data_subject_requests', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'actor_ref' => $actorRef,
                    'kind' => $kind,
                    'status' => $status,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            foreach ([['export', 'pending'], ['deletion', 'partial']] as [$kind, $status]) {
                try {
                    $db->insert('gf_data_subject_requests', [
                        'id' => Uuid::v7()->toRfc4122(),
                        'actor_ref' => $actorRef,
                        'kind' => $kind,
                        'status' => $status,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    self::fail(sprintf('Ledger accepted inconsistent %s/%s', $kind, $status));
                } catch (\Doctrine\DBAL\Exception $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
            self::assertSame(2, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_data_subject_requests WHERE actor_ref = :actor',
                ['actor' => $actorRef],
            ));
        } finally {
            $db->delete('gf_data_subject_requests', ['actor_ref' => $actorRef]);
            static::ensureKernelShutdown();
        }
    }

    public function testUnauthenticatedMutationAndSpoofedTargetAreRejected(): void
    {
        $client = static::createClient();
        $export = '/api/admin/privacy/data-subject-requests/export';
        $deletion = '/api/admin/privacy/data-subject-requests/deletion';
        foreach ([$export, $deletion] as $endpoint) {
            $client->request('POST', $endpoint, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
            self::assertResponseStatusCodeSame(401);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        }
        static::ensureKernelShutdown();
    }

    public function testExportRequiresCsrfAndCannotSelectAnotherIdentityOrTenant(): void
    {
        $context = $this->withSyntheticIdentities();
        $client = $context['client'];
        $db = $context['db'];
        try {
            $this->signIn($client, $context['actor'], $context['password']);
            $endpoint = '/api/admin/privacy/data-subject-requests/export';
            $json = ['CONTENT_TYPE' => 'application/json'];
            $payload = json_encode(['confirm' => true], JSON_THROW_ON_ERROR);

            $client->request('POST', $endpoint, [], [], $json, $payload);
            self::assertResponseStatusCodeSame(403);

            $csrf = $this->csrfToken($client);
            $headers = $json + ['HTTP_X_CSRF_TOKEN' => $csrf];
            // A valid CSRF token must not turn a non-JSON or malformed
            // request into an accepted state-changing operation.
            foreach (['text/plain', 'application/x-www-form-urlencoded', 'application/xml'] as $mediaType) {
                $client->request('POST', $endpoint, [], [],
                    ['CONTENT_TYPE' => $mediaType, 'HTTP_X_CSRF_TOKEN' => $csrf], $payload);
                self::assertResponseStatusCodeSame(422, 'Non-JSON Content-Type must be rejected');
            }
            $client->request('POST', $endpoint, [], [], $headers, '{not-json');
            self::assertResponseStatusCodeSame(422, 'Malformed JSON must be rejected');
            $client->request('POST', $endpoint, [], [], $headers,
                json_encode(['confirm' => true, 'padding' => str_repeat('x', 4096)], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'Overlong JSON must be rejected');
            foreach ([
                ['confirm' => true, 'user_id' => $context['other']],
                ['confirm' => true, 'organization_id' => $context['organization']],
                ['confirm' => true, 'role' => 'admin'],
            ] as $spoof) {
                $client->request('POST', $endpoint, [], [], $headers, json_encode($spoof, JSON_THROW_ON_ERROR));
                self::assertResponseStatusCodeSame(422);
            }

            $client->request('POST', $endpoint, [], [], $headers, $payload);
            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
            if ($response instanceof StreamedResponse) {
                ob_start();
                try {
                    $response->sendContent();
                    $body = (string) ob_get_clean();
                } catch (\Throwable $e) {
                    ob_end_clean();
                    throw $e;
                }
            } else {
                $body = (string) $response->getContent();
            }
            self::assertStringContainsString($context['actor'].'@example.test', $body);
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            self::assertSame('identity_only', $decoded['meta']['scope']);
            self::assertFalse($decoded['meta']['complete']);
            self::assertStringNotContainsString($context['other'].'@example.test', $body);
            self::assertStringNotContainsString('password_hash', $body);
            self::assertStringNotContainsString('session_generation', $body);
            self::assertStringNotContainsString($context['organization'], $body);
            self::assertSame(1, (int) $db->fetchOne('SELECT is_active FROM gf_identity_users WHERE id = ?', [$context['actor']]));
            $audits = $db->fetchAllAssociative(
                'SELECT * FROM gf_data_subject_requests WHERE actor_ref = :actor',
                ['actor' => $this->actorRef($context['actor'])],
            );
            self::assertCount(1, $audits);
            self::assertSame('export', $audits[0]['kind']);
            self::assertSame('partial', $audits[0]['status'], 'Identity-only export must not be recorded as complete');
            self::assertArrayNotHasKey('email', $audits[0]);
            self::assertArrayNotHasKey('user_id', $audits[0]);
        } finally {
            $this->cleanSyntheticIdentities($context);
        }
    }

    public function testDeletionWithSharedTenantMustBePendingNotIrreversible(): void
    {
        $context = $this->withSyntheticIdentities();
        $client = $context['client'];
        $db = $context['db'];
        try {
            $this->signIn($client, $context['actor'], $context['password']);
            $csrf = $this->csrfToken($client);
            $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf];
            $endpoint = '/api/admin/privacy/data-subject-requests/deletion';
            $client->request('POST', $endpoint, [], [], $headers, json_encode(['confirm' => false], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            $client->request('POST', $endpoint, [], [], $headers, json_encode([
                'confirm' => true, 'organization_id' => $context['organization'],
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            $client->request('POST', $endpoint, [], [], $headers, json_encode(['confirm' => true], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(202);
            $response = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('pending', $response);
            self::assertStringNotContainsString($context['actor'].'@example.test', $response);
            self::assertStringNotContainsString($context['other'].'@example.test', $response);
            // A shared tenant/unknown ownership always fails closed.
            self::assertSame(1, (int) $db->fetchOne('SELECT is_active FROM gf_identity_users WHERE id = ?', [$context['actor']]));
            self::assertSame(1, (int) $db->fetchOne('SELECT is_active FROM gf_identity_users WHERE id = ?', [$context['other']]));
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_identity_memberships WHERE organization_id = ?', [$context['organization']]));
            $client->request('POST', $endpoint, [], [], $headers, json_encode(['confirm' => true], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(202);
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_data_subject_requests WHERE actor_ref = :actor AND kind = :kind',
                ['actor' => $this->actorRef($context['actor']), 'kind' => 'deletion'],
            ), 'Repeated deletion requests must be idempotent');
        } finally {
            $this->cleanSyntheticIdentities($context);
        }
    }

    /** @return array{client: object,db: Connection,actor: string,other: string,organization: string,password: string} */
    private function withSyntheticIdentities(): array
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $actor = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $password = 'synthetic-dsr-not-production';
        $now = gmdate('Y-m-d H:i:s');
        foreach ([$actor => 'Synthetic own identity', $other => 'Synthetic other identity'] as $id => $name) {
            $db->insert('gf_identity_users', [
                'id' => $id, 'name' => $name, 'email' => $id.'@example.test',
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'platform_role' => 'model', 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $db->insert('gf_identity_organizations', [
            'id' => $organization, 'name' => 'Synthetic shared tenant',
            'slug' => 'dsr-'.substr(str_replace('-', '', $organization), 0, 24),
            'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([$actor, $other] as $userId) {
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $userId,
                'organization_id' => $organization, 'role' => 'model',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return compact('client', 'db', 'actor', 'other', 'organization', 'password');
    }

    private function signIn(object $client, string $actor, string $password): void
    {
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $actor.'@example.test', 'password' => $password,
        ]));
        self::assertResponseRedirects('/organizations');
    }

    private function csrfToken(object $client): string
    {
        $client->request('GET', '/api/admin/privacy/data-subject-requests/csrf');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($body['data']['csrf_token']);
        return $body['data']['csrf_token'];
    }

    private function actorRef(string $actor): string
    {
        $secret = (string) static::getContainer()->getParameter('kernel.secret');

        return hash_hmac('sha256', 'grindflow:dsr:v1:'.$actor, $secret);
    }

    /** @param array{db: Connection,actor: string,other: string,organization: string} $context */
    private function cleanSyntheticIdentities(array $context): void
    {
        $db = $context['db'];
        // Cleanup only synthetic fixtures, never broad deletes or live tables.
        $db->delete('gf_data_subject_requests', ['actor_ref' => $this->actorRef($context['actor'])]);
        $db->delete('gf_identity_memberships', ['organization_id' => $context['organization']]);
        $db->delete('gf_identity_organizations', ['id' => $context['organization']]);
        $db->delete('gf_identity_users', ['id' => $context['actor']]);
        $db->delete('gf_identity_users', ['id' => $context['other']]);
    }
}
