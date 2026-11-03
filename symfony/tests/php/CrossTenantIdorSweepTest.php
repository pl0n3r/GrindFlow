<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Library\AssetCollectionApplication;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** GF346: two synthetic tenants; no live data, network, or external provider. */
final class CrossTenantIdorSweepTest extends WebTestCase
{
    private const PASSWORD = 'gf673-synthetic-test-only';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function test_criterio_principal(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $library = static::getContainer()->get(AssetCollectionApplication::class);
        $users = ['a' => Uuid::v7()->toRfc4122(), 'b' => Uuid::v7()->toRfc4122()];
        $orgs = ['a' => Uuid::v7()->toRfc4122(), 'b' => Uuid::v7()->toRfc4122()];
        $assets = ['a' => Uuid::v7()->toRfc4122(), 'b' => Uuid::v7()->toRfc4122()];
        $collections = [];
        $unknown = Uuid::v7()->toRfc4122();
        $createdUsers = [];
        $createdOrgs = [];
        $createdAssets = [];
        $now = gmdate('Y-m-d H:i:s');

        try {
            foreach (['a', 'b'] as $side) {
                $db->insert('gf_identity_users', [
                    'id' => $users[$side], 'name' => 'GF673 synthetic '.$side,
                    'email' => $users[$side].'@example.test',
                    'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                    'platform_role' => 'model', 'is_active' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $createdUsers[] = $users[$side];
                $db->insert('gf_identity_organizations', [
                    'id' => $orgs[$side], 'name' => 'GF673 synthetic org '.$side,
                    'slug' => 'gf673-'.bin2hex(random_bytes(12)), 'type' => 'independent',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $createdOrgs[] = $orgs[$side];
                $db->insert('gf_identity_memberships', [
                    'id' => Uuid::v7()->toRfc4122(), 'user_id' => $users[$side],
                    'organization_id' => $orgs[$side], 'role' => 'studio',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $db->insert('gf_vault_assets', [
                    'id' => $assets[$side], 'organization_id' => $orgs[$side],
                    'uploaded_by' => $users[$side], 'original_name' => 'gf673-'.$side.'.png',
                    'mime_type' => 'image/png', 'size_bytes' => 69,
                    'sha256' => hash('sha256', 'gf673-'.$side),
                    'storage_key' => $assets[$side], 'created_at' => $now,
                ]);
                $createdAssets[] = $assets[$side];
                $result = $library->createCollection($orgs[$side], $users[$side]);
                self::assertSame('ok', $result['status']);
                $collections[$side] = $result['id'];
                self::assertSame('ok', $library->attachAsset(
                    $orgs[$side], $users[$side], $collections[$side], $assets[$side],
                )['status']);
            }

            foreach (['a', 'b'] as $side) {
                $other = $side === 'a' ? 'b' : 'a';
                $this->login($client, $users[$side]);

                $client->request('GET', '/api/admin/vault/'.$assets[$side]);
                self::assertResponseIsSuccessful();
                $client->request('GET', '/api/admin/library/collections/'.$collections[$side].'/assets');
                self::assertResponseIsSuccessful();
                self::assertContains($assets[$side], $this->body($client)['data']['assets']);
                foreach (['integrity', 'network-rules'] as $endpoint) {
                    $client->request('GET', '/api/admin/vault/'.$assets[$side].'/'.$endpoint);
                    self::assertResponseIsSuccessful();
                }

                $routes = [
                    '/api/admin/vault/{id}',
                    '/api/admin/vault/{id}/download',
                    '/api/admin/vault/{id}/preview',
                    '/api/admin/vault/{id}/integrity',
                    '/api/admin/vault/{id}/network-rules',
                    '/api/admin/vault/{id}/history',
                    '/api/admin/vault/{id}/lifecycle',
                    '/api/admin/library/assets/{id}/collections',
                    '/api/admin/library/masters/{id}/variants',
                    '/api/admin/library/collections/{collectionId}/assets',
                ];
                foreach ($routes as $route) {
                    $foreign = str_replace(
                        ['{id}', '{collectionId}'],
                        [$assets[$other], $collections[$other]], $route,
                    );
                    $missing = str_replace(
                        ['{id}', '{collectionId}'], $unknown, $route,
                    );
                    $this->assertForeignMatchesMissing($client, $foreign, $missing, [403, 404]);
                }

                $this->assertForeignMatchesMissing(
                    $client,
                    '/api/admin/library/collections?after='.$collections[$other],
                    '/api/admin/library/collections?after='.$unknown,
                    [422],
                );
                $client->request('GET', '/api/admin/library/collections?after='.$collections[$side]);
                self::assertResponseIsSuccessful();

                $this->assertForeignMatchesMissing(
                    $client,
                    '/api/admin/library/collections/'.$collections[$side].'/assets?after='.$assets[$other],
                    '/api/admin/library/collections/'.$collections[$side].'/assets?after='.$unknown,
                    [422],
                );
                $client->request('GET', '/api/admin/vault');
                self::assertResponseIsSuccessful();
                $data = $this->body($client);
                self::assertSame([$assets[$side]], array_column($data['data']['assets'], 'id'));
                self::assertStringNotContainsString($assets[$other], (string) $client->getResponse()->getContent());

                self::assertSame('gf673-'.$other.'.png', $db->fetchOne(
                    'SELECT original_name FROM gf_vault_assets WHERE id = ? AND organization_id = ?',
                    [$assets[$other], $orgs[$other]],
                ));
            }
        } finally {
            foreach ($createdOrgs as $org) {
                $db->delete('gf_vault_collection_assets', ['organization_id' => $org]);
                $db->delete('gf_vault_collections', ['organization_id' => $org]);
            }
            foreach ($createdAssets as $id) {
                $db->delete('gf_vault_assets', ['id' => $id]);
            }
            foreach ($createdOrgs as $org) {
                $db->delete('gf_identity_memberships', ['organization_id' => $org]);
                $db->delete('gf_identity_organizations', ['id' => $org]);
            }
            foreach ($createdUsers as $user) {
                $db->delete('gf_identity_users', ['id' => $user]);
            }
        }
    }

    private function login(KernelBrowser $client, string $user): void
    {
        $client->restart();
        $page = $client->request('GET', '/login');
        $client->submit($page->filter('form.identity-form')->form([
            'email' => $user.'@example.test', 'password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects('/organizations');
        $organizations = $client->request('GET', '/organizations');
        $client->submit($organizations->filter('.identity-orgs form')->form());
        self::assertResponseRedirects('/admin');
    }

    /** @return array<string, mixed> */
    private function body(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param list<int> $allowed */
    private function assertForeignMatchesMissing(
        KernelBrowser $client, string $foreign, string $missing, array $allowed,
    ): void {
        $client->request('GET', $foreign);
        $first = $this->deniedSnapshot($client);
        $client->request('GET', $missing);
        $second = $this->deniedSnapshot($client);
        self::assertContains($first['status'], $allowed, $foreign);
        self::assertSame($first, $second, $foreign.' must not reveal resource existence');
    }

    /** @return array{status: int, body: array<string,mixed>, headers: array<string, list<string>>, cookies: list<string>} */
    private function deniedSnapshot(KernelBrowser $client): array
    {
        $response = $client->getResponse();
        $headers = $response->headers->all();
        // Compare all application cookies; normalize only the opaque value of
        // known session cookies. Cookie flags, paths, domains and other attributes
        // must remain visible, otherwise Set-Cookie becomes an existence oracle.
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $serialized = (string) $cookie;
            $cookieName = $cookie->getName();
            if (in_array(strtoupper($cookieName), ['PHPSESSID', 'MOCKSESSID'], true)) {
                $serialized = preg_replace(
                    '/^'.preg_quote($cookieName, '/').'=[^;]*/',
                    $cookieName.'=<session-token>',
                    $serialized,
                    1,
                );
            }
            $cookies[] = $serialized;
        }
        sort($cookies);
        // Expires is derived from the response Date; comparing absolute
        // clock seconds is flaky if equivalent denials cross a second boundary.
        // Compare cache lifetime while retaining other security headers.
        if (isset($headers['expires'])) {
            $date = $response->headers->get('Date');
            $expires = $response->headers->get('Expires');
            self::assertNotNull($date);
            self::assertNotNull($expires);
            $dateEpoch = strtotime($date);
            $expiresEpoch = strtotime($expires);
            self::assertNotFalse($dateEpoch);
            self::assertNotFalse($expiresEpoch);
            $headers['expires'] = [(string) ($expiresEpoch - $dateEpoch)];
        }
        foreach (['date', 'set-cookie', 'x-request-id', 'x-correlation-id', 'traceparent', 'tracestate', 'server-timing'] as $volatile) {
            unset($headers[$volatile]);
        }
        ksort($headers);
        $body = $this->body($client);
        self::assertArrayNotHasKey('data', $body);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
            'headers' => $headers,
            'cookies' => $cookies,
        ];
    }
}
