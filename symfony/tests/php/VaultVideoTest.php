<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class VaultVideoTest extends WebTestCase
{
    private const MP4_HEX = '000000186674797069736f6d0000020069736f6d69736f32';
    private const WEBM_HEX = '1a45dfa39f4286810142f7810142f2810442f381084282847765626d4287810242858102';
    private const INVALID_MP4_HEX = '000000186674797069736f6d00000000';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testQuickUploadAcceptsPrivateMp4AndWebmWithTenantSafePreview(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $storedIds = [];
        $tempFiles = [];
        $at = gmdate('Y-m-d H:i:s');
        $root = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/vault';

        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            self::fail('Private test storage unavailable.');
        }

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Video fixture',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('video-disposable-only', PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        try {
            foreach ([$mine => 'Video propio', $foreign => 'Video ajeno'] as $id => $name) {
                $db->insert('gf_identity_organizations', [
                    'id' => $id,
                    'name' => $name,
                    'slug' => 'video-'.substr($id, 0, 30),
                    'type' => 'independent',
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $user,
                'organization_id' => $mine,
                'role' => 'editor',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $user,
                'organization_id' => $foreign,
                'role' => 'editor',
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $foreignBytes = hex2bin(self::MP4_HEX);
            self::assertIsString($foreignBytes);
            self::assertSame(strlen($foreignBytes), file_put_contents($root.'/'.$foreignAsset.'.blob', $foreignBytes));
            $db->insert('gf_vault_assets', [
                'id' => $foreignAsset,
                'organization_id' => $foreign,
                'uploaded_by' => $user,
                'original_name' => 'ajeno.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => strlen($foreignBytes),
                'sha256' => hash('sha256', $foreignBytes),
                'storage_key' => $foreignAsset,
                'created_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test',
                'password' => 'video-disposable-only',
            ]));
            self::assertResponseRedirects('/organizations');

            $selector = $client->request('GET', '/organizations');
            $selectorToken = $selector->filter('.identity-orgs input[name="_csrf_token"]')->first()->attr('value');
            self::assertNotNull($selectorToken);
            $client->request('POST', '/organizations/select', [
                'organization_id' => $mine,
                '_csrf_token' => $selectorToken,
            ]);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context');
            $context = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame($mine, $context['organization']['id']);
            $uploadToken = $context['vault_upload_csrf'];
            self::assertNotEmpty($uploadToken);

            $invalid = $this->tempFile(self::INVALID_MP4_HEX, $tempFiles);
            self::assertSame('video/mp4', (new \finfo(FILEINFO_MIME_TYPE))->file($invalid));
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($invalid, 'cabecera-invalida.mp4', 'video/mp4', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $uploadToken]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('invalid_type',
                json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);

            $mp4Bytes = hex2bin(self::MP4_HEX);
            self::assertIsString($mp4Bytes);
            $mp4 = $this->tempFile(self::MP4_HEX, $tempFiles);
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($mp4, 'clip-prueba.mp4', 'video/mp4', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $uploadToken]);
            self::assertResponseStatusCodeSame(201);
            $mp4Asset = json_decode((string) $client->getResponse()->getContent(), true)['data']['asset'];
            $storedIds[] = $mp4Asset['id'];
            self::assertSame('clip-prueba.mp4', $mp4Asset['name']);
            self::assertSame('video/mp4', $mp4Asset['mime_type']);
            self::assertSame(strlen($mp4Bytes), $mp4Asset['size_bytes']);

            $webmBytes = hex2bin(self::WEBM_HEX);
            self::assertIsString($webmBytes);
            $webm = $this->tempFile(self::WEBM_HEX, $tempFiles);
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($webm, 'clip-prueba.webm', 'video/webm', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $uploadToken]);
            self::assertResponseStatusCodeSame(201);
            $webmAsset = json_decode((string) $client->getResponse()->getContent(), true)['data']['asset'];
            $storedIds[] = $webmAsset['id'];
            self::assertSame('video/webm', $webmAsset['mime_type']);
            self::assertSame(strlen($webmBytes), $webmAsset['size_bytes']);

            $client->request('GET', '/api/admin/vault?format=mp4');
            self::assertResponseIsSuccessful();
            $mp4List = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame('mp4', $mp4List['format']);
            self::assertSame(1, $mp4List['total']);
            self::assertSame($mp4Asset['id'], $mp4List['assets'][0]['id']);
            self::assertStringNotContainsString('ajeno.mp4', (string) $client->getResponse()->getContent());

            $client->request('GET', '/api/admin/vault?format=webm');
            self::assertResponseIsSuccessful();
            $webmList = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(1, $webmList['total']);
            self::assertSame($webmAsset['id'], $webmList['assets'][0]['id']);

            $client->request('GET', '/api/admin/vault/'.$foreignAsset.'/preview');
            self::assertResponseStatusCodeSame(404);

            foreach ([
                [$mp4Asset, 'video/mp4', 'preview.mp4', $mp4Bytes],
                [$webmAsset, 'video/webm', 'preview.webm', $webmBytes],
            ] as [$asset, $mime, $previewName, $bytes]) {
                $client->request('GET', '/api/admin/vault/'.$asset['id'].'/preview');
                self::assertResponseIsSuccessful();
                self::assertSame($mime, $client->getResponse()->headers->get('Content-Type'));
                self::assertStringContainsString('inline',
                    (string) $client->getResponse()->headers->get('Content-Disposition'));
                self::assertStringContainsString($previewName,
                    (string) $client->getResponse()->headers->get('Content-Disposition'));
                self::assertStringNotContainsString($asset['name'],
                    (string) $client->getResponse()->headers->get('Content-Disposition'));
                self::assertStringContainsString('no-store',
                    (string) $client->getResponse()->headers->get('Cache-Control'));
                self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
                self::assertSame('same-origin',
                    $client->getResponse()->headers->get('Cross-Origin-Resource-Policy'));
                self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
                self::assertSame($bytes, file_get_contents($client->getResponse()->getFile()->getPathname()));
            }

            $client->request('GET', '/api/admin/vault/'.$mp4Asset['id'].'/download');
            self::assertResponseIsSuccessful();
            self::assertSame('application/octet-stream', $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString('attachment',
                (string) $client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringContainsString('clip-prueba.mp4',
                (string) $client->getResponse()->headers->get('Content-Disposition'));
        } finally {
            $db->delete('gf_vault_assets', ['organization_id' => $mine]);
            $db->delete('gf_vault_assets', ['organization_id' => $foreign]);
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);

            foreach ($storedIds as $id) {
                @unlink($root.'/'.$id.'.blob');
            }
            @unlink($root.'/'.$foreignAsset.'.blob');
            foreach ($tempFiles as $temp) {
                @unlink($temp);
            }
        }
    }

    /** @param list<string> $tempFiles */
    private function tempFile(string $hex, array &$tempFiles): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'gf-video-');
        self::assertIsString($temp);
        $bytes = hex2bin($hex);
        self::assertIsString($bytes);
        file_put_contents($temp, $bytes);
        $tempFiles[] = $temp;

        return $temp;
    }
}
