<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/** #1050: negative HTTP media inputs on a disposable tenant; never real storage. */
final class UploadValidationSweepTest extends WebTestCase
{
    private const PASSWORD = 'gf1050-upload-synthetic-only';
    private const QUICK_MAX = 8 * 1024 * 1024;
    private const DIRECT_MAX = 2_147_483_648;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testInvalidQuickAndDirectUploadsCannotPersistAssets(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $temporary = [];

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'GF1050 synthetic upload actor',
            'email' => $user.'@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            $db->insert('gf_identity_organizations', [
                'id' => $organization, 'name' => 'GF1050 synthetic upload tenant',
                'slug' => 'gf1050-upload-'.bin2hex(random_bytes(9)),
                'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user,
                'organization_id' => $organization, 'role' => 'studio',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => self::PASSWORD,
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');
            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $context = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
            $csrf = $context['data']['vault_upload_csrf'] ?? null;
            self::assertIsString($csrf);
            self::assertNotSame('', $csrf);
            self::assertSame(0, $this->countAssets($db, $organization));

            $temporary['html'] = $this->temporaryBytes('<html><script>unsafe</script></html>');
            $temporary['fake-png'] = $this->temporaryBytes("\x89PNG\r\n\x1a\ninvalid-png-shape");
            $temporary['good-png'] = $this->temporaryBytes((string) base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a21cAAAAASUVORK5CYII=',
                true,
            ));
            $temporary['oversized'] = $this->temporaryBytes('invalid');
            $stream = fopen($temporary['oversized'], 'c+b');
            self::assertNotFalse($stream);
            try {
                self::assertTrue(ftruncate($stream, self::QUICK_MAX + 1));
            } finally {
                fclose($stream);
            }

            $cases = [
                ['mime spoofing', 'html', 'picture.png', 'image/png', [], 'invalid_type'],
                ['invalid media signature', 'fake-png', 'picture.png', 'image/png', [], 'invalid_type'],
                ['size above 8 MiB', 'oversized', 'picture.png', 'image/png', [], ['invalid_size', 'invalid_upload']],
                ['unsafe filename', 'good-png', 'shell.php.png', 'image/png', [], 'invalid_filename'],
                ['spoofed tenant', 'good-png', 'picture.png', 'image/png', ['organization_id' => $organization], 'invalid_upload'],
                ['spoofed user', 'good-png', 'picture.png', 'image/png', ['user_id' => $user], 'invalid_upload'],
            ];
            foreach ($cases as [$label, $key, $filename, $mime, $parameters, $error]) {
                $file = new UploadedFile($temporary[$key], $filename, $mime, null, true);
                $client->request('POST', '/api/admin/vault', $parameters, ['file' => $file], [
                    'HTTP_X_CSRF_TOKEN' => $csrf,
                ]);
                $this->assertError($client->getResponse()->getStatusCode(),
                    (string) $client->getResponse()->getContent(), 422, $error, $label);
                self::assertFileExists($temporary[$key], $label.' moved a rejected upload');
                self::assertSame(0, $this->countAssets($db, $organization), $label.' inserted a vault asset');
            }

            // A valid PNG must be stopped by CSRF before any media read or write.
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($temporary['good-png'], 'picture.png', 'image/png', null, true),
            ]);
            $this->assertError($client->getResponse()->getStatusCode(),
                (string) $client->getResponse()->getContent(), 403, 'invalid_csrf', 'missing CSRF');
            self::assertFileExists($temporary['good-png']);
            $client->request('POST', '/api/admin/vault', [], [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            $this->assertError($client->getResponse()->getStatusCode(),
                (string) $client->getResponse()->getContent(), 422, 'invalid_upload', 'missing multipart file');

            $intent = '/api/admin/vault/direct-upload/intent';
            $valid = ['filename' => 'synthetic.mp4', 'mime_type' => 'video/mp4', 'byte_size' => self::QUICK_MAX + 1];
            $this->postJson($client, $intent, $valid, 'gf1050-invalid-csrf');
            $this->assertError($client->getResponse()->getStatusCode(),
                (string) $client->getResponse()->getContent(), 403, 'invalid_csrf', 'forged direct intent CSRF');
            foreach ([
                ['mime_type' => 'text/html'] + array_diff_key($valid, ['mime_type' => true]),
                ['byte_size' => self::DIRECT_MAX + 1] + array_diff_key($valid, ['byte_size' => true]),
                ['byte_size' => (string) (self::QUICK_MAX + 1)] + array_diff_key($valid, ['byte_size' => true]),
                ['filename' => '../synthetic.mp4'] + array_diff_key($valid, ['filename' => true]),
                $valid + ['user_id' => $user],
                ['filename' => 'synthetic.mp4', 'mime_type' => 'video/mp4'],
            ] as $invalid) {
                $this->postJson($client, $intent, $invalid, $csrf);
                $this->assertError($client->getResponse()->getStatusCode(),
                    (string) $client->getResponse()->getContent(), 422, 'invalid_direct_upload', 'invalid direct intent');
            }

            foreach ([[], ['upload_token' => []], ['upload_token' => str_repeat('x', 4097)],
                ['upload_token' => 'invalid', 'organization_id' => $organization]] as $invalid) {
                $this->postJson($client, '/api/admin/vault/direct-upload/complete', $invalid, $csrf);
                $this->assertError($client->getResponse()->getStatusCode(),
                    (string) $client->getResponse()->getContent(), 422, 'invalid_upload_token', 'invalid completion');
            }
            self::assertSame(0, $this->countAssets($db, $organization),
                'Malformed direct-upload metadata or completion wrote an asset');
        } finally {
            foreach ($temporary as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }

    private function countAssets(Connection $db, string $organization): int
    {
        return (int) $db->fetchOne('SELECT COUNT(*) FROM gf_vault_assets WHERE organization_id = ?', [$organization]);
    }

    private function temporaryBytes(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gf1050-');
        if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Could not create synthetic upload fixture.');
        }
        return $path;
    }

    /** @param array<string,mixed> $payload */
    private function postJson($client, string $url, array $payload, string $csrf): void
    {
        $client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** PHP's upload_max_filesize can reject before the app's 8 MiB guard. */
    private function assertError(int $status, string $content, int $expected, string|array $code, string $case): void
    {
        self::assertSame($expected, $status, $case);
        $body = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        $actual = $body['error']['code'] ?? null;
        if (is_array($code)) {
            self::assertContains($actual, $code, $case);
        } else {
            self::assertSame($code, $actual, $case);
        }
    }
}
