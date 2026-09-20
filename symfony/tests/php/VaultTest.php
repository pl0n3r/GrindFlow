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
            $emptyQuota = json_decode((string) $client->getResponse()->getContent(), true)['data']['quota'];
            self::assertSame(0, $emptyQuota['used_assets']);
            self::assertSame(0, $emptyQuota['used_bytes']);
            self::assertSame(100, $emptyQuota['max_assets']);
            self::assertSame(128 * 1024 * 1024, $emptyQuota['max_bytes']);
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
            self::assertSame(31, $first['quota']['used_assets']);
            self::assertSame(strlen($bytes) + 30 * 69, $first['quota']['used_bytes']);
            self::assertSame(128 * 1024 * 1024, $first['quota']['max_bytes']);
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
            // Filename search is tenant-scoped, shares count/page filters and never changes physical quota.
            $client->request('GET', '/api/admin/vault?q=imagen-prueba&page=1');
            self::assertResponseIsSuccessful();
            $found = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(1, $found['total']);
            self::assertSame(1, $found['pages']);
            self::assertSame($mineAsset, $found['assets'][0]['id']);
            self::assertSame(31, $found['quota']['used_assets']);
            self::assertSame(strlen($bytes) + 30 * 69, $found['quota']['used_bytes']);
            $client->request('GET', '/api/admin/vault?q=pagina-');
            self::assertResponseIsSuccessful();
            self::assertSame(30, json_decode((string) $client->getResponse()->getContent(), true)['data']['total']);
            $client->request('GET', '/api/admin/vault?view=trash&q=pagina-');
            self::assertSame(0, json_decode((string) $client->getResponse()->getContent(), true)['data']['total']);
            foreach (['%', '_', '!', 'ajena', 'no-existe'] as $literal) {
                $client->request('GET', '/api/admin/vault?q='.rawurlencode($literal));
                self::assertResponseIsSuccessful();
                self::assertSame(0, json_decode((string) $client->getResponse()->getContent(), true)['data']['total']);
            }
            $client->request('GET', '/api/admin/vault?q[]=archivo');
            self::assertResponseStatusCodeSame(422);
            $client->request('GET', '/api/admin/vault?q='.str_repeat('x', 81));
            self::assertResponseStatusCodeSame(422);
            $client->request('GET', '/api/admin/vault?q='.rawurlencode("a\u{200B}b"));
            self::assertResponseStatusCodeSame(422);

            // Format + sort use the same tenant-scoped SQL and pagination as name search.
            $db->update('gf_vault_assets', ['mime_type' => 'image/jpeg'],
                ['organization_id' => $mine, 'original_name' => 'pagina-0.png']);
            $db->update('gf_vault_assets', ['mime_type' => 'image/webp'],
                ['organization_id' => $mine, 'original_name' => 'pagina-1.png']);
            $client->request('GET', '/api/admin/vault?format=jpeg');
            self::assertResponseIsSuccessful();
            $jpeg = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame('jpeg', $jpeg['format']);
            self::assertSame('recent', $jpeg['sort']);
            self::assertSame(1, $jpeg['total']);
            self::assertSame('pagina-0.png', $jpeg['assets'][0]['name']);
            self::assertSame(31, $jpeg['quota']['used_assets']);
            self::assertSame(strlen($bytes) + 30 * 69, $jpeg['quota']['used_bytes']);

            $client->request('GET', '/api/admin/vault?view=trash&format=webp&sort=name_asc');
            self::assertResponseIsSuccessful();
            $emptyTrash = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(0, $emptyTrash['total']);
            self::assertSame(31, $emptyTrash['quota']['used_assets']);
            self::assertSame('name_asc', $emptyTrash['sort']);
            $client->request('GET', '/api/admin/vault?q=pagina-&format=png&sort=name_asc');
            self::assertResponseIsSuccessful();
            $png = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(28, $png['total']);
            self::assertSame('pagina-10.png', $png['assets'][0]['name']);

            $client->request('GET', '/api/admin/vault?sort=name_asc&page=1');
            self::assertResponseIsSuccessful();
            $alphaFirst = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame('imagen-prueba.png', $alphaFirst['assets'][0]['name']);
            self::assertSame(31, $alphaFirst['total']);
            self::assertCount(30, $alphaFirst['assets']);
            $client->request('GET', '/api/admin/vault?sort=name_asc&page=2');
            self::assertResponseIsSuccessful();
            $alphaLast = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertCount(1, $alphaLast['assets']);
            self::assertSame('pagina-9.png', $alphaLast['assets'][0]['name']);
            $client->request('GET', '/api/admin/vault?sort=name_desc');
            self::assertResponseIsSuccessful();
            self::assertSame('pagina-9.png',
                json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'][0]['name']);

            $db->update('gf_vault_assets', ['size_bytes' => 71],
                ['organization_id' => $mine, 'original_name' => 'pagina-0.png']);
            $db->update('gf_vault_assets', ['size_bytes' => 70],
                ['organization_id' => $mine, 'original_name' => 'pagina-1.png']);
            $client->request('GET', '/api/admin/vault?sort=size_desc');
            self::assertResponseIsSuccessful();
            self::assertSame('pagina-0.png',
                json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'][0]['name']);
            $client->request('GET', '/api/admin/vault?sort=size_asc');
            self::assertResponseIsSuccessful();
            self::assertSame(69,
                json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'][0]['size_bytes']);
            foreach (['pagina-0.png', 'pagina-1.png'] as $original) {
                $db->update('gf_vault_assets', ['size_bytes' => 69],
                    ['organization_id' => $mine, 'original_name' => $original]);
            }

            foreach (['?format=gif', '?format[]=jpeg', '?sort=random()', '?sort[]=name_asc',
                '?sort=name_asc%20DESC'] as $invalidFilter) {
                $client->request('GET', '/api/admin/vault'.$invalidFilter);
                self::assertResponseStatusCodeSame(422);
            }

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

            // Inline preview checks tenant, real bytes and privacy headers.
            $client->request('GET', '/api/admin/vault/'.$foreignAsset.'/preview');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/api/admin/vault/'.$mineAsset.'/preview');
            self::assertResponseIsSuccessful();
            self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString('inline', (string) $client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringNotContainsString('imagen-prueba.png', (string) $client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            self::assertSame('same-origin', $client->getResponse()->headers->get('Cross-Origin-Resource-Policy'));
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
            self::assertSame($bytes, file_get_contents($client->getResponse()->getFile()->getPathname()));
            $unavailable = (string) $db->fetchOne(
                'SELECT id FROM gf_vault_assets WHERE organization_id = ? AND original_name = ?',
                [$mine, 'pagina-0.png'],
            );
            $client->request('GET', '/api/admin/vault/'.$unavailable.'/preview');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/api/admin/vault/'.$foreignAsset.'/download');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/api/admin/vault/'.$mineAsset.'/download');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

            // Byte limit: 16 real metadata rows of 8 MiB exceed 128 MiB once
            // the remaining 15 records are included. Another tenant is ignored.
            $largeIds = $db->fetchFirstColumn(
                'SELECT id FROM gf_vault_assets WHERE organization_id = :org AND id <> :own ORDER BY id LIMIT 16',
                ['org' => $mine, 'own' => $mineAsset],
            );
            self::assertCount(16, $largeIds);
            foreach ($largeIds as $id) {
                $db->update('gf_vault_assets', ['size_bytes' => 8 * 1024 * 1024], ['id' => $id]);
            }
            $client->request('GET', '/api/admin/vault');
            $fullQuota = json_decode((string) $client->getResponse()->getContent(), true)['data']['quota'];
            self::assertSame(31, $fullQuota['used_assets']);
            self::assertGreaterThan(128 * 1024 * 1024, $fullQuota['used_bytes']);

            $vaultRoot = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/vault/';
            $blobsBefore = glob($vaultRoot.'*.blob');
            $tmp = tempnam(sys_get_temp_dir(), 'gf-vault-');
            $temp[] = $tmp;
            file_put_contents($tmp, $bytes);
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($tmp, 'sobre-cuota.png', 'image/png', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('vault_quota_exceeded',
                json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
            self::assertSame($blobsBefore, glob($vaultRoot.'*.blob'), 'Un rechazo de cuota no debe dejar un blob huérfano.');
            self::assertSame(31, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_vault_assets WHERE organization_id = ?', [$mine],
            ));

            // Count limit is independent of bytes; no extra row may be inserted
            // when the organization reaches exactly 100 images.
            foreach ($largeIds as $id) {
                $db->update('gf_vault_assets', ['size_bytes' => 69], ['id' => $id]);
            }
            for ($i = 0; $i < 69; ++$i) {
                $id = Uuid::v7()->toRfc4122();
                $db->insert('gf_vault_assets', [
                    'id' => $id, 'organization_id' => $mine, 'uploaded_by' => $user,
                    'original_name' => 'cuota-'.$i.'.png', 'mime_type' => 'image/png',
                    'size_bytes' => 69, 'sha256' => str_repeat('b', 64),
                    'storage_key' => $id, 'created_at' => $at,
                ]);
            }
            $client->request('GET', '/api/admin/vault?page=1');
            $atLimit = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(100, $atLimit['quota']['used_assets']);
            self::assertSame(100, $atLimit['total']);
            $tmp = tempnam(sys_get_temp_dir(), 'gf-vault-');
            $temp[] = $tmp;
            file_put_contents($tmp, $bytes);
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($tmp, 'extra.png', 'image/png', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame(100, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_vault_assets WHERE organization_id = ?', [$mine],
            ));
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_vault_assets WHERE organization_id = ?', [$foreign],
            ));

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
            self::assertSame('organization_access_changed', json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
            // Revocation clears the selected tenant, so the next call needs a new selection.
            $client->request('GET', '/api/admin/vault');
            self::assertResponseStatusCodeSame(409);
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
