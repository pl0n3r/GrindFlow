<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Isolated disposable accounts, MariaDB and private bytes. */
final class VaultTrashTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testTrashAndRestoreKeepPrivateOriginalAndEnforceMembership(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $mineAsset = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $root = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/vault';
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            self::fail('Fixture private storage unavailable.');
        }
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC', true);
        self::assertIsString($bytes);
        $path = $root.'/'.$mineAsset.'.blob';
        file_put_contents($path, $bytes);

        $client->request('POST', '/api/admin/vault/'.$mineAsset.'/trash');
        self::assertResponseStatusCodeSame(401);
        $client->request('POST', '/api/admin/vault/'.$mineAsset.'/restore');
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'S2 reversible actor',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('s2-trash-isolated-only', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at,
        ]);
        try {
            foreach ([$mine => 'Biblioteca local', $foreign => 'Organización externa'] as $id => $name) {
                $db->insert('gf_identity_organizations', [
                    'id' => $id, 'name' => $name, 'slug' => 'ci-'.substr($id, 0, 30),
                    'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
                ]);
            }
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user, 'organization_id' => $mine,
                'role' => 'editor', 'created_at' => $at, 'updated_at' => $at,
            ]);
            foreach ([$mineAsset => $mine, $foreignAsset => $foreign] as $id => $org) {
                $db->insert('gf_vault_assets', [
                    'id' => $id, 'organization_id' => $org, 'uploaded_by' => $user,
                    'original_name' => $id === $mineAsset ? 'propia.png' : 'ajena.png',
                    'mime_type' => 'image/png', 'size_bytes' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes), 'storage_key' => $id, 'created_at' => $at,
                ]);
            }
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => 's2-trash-isolated-only',
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context');
            $context = json_decode((string) $client->getResponse()->getContent(), true);
            $csrf = $context['data']['vault_manage_csrf'];
            self::assertNotEmpty($csrf);

            // Rename changes only display metadata; it must never move or duplicate private bytes.
            $renaming = '/api/admin/vault/'.$mineAsset.'/name';
            $renameHeaders = ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'];
            $client->request('POST', $renaming, server: ['HTTP_X_CSRF_TOKEN' => 'wrong', 'CONTENT_TYPE' => 'application/json'],
                content: '{"name":"nuevo.png"}');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/api/admin/vault/'.$foreignAsset.'/name', server: $renameHeaders,
                content: '{"name":"ajena-renombrada.png"}');
            self::assertResponseStatusCodeSame(404);
            foreach (['{"name":""}', '{"name":"valido.png","organization_id":"'.$foreign.'"}', '{"name":12}'] as $invalidName) {
                $client->request('POST', $renaming, server: $renameHeaders, content: $invalidName);
                self::assertResponseStatusCodeSame(422);
            }
            // Reject invisible Unicode names, format controls and non-ASCII line separators.
            foreach (["\u{00A0}\u{00A0}", "a\u{200B}b", "a\u{2028}b", "a\u{2029}b"] as $invisibleName) {
                $client->request('POST', $renaming, server: $renameHeaders,
                    content: json_encode(['name' => $invisibleName], JSON_THROW_ON_ERROR));
                self::assertResponseStatusCodeSame(422);
                self::assertSame('propia.png', $db->fetchOne(
                    'SELECT original_name FROM gf_vault_assets WHERE id = ?', [$mineAsset]));
            }
            $client->request('POST', $renaming, server: $renameHeaders, content: '{"name":"nueva-imagen.png"}');
            self::assertResponseIsSuccessful();
            self::assertSame('nueva-imagen.png',
                json_decode((string) $client->getResponse()->getContent(), true)['data']['name']);
            self::assertSame('nueva-imagen.png', $db->fetchOne(
                'SELECT original_name FROM gf_vault_assets WHERE id = ?', [$mineAsset]));
            self::assertFileExists($path);
            self::assertSame($bytes, file_get_contents($path));
            $client->request('GET', '/api/admin/vault/'.$mineAsset.'/download');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('nueva-imagen.png',
                (string) $client->getResponse()->headers->get('Content-Disposition'));

            $client->request('GET', '/api/admin/vault/'.$mineAsset.'/preview');
            self::assertResponseIsSuccessful();
            self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
            $client->request('GET', '/api/admin/vault/'.$foreignAsset.'/preview');
            self::assertResponseStatusCodeSame(404);

            $client->request('POST', '/api/admin/vault/'.$mineAsset.'/trash', server: ['HTTP_X_CSRF_TOKEN' => 'wrong']);
            self::assertResponseStatusCodeSame(403);
            self::assertNull($db->fetchOne('SELECT deleted_at FROM gf_vault_assets WHERE id = ?', [$mineAsset]));
            $client->request('POST', '/api/admin/vault/'.$foreignAsset.'/trash', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/api/admin/vault/'.$mineAsset.'/trash',
                server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'],
                content: '{"organization_id":"'.$foreign.'"}');
            self::assertResponseStatusCodeSame(422);

            $client->request('POST', '/api/admin/vault/'.$mineAsset.'/trash', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseIsSuccessful();
            self::assertSame('trash', json_decode((string) $client->getResponse()->getContent(), true)['data']['state']);
            $client->request('POST', $renaming, server: $renameHeaders, content: '{"name":"en-papelera.png"}');
            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertFileExists($path);
            self::assertSame($bytes, file_get_contents($path));
            self::assertSame($user, $db->fetchOne('SELECT deleted_by FROM gf_vault_assets WHERE id = ?', [$mineAsset]));
            self::assertNotNull($db->fetchOne('SELECT deleted_at FROM gf_vault_assets WHERE id = ?', [$mineAsset]));

            $client->request('GET', '/api/admin/vault');
            $active = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame([], $active['assets']);
            self::assertSame(0, $active['total']);
            self::assertSame(1, $active['quota']['used_assets']);
            self::assertSame(strlen($bytes), $active['quota']['used_bytes']);
            $client->request('GET', '/api/admin/vault?view=trash');
            $trash = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(1, $trash['total']);
            self::assertSame('trash', $trash['view']);
            self::assertSame($mineAsset, $trash['assets'][0]['id']);
            self::assertNotEmpty($trash['assets'][0]['deleted_at']);
            self::assertStringNotContainsString('ajena.png', (string) $client->getResponse()->getContent());

            $client->request('GET', '/api/admin/vault/'.$mineAsset);
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/api/admin/vault/'.$mineAsset.'/preview');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/api/admin/vault/'.$mineAsset.'/download');
            self::assertResponseStatusCodeSame(404);
            foreach (['all', 'deleted', 'active&view[]=trash'] as $invalid) {
                $client->request('GET', '/api/admin/vault?view='.$invalid);
                self::assertResponseStatusCodeSame(422);
            }
            $client->request('POST', '/api/admin/vault/'.$mineAsset.'/trash', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(409);
            $client->request('POST', '/api/admin/vault/'.$foreignAsset.'/restore', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(404);

            // Read-only role cannot restore or trash, even with prior editor's CSRF.
            $db->update('gf_identity_memberships', ['role' => 'model'], ['user_id' => $user, 'organization_id' => $mine]);
            $client->request('GET', '/api/admin/context');
            self::assertNull(json_decode((string) $client->getResponse()->getContent(), true)['data']['vault_manage_csrf']);
            $client->request('POST', $renaming, server: $renameHeaders, content: '{"name":"prohibida.png"}');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/api/admin/vault/'.$mineAsset.'/restore', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(403);
            $db->update('gf_identity_memberships', ['role' => 'editor'], ['user_id' => $user, 'organization_id' => $mine]);

            // Detect missing/corrupted blobs before returning them to the active library.
            file_put_contents($path, 'bad');
            $client->request('POST', '/api/admin/vault/'.$mineAsset.'/restore', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('file_unavailable',
                json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
            file_put_contents($path, $bytes);

            $client->request('POST', '/api/admin/vault/'.$mineAsset.'/restore', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseIsSuccessful();
            self::assertSame('active', json_decode((string) $client->getResponse()->getContent(), true)['data']['state']);
            self::assertNull($db->fetchOne('SELECT deleted_at FROM gf_vault_assets WHERE id = ?', [$mineAsset]));
            self::assertNull($db->fetchOne('SELECT deleted_by FROM gf_vault_assets WHERE id = ?', [$mineAsset]));
            $client->request('GET', '/api/admin/vault/'.$mineAsset.'/download');
            self::assertResponseIsSuccessful();
            $client->request('GET', '/api/admin/vault?view=trash');
            self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['data']['assets']);

            // The same CSRF cannot override a revoked membership.
            $db->delete('gf_identity_memberships', ['user_id' => $user, 'organization_id' => $mine]);
            $client->request('POST', '/api/admin/vault/'.$mineAsset.'/trash', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(403);
            self::assertNull($db->fetchOne('SELECT deleted_at FROM gf_vault_assets WHERE id = ?', [$mineAsset]));
        } finally {
            $db->delete('gf_vault_assets', ['organization_id' => $mine]);
            $db->delete('gf_vault_assets', ['organization_id' => $foreign]);
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);
            @unlink($path);
        }
    }
}
