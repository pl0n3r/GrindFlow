<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Http\Controller\VaultController;
use GrindFlow\Infrastructure\Storage\PrivateVaultDirectory;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * GF346: fail-closed upload boundaries with disposable tenants and private blobs.
 * No network, provider configuration, production credentials or personal data.
 */
final class UploadNegativeCasesTest extends WebTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC';
    private const QUICK_MAX = 8 * 1024 * 1024;
    private const DIRECT_INTENT = '/api/admin/vault/direct-upload/intent';
    private const DIRECT_COMPLETE = '/api/admin/vault/direct-upload/complete';

    private KernelBrowser $client;
    private Connection $db;
    private string $actor;
    private string $organization;
    private string $csrf;
    private string $vaultRoot;
    /** @var list<string> */
    private array $temporary = [];
    /** @var list<string> */
    private array $initialBlobs = [];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->catchExceptions(false);
        $this->db = static::getContainer()->get(Connection::class);
        $this->actor = Uuid::v7()->toRfc4122();
        $this->organization = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-upload-negative-only';

        $this->db->insert('gf_identity_users', [
            'id' => $this->actor,
            'name' => 'Synthetic negative upload actor',
            'email' => $this->actor.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('gf_identity_organizations', [
            'id' => $this->organization,
            'name' => 'Disposable upload tenant',
            'slug' => 'ci-'.substr($this->organization, 0, 30),
            'type' => 'independent',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $this->actor,
            'organization_id' => $this->organization,
            'role' => 'editor',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $login = $this->client->request('GET', '/login');
        $this->client->submit($login->filter('form.identity-form')->form([
            'email' => $this->actor.'@example.test',
            'password' => $password,
        ]));
        self::assertResponseRedirects('/organizations');
        $selector = $this->client->request('GET', '/organizations');
        $this->client->submit($selector->filter('.identity-orgs form')->form());
        self::assertResponseRedirects('/admin');

        $this->client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->organization, $context['data']['organization']['id']);
        self::assertIsString($context['data']['vault_upload_csrf']);
        $this->csrf = $context['data']['vault_upload_csrf'];
        self::assertNotSame('', $this->csrf);

        /** @var PrivateVaultDirectory $directory */
        $directory = static::getContainer()->get(PrivateVaultDirectory::class);
        $this->vaultRoot = $directory->root();
        $this->initialBlobs = $this->vaultBlobs();
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->organization)) {
            $this->db->delete('gf_vault_assets', ['organization_id' => $this->organization]);
            $this->db->delete('gf_identity_memberships', ['user_id' => $this->actor]);
            $this->db->delete('gf_identity_organizations', ['id' => $this->organization]);
            $this->db->delete('gf_identity_users', ['id' => $this->actor]);
        }
        if (isset($this->vaultRoot)) {
            foreach (array_diff($this->vaultBlobs(), $this->initialBlobs) as $blob) {
                @unlink($blob);
            }
        }
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testFalseMimeAndMalformedQuickMultipartLeaveNoArtifacts(): void
    {
        $baseline = $this->vaultBlobs();
        $this->quickUpload('fake.png', 'not-a-png', 'image/png');
        $this->assertRejected(422, 'invalid_type', 0, $baseline);

        $this->client->request('POST', '/api/admin/vault', [], [], [
            'HTTP_X_CSRF_TOKEN' => $this->csrf,
        ]);
        $this->assertRejected(422, 'invalid_upload', 0, $baseline);

        $this->quickUpload('legitimate.png', $this->png(), 'image/png', [
            'organization_id' => $this->organization,
        ]);
        $this->assertRejected(422, 'invalid_upload', 0, $baseline);

        $extra = $this->newUpload('second.png', $this->png());
        $file = $this->newUpload('first.png', $this->png());
        $this->client->request('POST', '/api/admin/vault', [], [
            'file' => $file, 'unrequested' => $extra,
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        $this->assertRejected(422, 'invalid_upload', 0, $baseline);
    }

    public function testExecutableNamesAndTraversalAreRejectedBeforeAnyWrite(): void
    {
        $baseline = $this->vaultBlobs();
        foreach (['payload.php.png', 'clip.exe.png', 'script.sh.png', 'batch.bat.png',
            'server.jsp.png', 'tool.ps1.png'] as $filename) {
            $this->quickUpload($filename, $this->png());
            $this->assertRejected(422, 'invalid_filename', 0, $baseline);
        }

        // BrowserKit normalizes multipart filenames before HTTP dispatch.
        // Exercise Symfony's original-path boundary without that normalization.
        $validator = new \ReflectionMethod(VaultController::class, 'hasUnsafeClientFilename');
        $path = $this->temporaryFile($this->png());
        foreach (['../traversal.png', 'folder\\traversal.png'] as $filename) {
            self::assertTrue($validator->invoke(null,
                new UploadedFile($path, $filename, 'image/png', null, true)));
        }
        self::assertFalse($validator->invoke(null,
            new UploadedFile($path, 'safe-photo.png', 'image/png', null, true)));
        $this->assertNoArtifacts(0, $baseline);
    }

    public function testEightMiBIsAllowedButOneByteMoreIsRejectedWithoutOrphans(): void
    {
        $atLimit = str_pad($this->png(), self::QUICK_MAX, "\0");
        self::assertSame(self::QUICK_MAX, strlen($atLimit));
        $beforeAllowed = $this->vaultBlobs();
        $this->quickUpload('limit.png', $atLimit);
        self::assertResponseStatusCodeSame(201);
        $json = $this->responseJson();
        self::assertSame(self::QUICK_MAX, $json['data']['asset']['size_bytes']);
        self::assertSame('image/png', $json['data']['asset']['mime_type']);
        self::assertSame(hash('sha256', $atLimit), $this->db->fetchOne(
            'SELECT sha256 FROM gf_vault_assets WHERE organization_id = ?', [$this->organization],
        ));
        $afterAllowed = $this->vaultBlobs();
        self::assertCount(count($beforeAllowed) + 1, $afterAllowed);
        $this->assertNoArtifacts(1, $afterAllowed);
        $this->quickUpload('too-large.png', $atLimit."\0");
        $this->assertRejected(422, 'invalid_size', 1, $afterAllowed);
        self::assertSame(1, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_assets WHERE organization_id = ?', [$this->organization],
        ));
    }

    public function testTenantQuotaRejectsAnExtraAssetAndUnlinksItsBlob(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        for ($i = 0; $i < 100; ++$i) {
            $id = Uuid::v7()->toRfc4122();
            $this->db->insert('gf_vault_assets', [
                'id' => $id,
                'organization_id' => $this->organization,
                'uploaded_by' => $this->actor,
                'original_name' => 'quota-'.$i.'.png',
                'mime_type' => 'image/png',
                'size_bytes' => 1,
                'sha256' => hash('sha256', $id),
                'storage_key' => $id,
                'created_at' => $now,
            ]);
        }
        $baseline = $this->vaultBlobs();
        $this->quickUpload('quota-extra.png', $this->png());
        $this->assertRejected(409, 'vault_quota_exceeded', 100, $baseline);
    }

    public function testDirectIntentAndCompletionRejectInvalidInputBeforeProvider(): void
    {
        $baseline = $this->vaultBlobs();
        $valid = ['filename' => 'clip.mp4', 'mime_type' => 'video/mp4',
            'byte_size' => self::QUICK_MAX + 1];
        foreach ([
            ['filename' => 'unsafe.exe.mp4', 'mime_type' => 'video/mp4', 'byte_size' => self::QUICK_MAX + 1],
            ['filename' => 'payload.php.png', 'mime_type' => 'image/png', 'byte_size' => self::QUICK_MAX + 1],
            ['filename' => '../clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => self::QUICK_MAX + 1],
            ['filename' => 'folder\\clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => self::QUICK_MAX + 1],
            ['filename' => 'clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => self::QUICK_MAX],
            ['filename' => 'clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => 2_147_483_649],
            ['filename' => 'clip.mp4', 'mime_type' => 'text/html', 'byte_size' => self::QUICK_MAX + 1],
            ['filename' => 'clip.mp4', 'mime_type' => 'video/mp4', 'byte_size' => '9000000'],
            $valid + ['organization_id' => $this->organization],
            $valid + ['user_id' => $this->actor],
            [],
        ] as $payload) {
            $this->postJson(self::DIRECT_INTENT, $payload);
            $this->assertRejected(422, 'invalid_direct_upload', 0, $baseline);
        }

        $this->client->request('POST', self::DIRECT_INTENT, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf,
        ], '{malformed-json');
        $this->assertRejected(422, 'invalid_direct_upload', 0, $baseline);
        foreach ([[], ['upload_token' => ''], ['upload_token' => str_repeat('x', 4097)],
            ['upload_token' => 'synthetic', 'organization_id' => $this->organization]] as $payload) {
            $this->postJson(self::DIRECT_COMPLETE, $payload);
            $this->assertRejected(422, 'invalid_upload_token', 0, $baseline);
        }

        // A valid intent reaches availability, never an actual external provider.
        $this->postJson(self::DIRECT_INTENT, $valid);
        $this->assertRejected(503, 'direct_upload_unavailable', 0, $baseline);
    }

    /** @param array<string, mixed> $request */
    private function postJson(string $endpoint, array $request): void
    {
        $this->client->request('POST', $endpoint, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrf,
        ], json_encode($request, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $fields */
    private function quickUpload(string $name, string $bytes, string $mime = 'image/png', array $fields = []): void
    {
        $this->client->request('POST', '/api/admin/vault', $fields, [
            'file' => $this->newUpload($name, $bytes, $mime),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
    }

    private function newUpload(string $name, string $bytes, string $mime = 'image/png'): UploadedFile
    {
        return new UploadedFile($this->temporaryFile($bytes), $name, $mime, null, true);
    }

    private function temporaryFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gf-negative-');
        self::assertIsString($path);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $this->temporary[] = $path;

        return $path;
    }

    private function png(): string
    {
        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);

        return $bytes;
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        $value = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return $value;
    }

    /** @param list<string> $previousBlobs */
    private function assertRejected(int $status, string $error, int $count, array $previousBlobs): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame($error, $this->responseJson()['error']['code'] ?? null);
        $this->assertNoArtifacts($count, $previousBlobs);
    }

    /** @param list<string> $previousBlobs */
    private function assertNoArtifacts(int $count, array $previousBlobs): void
    {
        self::assertSame($count, (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_assets WHERE organization_id = ?', [$this->organization],
        ));
        self::assertSame($previousBlobs, $this->vaultBlobs(), 'Rejected upload left a private blob.');
    }

    /** @return list<string> */
    private function vaultBlobs(): array
    {
        $paths = glob($this->vaultRoot.'/*.blob') ?: [];
        sort($paths);

        return $paths;
    }
}
