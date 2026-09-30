<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Ops\Security\StaffOpsRequestAuthenticator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class StaffOpsControllerTest extends WebTestCase
{
    private const KEY_ID = 'grindflow-test';
    private const SECRET = 'test-secret-not-production';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testSearchExposesOnlyStaffAndMasksEmail(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $admin = $this->insertUser($db, 'admin', 'staff-search');
        $model = $this->insertUser($db, 'model', 'staff-search');

        try {
            $this->request($client, 'GET', '/ops/staff?q=staff-search');
            self::assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
            self::assertCount(1, $payload['data']['items']);
            self::assertSame($admin['id'], $payload['data']['items'][0]['id']);
            self::assertStringContainsString('***@example.test', $payload['data']['items'][0]['email_masked']);
            self::assertNotSame($admin['email'], $payload['data']['items'][0]['email_masked']);
            self::assertNotSame($model['id'], $payload['data']['items'][0]['id']);
        } finally {
            $this->cleanup($db, [$admin['id'], $model['id']]);
        }
    }

    public function testInviteIsIdempotentAndQueuesServerSideHandoff(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $email = 'ops-invite-'.Uuid::v7()->toRfc4122().'@example.test';
        $body = json_encode(['name' => 'Ops Invite', 'email' => $email, 'role' => 'editor'], JSON_THROW_ON_ERROR);
        $key = 'invite-'.bin2hex(random_bytes(8));
        $id = null;

        try {
            $this->request($client, 'POST', '/ops/staff', $body, $key);
            self::assertResponseStatusCodeSame(201);
            $first = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
            $id = $first['data']['id'];
            self::assertTrue($first['data']['invitation_sent']);
            self::assertSame(1, (int) $db->fetchOne(
                "SELECT COUNT(*) FROM gf_password_recovery_outbox WHERE user_id = ? AND kind = 'reset'",
                [$id],
            ));

            $this->request($client, 'POST', '/ops/staff', $body, $key);
            self::assertResponseStatusCodeSame(201);
            $second = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame($id, $second['data']['id']);
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_identity_users WHERE email = ?', [$email]));
            self::assertSame(1, (int) $db->fetchOne(
                "SELECT COUNT(*) FROM gf_ops_audit WHERE action = 'invite_staff' AND staff_id = ?",
                [$id],
            ));

            $changed = json_encode(['name' => 'Changed', 'email' => $email, 'role' => 'editor'], JSON_THROW_ON_ERROR);
            $this->request($client, 'POST', '/ops/staff', $changed, $key);
            self::assertResponseStatusCodeSame(409);
        } finally {
            if (is_string($id)) {
                $this->cleanup($db, [$id]);
            }
        }
    }

    public function testSuspendInvalidatesSessionGenerationAndRejectsNonStaff(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $admin = $this->insertUser($db, 'admin', 'ops-suspend');
        $model = $this->insertUser($db, 'model', 'ops-suspend');
        $body = json_encode(['reason_code' => 'controlbot_policy'], JSON_THROW_ON_ERROR);

        try {
            $this->request(
                $client,
                'POST',
                '/ops/staff/'.$admin['id'].'/suspend',
                $body,
                'suspend-'.bin2hex(random_bytes(8)),
            );
            self::assertResponseIsSuccessful();
            $row = $db->fetchAssociative(
                'SELECT is_active, session_generation FROM gf_identity_users WHERE id = ?',
                [$admin['id']],
            );
            self::assertSame(0, (int) $row['is_active']);
            self::assertSame(1, (int) $row['session_generation']);

            $this->request(
                $client,
                'POST',
                '/ops/staff/'.$model['id'].'/suspend',
                $body,
                'suspend-'.bin2hex(random_bytes(8)),
            );
            self::assertResponseStatusCodeSame(404);
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT is_active FROM gf_identity_users WHERE id = ?',
                [$model['id']],
            ));
        } finally {
            $this->cleanup($db, [$admin['id'], $model['id']]);
        }
    }

    public function testRoleResetAndSummaryStayInsideStaffScope(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $staff = $this->insertUser($db, 'admin', 'ops-role');
        $model = $this->insertUser($db, 'model', 'ops-role-model');

        try {
            $body = json_encode(['role' => 'editor'], JSON_THROW_ON_ERROR);
            $this->request(
                $client,
                'POST',
                '/ops/staff/'.$staff['id'].'/role',
                $body,
                'role-'.bin2hex(random_bytes(8)),
            );
            self::assertResponseIsSuccessful();
            self::assertSame('editor', $db->fetchOne(
                'SELECT platform_role FROM gf_identity_users WHERE id = ?',
                [$staff['id']],
            ));
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT session_generation FROM gf_identity_users WHERE id = ?',
                [$staff['id']],
            ));

            $this->request(
                $client,
                'POST',
                '/ops/staff/'.$model['id'].'/role',
                json_encode(['role' => 'admin'], JSON_THROW_ON_ERROR),
                'role-'.bin2hex(random_bytes(8)),
            );
            self::assertResponseStatusCodeSame(404);
            self::assertSame('model', $db->fetchOne(
                'SELECT platform_role FROM gf_identity_users WHERE id = ?',
                [$model['id']],
            ));

            $this->request(
                $client,
                'POST',
                '/ops/staff/'.$staff['id'].'/password-reset',
                '',
                'reset-'.bin2hex(random_bytes(8)),
            );
            self::assertResponseStatusCodeSame(202);
            self::assertSame(1, (int) $db->fetchOne(
                "SELECT COUNT(*) FROM gf_password_recovery_outbox WHERE user_id = ? AND kind = 'reset'",
                [$staff['id']],
            ));

            $this->request($client, 'GET', '/ops/summary');
            self::assertResponseIsSuccessful();
            $summary = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('active', $summary['data']);
            self::assertArrayHasKey('suspended', $summary['data']);
            self::assertArrayHasKey('recent_failed_logins', $summary['data']);
        } finally {
            $this->cleanup($db, [$staff['id'], $model['id']]);
        }
    }

    public function testAuthenticationAndIdempotencyRejectionsAreHttpFailClosed(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $nonce = bin2hex(random_bytes(16));

        try {
            $this->request($client, 'GET', '/ops/summary', nonce: $nonce);
            self::assertResponseIsSuccessful();

            $this->request($client, 'GET', '/ops/summary', nonce: $nonce);
            self::assertResponseStatusCodeSame(409);
            $this->assertErrorCode($client, 'replay_detected');

            $this->request(
                $client,
                'GET',
                '/ops/summary',
                serverOverrides: ['HTTP_X_FACTORY_SIGNATURE' => str_repeat('0', 64)],
            );
            self::assertResponseStatusCodeSame(401);
            $this->assertErrorCode($client, 'invalid_signature');

            $this->request(
                $client,
                'GET',
                '/ops/summary',
                serverOverrides: ['REMOTE_ADDR' => '203.0.113.77'],
            );
            self::assertResponseStatusCodeSame(403);
            $this->assertErrorCode($client, 'source_not_allowed');

            $this->request(
                $client,
                'GET',
                'http://localhost/ops/summary',
                serverOverrides: ['HTTPS' => 'off'],
            );
            self::assertResponseStatusCodeSame(404);
            $this->assertErrorCode($client, 'ops_not_found');

            $body = json_encode(
                ['name' => 'Missing Idempotency', 'email' => 'missing-idempotency@example.test', 'role' => 'editor'],
                JSON_THROW_ON_ERROR,
            );
            $this->request($client, 'POST', '/ops/staff', $body);
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->cleanup($db, []);
        }
    }

    private function assertErrorCode(KernelBrowser $client, string $code): void
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($code, $payload['error']['code'] ?? null);
    }

    private function request(
        KernelBrowser $client,
        string $method,
        string $uri,
        string $body = '',
        ?string $idempotencyKey = null,
        array $serverOverrides = [],
        ?string $nonce = null,
    ): void {
        $parts = parse_url($uri);
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');
        $canonicalQuery = StaffOpsRequestAuthenticator::canonicalQuery($query);
        $signedPath = $path.($canonicalQuery === '' ? '' : '?'.$canonicalQuery);
        $timestamp = (string) time();
        $nonce ??= bin2hex(random_bytes(16));
        $canonical = StaffOpsRequestAuthenticator::canonicalRequest(
            self::KEY_ID,
            $method,
            $signedPath,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        );

        $server = [
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FACTORY_KEY_ID' => self::KEY_ID,
            'HTTP_X_FACTORY_TIMESTAMP' => $timestamp,
            'HTTP_X_FACTORY_NONCE' => $nonce,
            'HTTP_X_FACTORY_SIGNATURE' => hash_hmac('sha256', $canonical, self::SECRET),
        ];
        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }
        $server = array_replace($server, $serverOverrides);

        $client->catchExceptions(false);
        $client->request($method, $uri, server: $server, content: $body);
    }

    /** @return array{id:string,email:string} */
    private function insertUser(Connection $db, string $role, string $prefix): array
    {
        $id = Uuid::v7()->toRfc4122();
        $email = $prefix.'-'.$id.'@example.test';
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $id,
            'name' => $prefix.' account',
            'email' => $email,
            'password_hash' => password_hash('synthetic-password-123', PASSWORD_BCRYPT),
            'platform_role' => $role,
            'is_active' => 1,
            'session_generation' => 0,
            'last_access_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'email' => $email];
    }

    /** @param list<string> $ids */
    private function cleanup(Connection $db, array $ids): void
    {
        foreach ($ids as $id) {
            $db->delete('gf_ops_staff_login_failures', ['user_id' => $id]);
            $db->delete('gf_ops_audit', ['staff_id' => $id]);
            $db->delete('gf_password_recovery_outbox', ['user_id' => $id]);
            $db->delete('gf_password_reset_tokens', ['user_id' => $id]);
            $db->delete('gf_identity_users', ['id' => $id]);
        }
        $db->executeStatement("DELETE FROM gf_ops_idempotency WHERE actor_key_id = 'grindflow-test'");
        $db->executeStatement("DELETE FROM gf_ops_nonces WHERE key_id = 'grindflow-test'");
        $db->executeStatement("DELETE FROM gf_ops_rate_limits WHERE key_id = 'grindflow-test'");
    }
}
