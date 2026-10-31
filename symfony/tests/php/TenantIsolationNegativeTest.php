<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Two synthetic tenants; offline disposable MariaDB only. #793 */
final class TenantIsolationNegativeTest extends WebTestCase
{
    private const PASSWORD = 'gf793-synthetic-test-only';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function test_criterio_principal(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $users = ['alpha' => Uuid::v7()->toRfc4122(), 'beta' => Uuid::v7()->toRfc4122()];
        $orgs = ['alpha' => Uuid::v7()->toRfc4122(), 'beta' => Uuid::v7()->toRfc4122()];
        $assets = ['alpha' => Uuid::v7()->toRfc4122(), 'beta' => Uuid::v7()->toRfc4122()];
        $time = gmdate('Y-m-d H:i:s');
        $createdUsers = [];
        $createdOrgs = [];
        $createdAssets = [];

        try {
            foreach (['alpha', 'beta'] as $side) {
                $db->insert('gf_identity_users', [
                    'id' => $users[$side], 'name' => 'GF793 fictitious '.$side,
                    'email' => $users[$side].'@example.test',
                    'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                    'platform_role' => 'model', 'is_active' => 1,
                    'created_at' => $time, 'updated_at' => $time,
                ]);
                $createdUsers[] = $users[$side];
                $db->insert('gf_identity_organizations', [
                    'id' => $orgs[$side], 'name' => 'GF793 studio '.$side,
                    'slug' => 'gf793-'.bin2hex(random_bytes(12)), 'type' => 'independent',
                    'created_at' => $time, 'updated_at' => $time,
                ]);
                $createdOrgs[] = $orgs[$side];
                $db->insert('gf_identity_memberships', [
                    'id' => Uuid::v7()->toRfc4122(), 'user_id' => $users[$side],
                    'organization_id' => $orgs[$side], 'role' => $side === 'alpha' ? 'studio' : 'admin',
                    'created_at' => $time, 'updated_at' => $time,
                ]);
                $db->insert('gf_vault_assets', [
                    'id' => $assets[$side], 'organization_id' => $orgs[$side],
                    'uploaded_by' => $users[$side], 'original_name' => 'gf793-'.$side.'.png',
                    'mime_type' => 'image/png', 'size_bytes' => 69,
                    'sha256' => hash('sha256', 'gf793-'.$side),
                    'storage_key' => $assets[$side], 'created_at' => $time,
                ]);
                $createdAssets[] = $assets[$side];
            }

            $tokens = [];
            foreach (['alpha', 'beta'] as $side) {
                $other = $side === 'alpha' ? 'beta' : 'alpha';
                $this->login($client, $users[$side]);
                $client->request('GET', '/api/admin/context');
                self::assertResponseIsSuccessful();
                $tokens[$side] = (string) ($this->body($client)['data']['vault_manage_csrf'] ?? '');
                self::assertNotEmpty($tokens[$side]);

                $client->request('GET', '/api/admin/vault');
                self::assertResponseIsSuccessful();
                $list = $this->body($client)['data'];
                self::assertSame(1, $list['total']);
                self::assertSame([$assets[$side]], array_column($list['assets'], 'id'));
                self::assertStringNotContainsString('gf793-'.$other.'.png', (string) $client->getResponse()->getContent());

                foreach ([
                    'detail' => '/api/admin/vault/'.$assets[$other],
                    'download' => '/api/admin/vault/'.$assets[$other].'/download',
                    'preview' => '/api/admin/vault/'.$assets[$other].'/preview',
                    'history' => '/api/admin/vault/'.$assets[$other].'/history',
                    'lifecycle' => '/api/admin/vault/'.$assets[$other].'/lifecycle',
                ] as $surface => $path) {
                    $client->request('GET', $path);
                    $this->assertDenied($client, 404, 'file_not_found', $side.'-'.$surface);
                }

                // Export is not implemented: an unexpected new endpoint must
                // not be accepted as evidence of tenant-safe export.
                $client->request('GET', '/api/admin/vault/export?organization_id='.$orgs[$other]);
                self::assertResponseStatusCodeSame(404);

                // Valid own-tenant CSRF cannot authorize foreign writes.
                $this->jsonRequest($client, 'POST', '/api/admin/vault/'.$assets[$other].'/name',
                    ['name' => 'injected.png'], $tokens[$side]);
                $this->assertDenied($client, 404, 'file_not_found', $side.'-rename');
                $this->jsonRequest($client, 'POST', '/api/admin/vault/'.$assets[$other].'/trash',
                    null, $tokens[$side]);
                $this->assertDenied($client, 404, 'file_not_found', $side.'-trash');
                self::assertSame('gf793-'.$other.'.png', $db->fetchOne(
                    'SELECT original_name FROM gf_vault_assets WHERE id = ? AND organization_id = ?',
                    [$assets[$other], $orgs[$other]],
                ));
                $otherState = $db->fetchAssociative('SELECT deleted_at, purged_at FROM gf_vault_assets WHERE id = ?', [$assets[$other]]);
                self::assertIsArray($otherState);
                self::assertNull($otherState['deleted_at']);
                self::assertNull($otherState['purged_at']);
            }

            $this->login($client, $users['alpha']);
            foreach ([null, 'invalid-token', $tokens['beta']] as $csrf) {
                $this->jsonRequest($client, 'POST', '/api/admin/vault/'.$assets['alpha'].'/name',
                    ['name' => 'forbidden.png'], $csrf);
                $this->assertDenied($client, 403, 'invalid_csrf', 'post-rename-csrf');
                $this->jsonRequest($client, 'POST', '/api/admin/vault/'.$assets['alpha'].'/trash',
                    null, $csrf);
                $this->assertDenied($client, 403, 'invalid_csrf', 'post-trash-csrf');
            }

            // A real PUT route, unlike an absent/misrouted endpoint.
            foreach ([null, 'invalid-token', $tokens['beta']] as $csrf) {
                $this->jsonRequest(
                    $client, 'PUT', '/api/admin/library/collections/'.
                    Uuid::v7()->toRfc4122().'/assets/'.$assets['alpha'], [], $csrf,
                );
                $this->assertDenied($client, 403, 'invalid_csrf', 'put-attach-csrf');
            }

            // No DELETE vault route exists; 404/405 is the fail-closed contract.
            foreach ([null, 'invalid-token', $tokens['beta']] as $csrf) {
                $this->jsonRequest($client, 'DELETE', '/api/admin/vault/'.$assets['alpha'], null, $csrf);
                self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
            }

            // Model cannot edit or trash even with an earlier legitimate token.
            $db->update('gf_identity_memberships', ['role' => 'model'], [
                'user_id' => $users['alpha'], 'organization_id' => $orgs['alpha'],
            ]);
            $this->jsonRequest($client, 'POST', '/api/admin/vault/'.$assets['alpha'].'/name',
                ['name' => 'role-bypass.png'], $tokens['alpha']);
            $this->assertDenied($client, 403, 'vault_manage_forbidden', 'model-rename');
            $this->jsonRequest($client, 'POST', '/api/admin/vault/'.$assets['alpha'].'/trash',
                null, $tokens['alpha']);
            $this->assertDenied($client, 403, 'vault_manage_forbidden', 'model-trash');

            // Studio may prepare content but cannot permanently purge it.
            $db->update('gf_identity_memberships', ['role' => 'studio'], [
                'user_id' => $users['alpha'], 'organization_id' => $orgs['alpha'],
            ]);
            $this->jsonRequest($client, 'POST', '/api/admin/vault/'.$assets['alpha'].'/purge',
                ['confirm_asset_id' => $assets['alpha']], $tokens['alpha']);
            $this->assertDenied($client, 403, 'purge_forbidden', 'studio-purge');

            foreach (['alpha', 'beta'] as $side) {
                self::assertSame('gf793-'.$side.'.png', $db->fetchOne(
                    'SELECT original_name FROM gf_vault_assets WHERE id = ?', [$assets[$side]],
                ));
                $state = $db->fetchAssociative(
                    'SELECT deleted_at, purged_at FROM gf_vault_assets WHERE id = ?', [$assets[$side]],
                );
                self::assertIsArray($state);
                self::assertNull($state['deleted_at']);
                self::assertNull($state['purged_at']);
            }
        } finally {
            // Scoped cleanup only, on ephemeral CI database.
            foreach ($createdAssets as $id) {
                $db->delete('gf_vault_assets', ['id' => $id]);
            }
            foreach ($createdOrgs as $id) {
                $db->delete('gf_identity_memberships', ['organization_id' => $id]);
                $db->delete('gf_identity_organizations', ['id' => $id]);
            }
            foreach ($createdUsers as $id) {
                $db->delete('gf_identity_users', ['id' => $id]);
            }
        }
    }

    private function login(KernelBrowser $client, string $user): void
    {
        $client->restart();
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $user.'@example.test', 'password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects('/organizations');
        $select = $client->request('GET', '/organizations');
        $client->submit($select->filter('.identity-orgs form')->form());
        self::assertResponseRedirects('/admin');
    }

    /** @return array<string, mixed> */
    private function body(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $payload */
    private function jsonRequest(
        KernelBrowser $client, string $method, string $path, ?array $payload, ?string $csrf,
    ): void {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf !== null) {
            $headers['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $client->request($method, $path, server: $headers,
            content: $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function assertDenied(KernelBrowser $client, int $status, string $error, string $case): void
    {
        self::assertResponseStatusCodeSame($status, $case);
        $body = $this->body($client);
        self::assertSame($error, $body['error']['code'] ?? null, $case);
        self::assertArrayNotHasKey('data', $body);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        // CI-only denial register, never product audit.
        fwrite(STDERR, sprintf("[GF793 denied] %s %d %s\n", $case, $status, $error));
    }
}
