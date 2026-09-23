<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Http\Controller\DirectUploadController;
use GrindFlow\Infrastructure\Storage\DirectUploadCompletionVerifier;
use GrindFlow\Infrastructure\Storage\DirectUploadIntentIssuer;
use GrindFlow\Infrastructure\Storage\DirectUploadObjectKeys;
use GrindFlow\Infrastructure\Storage\DirectUploadReadiness;
use GrindFlow\Infrastructure\Storage\DirectUploadStorage;
use GrindFlow\Infrastructure\Storage\DirectUploadTokenCipher;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Synthetic HTTP contract with a disposable object-store adapter, never real provider I/O. */
final class DirectUploadConfiguredHttpTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testStagingIsVerifiedButNotRegisteredAndRevocationDuringStreamIsDenied(): void
    {
        $client = static::createClient();
        // Keep the disposable controller binding across BrowserKit requests.
        $client->disableReboot();
        // Surface the underlying synthetic failure instead of hiding it behind a 500 page.
        $client->catchExceptions(false);
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $size = 9 * 1024 * 1024;

        $storage = new class($db, $user, $organization, $size) implements DirectUploadStorage {
            public string $stagingKey = '';
            public int $readCalls = 0;
            public bool $revokeOnRead = false;
            private readonly string $bytes;

            public function __construct(
                private readonly Connection $db,
                private readonly string $user,
                private readonly string $organization,
                int $size,
            ) {
                $this->bytes = str_repeat('x', $size);
            }

            public function available(): bool { return true; }
            public function disk(): string { return 'media'; }
            public function driver(): string { return 'disposable'; }

            public function temporaryUpload(string $storageKey, string $mimeType, int $byteSize, int $expiresAt): array
            {
                $this->stagingKey = $storageKey;

                return ['url' => 'https://objects.example.invalid/temporary',
                    'headers' => ['Content-Type' => $mimeType]];
            }

            public function exists(string $storageKey): bool
            {
                return $storageKey === $this->stagingKey;
            }

            public function size(string $storageKey): ?int
            {
                return $this->exists($storageKey) ? strlen($this->bytes) : null;
            }

            public function readStream(string $storageKey)
            {
                if (!$this->exists($storageKey)) {
                    return null;
                }
                $this->readCalls++;
                if ($this->revokeOnRead) {
                    $this->db->delete('gf_identity_memberships', [
                        'user_id' => $this->user, 'organization_id' => $this->organization,
                    ]);
                }

                $stream = fopen('php://temp', 'w+b');
                if ($stream === false) {
                    return null;
                }
                fwrite($stream, $this->bytes);
                rewind($stream);

                return $stream;
            }

            public function delete(string $storageKey): void
            {
                throw new \LogicException('Staging-only completion must not delete objects.');
            }

            public function promote(string $stagingKey, string $finalKey): void
            {
                throw new \LogicException('Staging-only completion must not promote objects.');
            }
        };

        $tokens = new DirectUploadTokenCipher(str_repeat('test-secret-', 4));
        $keys = new DirectUploadObjectKeys();
        static::getContainer()->set(DirectUploadController::class, new DirectUploadController(
            new DirectUploadReadiness($storage, $tokens),
            new DirectUploadIntentIssuer($storage, $tokens, $keys),
            new DirectUploadCompletionVerifier($storage, $tokens, $keys),
        ));

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Configured upload synthetic actor',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('isolated-configured-upload-only', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            $db->insert('gf_identity_organizations', [
                'id' => $organization, 'name' => 'Configured upload synthetic tenant',
                'slug' => 'ci-'.substr($organization, 0, 30),
                'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $user, 'organization_id' => $organization,
                'role' => 'editor', 'created_at' => $now, 'updated_at' => $now,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test',
                'password' => 'isolated-configured-upload-only',
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $csrf = json_decode((string) $client->getResponse()->getContent(), true)['data']['vault_upload_csrf'];
            self::assertIsString($csrf);

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->postJson($client, '/api/admin/vault/direct-upload/intent', [
                    'filename' => 'synthetic.mp4',
                    'mime_type' => 'video/mp4',
                    'byte_size' => $size,
                ], $csrf);
                self::assertResponseStatusCodeSame(201);
                $intent = json_decode((string) $client->getResponse()->getContent(), true)['data'];
                self::assertSame('https://objects.example.invalid/temporary', $intent['url']);
                self::assertSame(['Content-Type' => 'video/mp4'], $intent['headers']);
                self::assertIsString($intent['upload_token']);
                self::assertStringContainsString($organization.'/staging/', $storage->stagingKey);

                $storage->revokeOnRead = ($attempt === 1);
                $this->postJson($client, '/api/admin/vault/direct-upload/complete', [
                    'upload_token' => $intent['upload_token'],
                ], $csrf);

                if ($attempt === 1) {
                    self::assertResponseStatusCodeSame(403);
                    self::assertSame('organization_access_changed',
                        json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);

                    continue;
                }

                self::assertResponseIsSuccessful();
                $verified = json_decode((string) $client->getResponse()->getContent(), true)['data'];
                self::assertSame('verified_staging_only', $verified['status']);
                self::assertFalse($verified['registered']);
                self::assertSame(hash('sha256', str_repeat('x', $size)), $verified['sha256']);
                self::assertSame($size, $verified['size_bytes']);
                self::assertSame('video/mp4', $verified['mime_type_declared']);
                self::assertStringContainsString('no-store',
                    (string) $client->getResponse()->headers->get('Cache-Control'));
                self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            }
            self::assertSame(2, $storage->readCalls);
        } finally {
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
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
}
