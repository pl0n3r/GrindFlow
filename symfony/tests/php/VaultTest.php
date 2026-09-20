<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/** Real storage/DB assertions on synthetic actors and disposable MariaDB only. */
final class VaultTest extends WebTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testPrivatePhotoUploadListDownloadAndCrossTenantGuards(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $mineAsset = null;
        $temp = [];

        $client->request('GET', '/api/admin/vault');
        self::assertResponseStatusCodeSame(401);
        $client->request('POST', '/api/admin/vault');
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Vault synthetic actor',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('isolated-vault-only', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);

        try {
            foreach ([$mine => 'Biblioteca propia', $foreign => 'Biblioteca ajena'] as $id => $name) {
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
            $db->insert('gf_vault_assets', [
                'id' => $foreignAsset, 'organization_id' => $foreign,
                'uploaded_by' => $user, 'original_name' => 'ajena.png',
                'mime_type' => 'image/png', 'size_bytes' => 69,
                'sha256' => str_repeat('a', 64), 'storage_key' => $foreignAsset,
                'created_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => 'isolated-vault-only',
            ]));
            self::assertResponseRedirects('/organizations');

            $client->request('GET', '/api/admin/vault');
            self::assertResponseStatusCodeSame(409);

            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/vault');
            self::assertResponseIsSuccessful();
            self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['data']['assets']);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $client->request('GET', '/api/admin/context');
            $context = json_decode((string) $client->getResponse()->getContent(), true);
            $token = $context['data']['vault_upload_csrf'];
            self::assertNotEmpty($token);

            $tmp = tempnam(sys_get_temp_dir(), 'gf-vault-');
            $temp[] = $tmp;
            file_put_contents($tmp, base64_decode(self::PNG, true));
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($tmp, 'invalid.png', 'image/png', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => 'not-a-csrf-token']);
            self::assertResponseStatusCodeSame(403);

            $tmp = tempnam(sys_get_temp_dir(), 'gf-vault-');
            $temp[] = $tmp;
            file_put_contents($tmp, base64_decode(self::PNG, true));
            $client->request('POST', '/api/admin/vault', ['organization_id' => $foreign], [
                'file' => new UploadedFile($tmp, 'invalid.png', 'image/png', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(422);

            $tmp = tempnam(sys_get_temp_dir(), 'gf-vault-');
            $temp[] = $tmp;
            file_put_contents($tmp, 'not-an-image');
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($tmp, 'fake.png', 'image/png', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(422);

            $tmp = tempnam(sys_get_temp_dir(), 'gf-vault-');
            $temp[] = $tmp;
            $bytes = base64_decode(self::PNG, true);
            file_put_contents($tmp, $bytes);
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($tmp, 'imagen-prueba.png', 'image/png', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(201);
            $result = json_decode((string) $client->getResponse()->getContent(), true);
            $mineAsset = $result['data']['asset']['id'];
            self::assertSame('imagen-prueba.png', $result['data']['asset']['name']);
            self::assertSame('image/png', $result['data']['asset']['mime_type']);
            self::assertSame(strlen($bytes), $result['data']['asset']['size_bytes']);
            self::assertArrayNotHasKey('storage_key', $result['data']['asset']);
            self::assertSame(hash('sha256', $bytes), $db->fetchOne(
                'SELECT sha256 FROM gf_vault_assets WHERE id = ? AND organization_id = ?', [$mineAsset, $mine],
            ));

            $client->request('GET', '/api/admin/vault');
            self::assertResponseIsSuccessful();
            $list = json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'];
            self::assertCount(1, $list);
            self::assertSame($mineAsset, $list[0]['id']);
            self::assertStringNotContainsString('ajena.png', (string) $client->getResponse()->getContent());

            // More than one page with identical timestamps must not truncate
            // the organization catalog or include the foreign asset.
            for ($i = 0; $i < 30; ++$i) {
                $id = Uuid::v7()->toRfc4122();
                $db->insert('gf_vault_assets', [
                    'id' => $id, 'organization_id' => $mine, 'uploaded_by' => $user,
                    'original_name' => 'pagina-'.$i.'.png', 'mime_type' => 'image/png',
                    'size_bytes' => 69, 'sha256' => str_repeat('b', 64),
                    'storage_key' => $id, 'created_at' => $at,
                ]);
            }
            $client->request('GET', '/api/admin/vault?page=1');
            self::assertResponseIsSuccessful();
            $first = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(31, $first['total']);
            self::assertSame(2, $first['pages']);
            self::assertSame(1, $first['page']);
            self::assertCount(30, $first['assets']);
            $client->request('GET', '/api/admin/vault?page=2');
            self::assertResponseIsSuccessful();
            $second = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(31, $second['total']);
            self::assertSame(2, $second['page']);
            self::assertCount(1, $second['assets']);
            self::assertCount(31, array_unique(array_merge(
                array_column($first['assets'], 'id'), array_column($second['assets'], 'id'),
            )));
            self::assertNotContains($foreignAsset, array_merge(
                array_column($first['assets'], 'id'), array_column($second['assets'], 'id'),
            ));
            $client->request('GET', '/api/admin/vault?page=3');
            self::assertResponseIsSuccessful();
            self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['data']['assets']);
            foreach (['0', '-1', '1001', '1 OR 1=1', '1.5'] as $invalidPage) {
                $client->request('GET', '/api/admin/vault?page='.rawurlencode($invalidPage));
                self::assertResponseStatusCodeSame(422);
            }

            $client->request('GET', '/api/admin/vault?page[]=1');
            self::assertResponseStatusCodeSame(422);

            $client->request('GET', '/api/admin/vault/'.$foreignAsset);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('file_not_found', json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
            $client->request('GET', '/api/admin/vault/'.$mineAsset);
            self::assertResponseIsSuccessful();
            $detail = json_decode((string) $client->getResponse()->getContent(), true)['data']['asset'];
            self::assertSame($mineAsset, $detail['id']);
            self::assertSame('imagen-prueba.png', $detail['name']);
            self::assertArrayNotHasKey('storage_key', $detail);
            self::assertArrayNotHasKey('sha256', $detail);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $client->request('GET', '/api/admin/vault/'.$foreignAsset.'/download');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/api/admin/vault/'.$mineAsset.'/download');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

            $db->update('gf_identity_memberships', ['role' => 'model'], [
                'user_id' => $user, 'organization_id' => $mine,
            ]);
            $client->request('GET', '/api/admin/context');
            $modelContext = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertNull($modelContext['data']['vault_upload_csrf']);
            $client->request('POST', '/api/admin/vault', [], [], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/api/admin/vault');
            self::assertResponseIsSuccessful();

            $db->delete('gf_identity_memberships', [
                'user_id' => $user, 'organization_id' => $mine,
            ]);
            $client->request('GET', '/api/admin/vault/'.$mineAsset);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/api/admin/vault');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $db->delete('gf_vault_assets', ['organization_id' => $mine]);
            $db->delete('gf_vault_assets', ['organization_id' => $foreign]);
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);
            if ($mineAsset !== null) {
                @unlink((string) static::getContainer()->getParameter('kernel.project_dir').'/var/vault/'.$mineAsset.'.blob');
            }
            foreach ($temp as $path) {
                @unlink($path);
            }
        }
    }
}
