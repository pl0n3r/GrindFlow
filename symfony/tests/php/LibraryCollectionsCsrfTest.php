<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Factory #444: only synthetic identities and disposable test database rows.
 * Assert the real HTTP gates before any Library collection/variant mutation.
 */
final class LibraryCollectionsCsrfTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMissingOrInvalidCsrfIsRejectedOnEveryWriteRoute(): void
    {
        $this->withIdentity('editor', function (KernelBrowser $client, Connection $db, string $organization): void {
            foreach ($this->writeRoutes() as [$method, $path, $body]) {
                foreach ([[], ['HTTP_X_CSRF_TOKEN' => 'synthetic-invalid-csrf']] as $headers) {
                    $client->request($method, $path, [], [], $headers, $body);
                    self::assertResponseStatusCodeSame(403);
                    self::assertSame('invalid_csrf', $this->errorCode($client));
                }
            }
            self::assertSame(0, $this->countRows($db, 'gf_vault_collections', $organization));
            self::assertSame(0, $this->countRows($db, 'gf_vault_collection_assets', $organization));
            self::assertSame(0, $this->countRows($db, 'gf_vault_asset_variants', $organization));
        });
    }

    public function testRejectedWriteDoesNotChangeState(): void
    {
        $this->withIdentity('editor', function (KernelBrowser $client, Connection $db, string $organization): void {
            $tables = ['gf_vault_collections', 'gf_vault_collection_assets', 'gf_vault_asset_variants'];
            $before = array_map(fn (string $table): int => $this->countRows($db, $table, $organization), $tables);
            foreach ($this->writeRoutes() as [$method, $path, $body]) {
                $client->request($method, $path, [], [], ['HTTP_X_CSRF_TOKEN' => 'rejected'], $body);
                self::assertResponseStatusCodeSame(403);
                self::assertSame('invalid_csrf', $this->errorCode($client));
            }
            $after = array_map(fn (string $table): int => $this->countRows($db, $table, $organization), $tables);
            self::assertSame($before, $after, 'CSRF rejection must not change collections or relations');
        });
    }

    public function testRoleWithoutContentPrepareGetsLibraryWriteForbidden(): void
    {
        // MembershipContext grants content_prepare to admin/studio/editor, never model.
        $this->withIdentity('model', function (KernelBrowser $client, Connection $db, string $organization): void {
            foreach ($this->writeRoutes() as [$method, $path, $body]) {
                $client->request($method, $path, [], [], [], $body);
                self::assertResponseStatusCodeSame(403);
                self::assertSame('library_write_forbidden', $this->errorCode($client));
            }
            self::assertSame(0, $this->countRows($db, 'gf_vault_collections', $organization));
            self::assertSame(0, $this->countRows($db, 'gf_vault_collection_assets', $organization));
            self::assertSame(0, $this->countRows($db, 'gf_vault_asset_variants', $organization));
        });
    }

    public function testValidCsrfTokenAllowsCollectionCreation(): void
    {
        $this->withIdentity('editor', function (KernelBrowser $client, Connection $db, string $organization): void {
            $client->request('GET', '/api/admin/library/collections');
            self::assertResponseIsSuccessful();
            $listing = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertIsArray($listing);
            $csrf = $listing['data']['csrf'] ?? null;
            self::assertIsString($csrf);
            self::assertNotSame('', $csrf);

            $client->request('POST', '/api/admin/library/collections', [], [],
                ['HTTP_X_CSRF_TOKEN' => $csrf], '{}');
            self::assertResponseStatusCodeSame(201);
            self::assertSame(1, $this->countRows($db, 'gf_vault_collections', $organization));
        });
    }

    /** @return list<array{string, string, string}> */
    private function writeRoutes(): array
    {
        $collection = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $master = Uuid::v7()->toRfc4122();
        $variant = Uuid::v7()->toRfc4122();

        return [
            ['POST', '/api/admin/library/collections', '{}'],
            ['PUT', '/api/admin/library/collections/'.$collection.'/assets/'.$asset, '{}'],
            ['PUT', '/api/admin/library/masters/'.$master.'/variants/'.$variant, '{"type":"format"}'],
        ];
    }

    private function errorCode(KernelBrowser $client): string
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['error'] ?? null);
        return (string) ($decoded['error']['code'] ?? '');
    }

    private function countRows(Connection $db, string $table, string $organization): int
    {
        // $table is supplied only from the fixed whitelist within this test.
        self::assertContains($table, [
            'gf_vault_collections', 'gf_vault_collection_assets', 'gf_vault_asset_variants',
        ]);
        return (int) $db->fetchOne(
            'SELECT COUNT(*) FROM '.$table.' WHERE organization_id = :organization',
            ['organization' => $organization],
        );
    }

    /** @param callable(KernelBrowser, Connection, string): void $assertions */
    private function withIdentity(string $role, callable $assertions): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $password = 'synthetic-library-csrf-password';
        $at = gmdate('Y-m-d H:i:s');

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Library CSRF fixture',
            'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $db->insert('gf_identity_organizations', [
            'id' => $organization, 'name' => 'Library CSRF fixture',
            'slug' => 'csrf-'.substr(str_replace('-', '', $organization), 0, 24),
            'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user,
            'organization_id' => $organization, 'role' => $role,
            'created_at' => $at, 'updated_at' => $at,
        ]);

        try {
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => $password,
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');
            $assertions($client, $db, $organization);
        } finally {
            $db->delete('gf_vault_collection_assets', ['organization_id' => $organization]);
            $db->delete('gf_vault_asset_variants', ['organization_id' => $organization]);
            $db->delete('gf_vault_collections', ['organization_id' => $organization]);
            $db->delete('gf_identity_memberships', ['organization_id' => $organization]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }
}
