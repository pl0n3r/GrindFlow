<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** HTTP ACL, bounded request, CSRF and unavailable-storage tests on disposable MariaDB. */
final class DirectUploadHttpTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testIntentAndCompletionAreTenantBoundAndFailClosedWithoutProvider(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $intent = '/api/admin/vault/direct-upload/intent';
        $complete = '/api/admin/vault/direct-upload/complete';

        foreach ([$intent, $complete] as $endpoint) {
            $client->request('POST', $endpoint);
            self::assertResponseStatusCodeSame(401);
            self::assertSame('authentication_required', $this->errorCode($client));
        }

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Direct upload synthetic actor',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('isolated-direct-upload-only', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);

        try {
            foreach ([$mine => 'Tenant selected', $foreign => 'Foreign tenant'] as $id => $name) {
                $db->insert('gf_identity_organizations', [
                    'id' => $id, 'name' => $name,
                    'slug' => 'ci-'.substr($id, 0, 30),
                    'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
                ]);
            }
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $user, 'organization_id' => $mine,
                'role' => 'editor', 'created_at' => $at, 'updated_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test',
                'password' => 'isolated-direct-upload-only',
            ]));
            self::assertResponseRedirects('/organizations');

            foreach ([$intent, $complete] as $endpoint) {
                $client->request('POST', $endpoint);
                self::assertResponseStatusCodeSame(409);
                self::assertSame('organization_required', $this->errorCode($client));
            }

            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $context = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            $csrf = $context['vault_upload_csrf'];
            self::assertIsString($csrf);
            self::assertSame($mine, $context['organization']['id']);

            $validIntent = ['filename' => 'large-clip.mp4', 'mime_type' => 'video/mp4',
                'byte_size' => 9 * 1024 * 1024];
            $validComplete = ['upload_token' => 'not-a-valid-encrypted-token'];

            foreach ([[$intent, $validIntent], [$complete, $validComplete]] as [$endpoint, $payload]) {
                $this->postJson($client, $endpoint, $payload, 'wrong-csrf');
                self::assertResponseStatusCodeSame(403);
                self::assertSame('invalid_csrf', $this->errorCode($client));
                $this->postJson($client, $endpoint, $payload, $csrf);
                self::assertResponseStatusCodeSame(503);
                self::assertSame('direct_upload_unavailable', $this->errorCode($client));
                self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
                self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            }

            foreach ([
                ['filename' => 'clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => 8 * 1024 * 1024],
                ['filename' => 'clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => 2_147_483_649],
                ['filename' => '../clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => 9 * 1024 * 1024],
                ['filename' => '..', 'mime_type' => 'video/mp4', 'byte_size' => 9 * 1024 * 1024],
                ['filename' => 'clip.mp4', 'mime_type' => 'text/html', 'byte_size' => 9 * 1024 * 1024],
                ['filename' => 'clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => '9000000'],
                $validIntent + ['organization_id' => $foreign],
                $validIntent + ['user_id' => $user],
            ] as $invalid) {
                $this->postJson($client, $intent, $invalid, $csrf);
                self::assertResponseStatusCodeSame(422);
                self::assertSame('invalid_direct_upload', $this->errorCode($client));
            }

            foreach ([[], $validComplete + ['organization_id' => $foreign],
                ['upload_token' => []], ['upload_token' => str_repeat('x', 4097)]] as $invalid) {
                $this->postJson($client, $complete, $invalid, $csrf);
                self::assertResponseStatusCodeSame(422);
                self::assertSame('invalid_upload_token', $this->errorCode($client));
            }
            $client->request('POST', $intent, [], [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf],
                str_repeat('x', 4097));
            self::assertResponseStatusCodeSame(422);

            $db->update('gf_identity_memberships', ['role' => 'model'], [
                'user_id' => $user, 'organization_id' => $mine,
            ]);
            foreach ([[$intent, $validIntent], [$complete, $validComplete]] as [$endpoint, $payload]) {
                $this->postJson($client, $endpoint, $payload, $csrf);
                self::assertResponseStatusCodeSame(403);
                self::assertSame('upload_forbidden', $this->errorCode($client));
            }

            $db->delete('gf_identity_memberships', ['user_id' => $user, 'organization_id' => $mine]);
            $this->postJson($client, $complete, $validComplete, $csrf);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('organization_access_changed', $this->errorCode($client));
        } finally {
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function postJson($client, string $url, array $payload, string $csrf): void
    {
        $client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function errorCode($client): string
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        return (string) $body['error']['code'];
    }
}
