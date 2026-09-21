<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Atomic bulk classification against disposable Symfony MariaDB only. */
final class VaultBulkUsageTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testBatchIsTenantSafeAllOrNothingIdempotentAndRoleProtected(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $actor = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $first = Uuid::v7()->toRfc4122();
        $second = Uuid::v7()->toRfc4122();
        $trashed = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $missing = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $endpoint = '/api/admin/vault/usage/bulk';
        $headers = ['CONTENT_TYPE' => 'application/json'];

        $client->request('POST', $endpoint, server: $headers, content: '{}');
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $actor, 'name' => 'Bulk test editor', 'email' => $actor.'@example.test',
            'password_hash' => password_hash('bulk-fixture-only-123', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at,
        ]);
        try {
            foreach ([$organization => 'Own workspace', $foreign => 'Other workspace'] as $id => $name) {
                $db->insert('gf_identity_organizations', [
                    'id' => $id, 'name' => $name,
                    'slug' => 'ci-'.substr($id, 0, 30), 'type' => 'independent',
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $actor,
                'organization_id' => $organization, 'role' => 'editor',
                'created_at' => $at, 'updated_at' => $at,
            ]);
            foreach ([$first => $organization, $second => $organization,
                $trashed => $organization, $foreignAsset => $foreign] as $id => $owner) {
                $db->insert('gf_vault_assets', [
                    'id' => $id, 'organization_id' => $owner, 'uploaded_by' => $actor,
                    'original_name' => $id.'.png', 'mime_type' => 'image/png',
                    'size_bytes' => 69, 'sha256' => hash('sha256', $id),
                    'storage_key' => $id, 'created_at' => $at,
                    'deleted_at' => $id === $trashed ? $at : null,
                ]);
            }
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $actor.'@example.test', 'password' => 'bulk-fixture-only-123',
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $csrf = json_decode((string) $client->getResponse()->getContent(), true)['data']['vault_manage_csrf'];
            self::assertNotEmpty($csrf);
            $validHeaders = $headers + ['HTTP_X_CSRF_TOKEN' => $csrf];
            $batch = static fn (array $ids, string $scope = 'internal_only'): string =>
                json_encode(['ids' => $ids, 'usage_scope' => $scope], JSON_THROW_ON_ERROR);
            $client->request('POST', $endpoint, server: $headers, content: $batch([$first, $second]));
            self::assertResponseStatusCodeSame(403);

            foreach ([
                '{}', '{"ids":[],"usage_scope":"internal_only"}',
                $batch([$first, $first]),
                $batch([$first], 'published'),
                $batch([strtoupper($first)]),
                json_encode(['ids' => [$first], 'usage_scope' => 'internal_only', 'organization_id' => $foreign], JSON_THROW_ON_ERROR),
                json_encode(['ids' => array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, 31)),
                    'usage_scope' => 'internal_only'], JSON_THROW_ON_ERROR),
                json_encode(['ids' => [$first, 7], 'usage_scope' => 'internal_only'], JSON_THROW_ON_ERROR),
            ] as $invalid) {
                $client->request('POST', $endpoint, server: $validHeaders, content: $invalid);
                self::assertResponseStatusCodeSame(422);
            }
            foreach ([$foreignAsset, $trashed, $missing] as $unsafe) {
                $client->request('POST', $endpoint, server: $validHeaders,
                    content: $batch([$first, $unsafe]));
                self::assertResponseStatusCodeSame(404);
                self::assertSame('unclassified', $db->fetchOne(
                    'SELECT usage_scope FROM gf_vault_assets WHERE id = ?', [$first],
                ));
            }

            $client->request('POST', $endpoint, server: $validHeaders, content: $batch([$first, $second]));
            self::assertResponseIsSuccessful();
            self::assertSame(
                ['usage_scope' => 'internal_only', 'selected_count' => 2, 'updated_count' => 2],
                json_decode((string) $client->getResponse()->getContent(), true)['data'],
            );
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $client->request('POST', $endpoint, server: $validHeaders, content: $batch([$first, $second]));
            self::assertResponseIsSuccessful();
            self::assertSame(0, json_decode((string) $client->getResponse()->getContent(), true)['data']['updated_count']);
            $client->request('GET', '/api/admin/vault?usage=internal_only');
            self::assertResponseIsSuccessful();
            $listed = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(2, $listed['total']);
            self::assertSame(3, $listed['quota']['used_assets']);

            foreach ([$trashed, $foreignAsset] as $id) {
                self::assertSame('unclassified', $db->fetchOne(
                    'SELECT usage_scope FROM gf_vault_assets WHERE id = ?', [$id],
                ));
            }
            $db->update('gf_identity_memberships', ['role' => 'model'], [
                'user_id' => $actor, 'organization_id' => $organization,
            ]);
            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            self::assertNull(json_decode((string) $client->getResponse()->getContent(), true)['data']['vault_manage_csrf']);
            $client->request('POST', $endpoint, server: $validHeaders,
                content: $batch([$first, $second], 'needs_review'));
            self::assertResponseStatusCodeSame(403);
            self::assertSame('internal_only', $db->fetchOne(
                'SELECT usage_scope FROM gf_vault_assets WHERE id = ?', [$first],
            ));
        } finally {
            $db->delete('gf_vault_assets', ['organization_id' => $organization]);
            $db->delete('gf_vault_assets', ['organization_id' => $foreign]);
            $db->delete('gf_identity_memberships', ['user_id' => $actor]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $actor]);
        }
    }
}
