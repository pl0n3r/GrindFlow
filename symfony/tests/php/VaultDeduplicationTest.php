<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class VaultDeduplicationTest extends WebTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testIdenticalBytesOnlyConflictInsideCurrentTenantAndRemainRestorable(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $owned = null;
        $at = gmdate('Y-m-d H:i:s');
        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);
        $root = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/vault';
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            self::fail('Private test storage unavailable.');
        }
        $blobs = static fn (): array => glob($root.'/*.blob') ?: [];

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Duplicate fixture',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('dedup-disposable-only', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        try {
            foreach ([$mine => 'Propio', $foreign => 'Ajeno'] as $id => $name) {
                $db->insert('gf_identity_organizations', [
                    'id' => $id, 'name' => $name, 'slug' => 'dedup-'.substr($id, 0, 30),
                    'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
                ]);
            }
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user,
                'organization_id' => $mine, 'role' => 'editor',
                'created_at' => $at, 'updated_at' => $at,
            ]);
            // Foreign org already stores identical bytes. No cross-tenant dedupe.
            $db->insert('gf_vault_assets', [
                'id' => $foreignAsset, 'organization_id' => $foreign,
                'uploaded_by' => $user, 'original_name' => 'privada-ajena.png',
                'mime_type' => 'image/png', 'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes), 'storage_key' => $foreignAsset,
                'created_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => 'dedup-disposable-only',
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context');
            $context = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            $uploadToken = $context['vault_upload_csrf'];
            $manageToken = $context['vault_manage_csrf'];
            self::assertNotEmpty($uploadToken);
            self::assertNotEmpty($manageToken);

            $upload = static function (string $name) use ($client, $bytes, $uploadToken): void {
                $temp = tempnam(sys_get_temp_dir(), 'gf-dedup-');
                self::assertIsString($temp);
                try {
                    file_put_contents($temp, $bytes);
                    $client->request('POST', '/api/admin/vault', [], [
                        'file' => new UploadedFile($temp, $name, 'image/png', null, true),
                    ], ['HTTP_X_CSRF_TOKEN' => $uploadToken]);
                } finally {
                    @unlink($temp);
                }
            };

            $before = $blobs();
            $upload('primera.png');
            self::assertResponseStatusCodeSame(201, 'A foreign tenant cannot block the initial upload.');
            $owned = json_decode((string) $client->getResponse()->getContent(), true)['data']['asset']['id'];
            $original = $root.'/'.$owned.'.blob';
            self::assertFileExists($original);
            self::assertSame($bytes, file_get_contents($original));
            self::assertCount(count($before) + 1, $blobs());

            $stored = $blobs();
            $upload('mismos-bytes-otro-nombre.png');
            self::assertResponseStatusCodeSame(409);
            $conflict = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame('vault_duplicate_active', $conflict['error']['code']);
            self::assertStringNotContainsString('privada-ajena.png', (string) $client->getResponse()->getContent());
            self::assertSame($stored, $blobs(), 'A rejected duplicate cannot leak a staged blob.');
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_vault_assets WHERE organization_id = ?', [$mine],
            ));

            $client->request('POST', '/api/admin/vault/'.$owned.'/trash',
                server: ['HTTP_X_CSRF_TOKEN' => $manageToken]);
            self::assertResponseIsSuccessful();
            $upload('archivo-que-debo-restaurar.png');
            self::assertResponseStatusCodeSame(409);
            self::assertSame('vault_duplicate_trash',
                json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
            self::assertSame($stored, $blobs(), 'Trash also owns retained bytes.');
            $client->request('GET', '/api/admin/vault?view=trash');
            $trash = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(1, $trash['quota']['used_assets']);
            self::assertSame(strlen($bytes), $trash['quota']['used_bytes']);

            $client->request('POST', '/api/admin/vault/'.$owned.'/restore',
                server: ['HTTP_X_CSRF_TOKEN' => $manageToken]);
            self::assertResponseIsSuccessful();
            self::assertSame($stored, $blobs());
            $client->request('GET', '/api/admin/vault');
            self::assertSame(1, json_decode((string) $client->getResponse()->getContent(), true)['data']['total']);

            $db->update('gf_identity_memberships', ['role' => 'model'], [
                'user_id' => $user, 'organization_id' => $mine,
            ]);
            $upload('sin-permiso.png');
            self::assertResponseStatusCodeSame(403);
            self::assertSame($stored, $blobs());
        } finally {
            $db->delete('gf_vault_assets', ['organization_id' => $mine]);
            $db->delete('gf_vault_assets', ['organization_id' => $foreign]);
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);
            if ($owned !== null) {
                @unlink($root.'/'.$owned.'.blob');
            }
        }
    }
}
