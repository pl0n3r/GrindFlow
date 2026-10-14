<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Library\AssetHistoryService;
use GrindFlow\Library\LibraryPermissions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Library #52/#53: server-side capability matrix and a redacted per-asset timeline (disposable DB only). */
final class LibraryPermissionsHistoryTest extends WebTestCase
{
    private const JSON = ['CONTENT_TYPE' => 'application/json'];
    private const PASSWORD = 'library-fixture-only-123';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMemberPermissionsAreEnforcedOnServer(): void
    {
        [$client, $db, $f] = $this->fixture();
        /** @var LibraryPermissions $library */
        $library = static::getContainer()->get(LibraryPermissions::class);

        // Role matrix: derived from the existing role permissions, never wider.
        $memberships = static::getContainer()->get(\GrindFlow\Identity\Application\MembershipContext::class);
        foreach (['admin', 'studio', 'editor', 'model'] as $role) {
            $matrix = $library->roleMatrix($role);
            $legacy = $memberships->permissions($role);
            self::assertSame(array_keys($matrix), LibraryPermissions::CAPABILITIES);
            self::assertSame($legacy['workspace_view'], $matrix['view'], $role);
            foreach (['upload', 'edit', 'archive'] as $capability) {
                self::assertSame($legacy['content_prepare'], $matrix[$capability], $role.' '.$capability);
            }
            self::assertSame($legacy['content_review_decide'], $matrix['approve'], $role);
            self::assertSame($legacy['distribution_authorize'], $matrix['authorize_publication'], $role);
            self::assertFalse($matrix['delete'], 'delete is denied by default for every role');
        }
        self::assertSame(array_fill_keys(LibraryPermissions::CAPABILITIES, false), $library->roleMatrix('visitor'));

        // Direct service access: unknown capability, foreign tenant and unknown user all fail closed.
        self::assertFalse($library->allows($f['org'], $f['editor'], 'publish_everything'));
        self::assertFalse($library->allows($f['foreign_org'], $f['editor'], 'view'));
        self::assertFalse($library->allows($f['org'], Uuid::v7()->toRfc4122(), 'view'));
        self::assertTrue($library->allows($f['org'], $f['editor'], 'edit'));
        self::assertFalse($library->allows($f['org'], $f['model'], 'edit'));
        self::assertFalse($library->deny($f['foreign_org'], $f['editor'], 'edit', $f['admin']), 'cannot restrict a non-member');

        // HTTP enforcement for a member whose role has no content_prepare.
        $this->login($client, $f['model']);
        $client->request('GET', '/api/admin/vault');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/admin/library/permissions');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame('model', $data['role']);
        self::assertTrue($data['permissions']['view']);
        self::assertFalse($data['permissions']['edit']);

        // An editor is allowed, then a deny-only restriction removes exactly that capability.
        $this->login($client, $f['editor']);
        $csrf = $this->manageCsrf($client);
        $headers = self::JSON + ['HTTP_X_CSRF_TOKEN' => $csrf];
        $edit = static fn (): int => $client->getResponse()->getStatusCode();
        $dbg = static fn (): string => (string) $client->getResponse()->getContent();
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/usage', server: $headers, content: '{"usage_scope":"internal_only"}');
        self::assertSame(200, $edit(), $dbg());

        self::assertTrue($library->deny($f['org'], $f['editor'], 'edit', $f['admin']));
        self::assertTrue($library->deny($f['org'], $f['editor'], 'edit', $f['admin']), 'idempotent');
        self::assertFalse($library->forMember($f['org'], $f['editor'])['edit']);
        self::assertTrue($library->forMember($f['org'], $f['editor'])['archive'], 'other capabilities untouched');
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/usage', server: $headers, content: '{"usage_scope":"needs_review"}');
        self::assertSame(403, $edit());
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/validity', server: $headers,
            content: '{"valid_from_at":null,"valid_until_at":null}');
        self::assertSame(403, $edit());
        self::assertSame('internal_only', $db->fetchOne('SELECT usage_scope FROM gf_vault_assets WHERE id = ?', [$f['asset']]));
        // archive is a separate capability: still allowed until it is denied too.
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/trash', server: $headers);
        self::assertSame(200, $edit());
        self::assertNotNull($db->fetchOne('SELECT deleted_at FROM gf_vault_assets WHERE id = ?', [$f['asset']]));
        $library->deny($f['org'], $f['editor'], 'archive', $f['admin']);
        $client->request('POST', '/api/admin/vault/'.$f['variant'].'/trash', server: $headers);
        self::assertSame(403, $edit(), $dbg());
        self::assertNull($db->fetchOne('SELECT deleted_at FROM gf_vault_assets WHERE id = ?', [$f['variant']]));
        // upload is checked on the server as its own capability.
        $library->deny($f['org'], $f['editor'], 'upload', $f['admin']);
        $client->request('POST', '/api/admin/vault', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertSame(403, $edit());

        // Removing view hides everything, including history and the matrix itself.
        $library->deny($f['org'], $f['editor'], 'view', $f['admin']);
        foreach (['/api/admin/vault', '/api/admin/vault/'.$f['asset'], '/api/admin/vault/'.$f['asset'].'/history', '/api/admin/library/permissions'] as $path) {
            $client->request('GET', $path);
            self::assertSame(403, $edit(), $path);
        }
    }

    public function testHistoryContainsAssetOriginChangesVariantsPublications(): void
    {
        [$client, $db, $f] = $this->fixture();
        $this->login($client, $f['admin']);
        $at = static fn (int $offset): string => gmdate('Y-m-d H:i:s', 1_900_000_000 + $offset);
        $db->update('gf_vault_assets', ['created_at' => $at(0)], ['id' => $f['asset']]);
        $db->insert('gf_vault_asset_variants', [
            'organization_id' => $f['org'], 'master_asset_id' => $f['asset'],
            'variant_asset_id' => $f['variant'], 'variant_type' => 'network', 'created_at' => $at(40),
        ]);
        $db->insert('gf_content_review_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $f['asset'],
            'actor_id' => $f['editor'], 'action' => 'approve', 'created_at' => $at(10),
        ]);
        $db->insert('gf_distribution_authorization_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $f['asset'],
            'actor_id' => $f['admin'], 'action' => 'grant', 'created_at' => $at(20),
        ]);
        $draft = Uuid::v7()->toRfc4122();
        $db->insert('gf_schedule_drafts', [
            'id' => $draft, 'organization_id' => $f['org'], 'asset_id' => $f['asset'],
            'caption' => 'confidential caption', 'created_by' => $f['editor'],
            'scheduled_at_utc' => $at(86400), 'timezone' => 'UTC', 'local_date' => gmdate('Y-m-d', 1_900_086_400),
            'local_time' => '10:00', 'status' => 'cancelled', 'created_at' => $at(30),
            'cancelled_at' => $at(35), 'cancelled_by' => $f['admin'],
        ]);
        $db->insert('gf_manual_handoff_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'draft_id' => $draft,
            'actor_id' => $f['editor'], 'action' => 'prepare', 'created_at' => $at(36),
        ]);

        $client->request('GET', '/api/admin/vault/'.$f['asset'].'/history');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame($f['asset'], $data['asset_id']);
        self::assertFalse($data['truncated']);
        self::assertSame(
            ['origin', 'content_review', 'distribution_authorization', 'schedule_draft',
                'schedule_draft_cancelled', 'manual_handoff', 'variant_linked'],
            array_column($data['events'], 'type'),
            'chronological origin → changes → variants → publications',
        );
        $byType = array_column($data['events'], null, 'type');
        self::assertSame('image/png', $byType['origin']['detail']['mime_type']);
        self::assertSame('approve', $byType['content_review']['detail']['action']);
        self::assertSame('grant', $byType['distribution_authorization']['detail']['action']);
        self::assertSame(['variant_type' => 'network', 'related_asset_id' => $f['variant']], $byType['variant_linked']['detail']);
        self::assertSame('cancelled', $byType['schedule_draft']['detail']['status']);
        self::assertSame('prepare', $byType['manual_handoff']['detail']['action']);
        // The variant sees the same relation from its side, and the deterministic order is stable.
        $client->request('GET', '/api/admin/vault/'.$f['variant'].'/history');
        $variantTypes = array_column(json_decode((string) $client->getResponse()->getContent(), true)['data']['events'], 'type');
        self::assertContains('variant_of', $variantTypes);
        self::assertSame($data, $this->historyOf($client, $f['asset']));

        // Archive state shows up as a change, and a long history is bounded.
        $db->update('gf_vault_assets', ['deleted_at' => $at(50), 'deleted_by' => $f['editor']], ['id' => $f['asset']]);
        self::assertContains('archived', array_column($this->historyOf($client, $f['asset'])['events'], 'type'));
        for ($i = 0; $i < AssetHistoryService::LIMIT + 5; ++$i) {
            $db->insert('gf_content_review_events', [
                'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $f['asset'],
                'actor_id' => $f['editor'], 'action' => $i % 2 === 0 ? 'revoke' : 'approve', 'created_at' => $at(100 + $i),
            ]);
        }
        $bounded = $this->historyOf($client, $f['asset']);
        self::assertTrue($bounded['truncated']);
        self::assertCount(AssetHistoryService::LIMIT, $bounded['events']);
    }

    public function testHistoryRedactsPiiAndSecrets(): void
    {
        [$client, $db, $f] = $this->fixture();
        $this->login($client, $f['admin']);
        $db->insert('gf_content_review_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $f['asset'],
            'actor_id' => $f['editor'], 'action' => 'approve', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $db->update('gf_vault_assets', ['private_note' => 'private note SECRET-NOTE-42'], ['id' => $f['asset']]);
        $db->insert('gf_schedule_drafts', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $f['asset'],
            'caption' => 'SECRET-CAPTION-42', 'delivery_provider' => 'facebook_page',
            'delivery_destination_id' => '424242424242', 'created_by' => $f['editor'],
            'scheduled_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600), 'timezone' => 'UTC',
            'local_date' => gmdate('Y-m-d'), 'local_time' => '10:00', 'status' => 'draft', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $client->request('GET', '/api/admin/vault/'.$f['asset'].'/history');
        self::assertResponseIsSuccessful();
        $raw = (string) $client->getResponse()->getContent();
        foreach ([
            'Zed Editor Secret', $f['editor'].'@example.test', $f['admin'].'@example.test', $f['editor'], $f['admin'],
            'SECRET-NOTE-42', 'SECRET-CAPTION-42', '424242424242', 'facebook_page',
            hash('sha256', $f['asset']), 'password', 'token', 'csrf', 'storage_key',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $raw, 'history must not leak '.$secret);
        }
        $events = json_decode($raw, true)['data']['events'];
        foreach ($events as $event) {
            self::assertSame(['type', 'at', 'actor_ref', 'detail'], array_keys($event));
            if ($event['actor_ref'] !== null) {
                self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/D', $event['actor_ref']);
            }
        }
        // The same member keeps one stable reference inside the organization only.
        $refs = array_column(array_filter($events, static fn (array $e): bool => in_array($e['type'], ['content_review', 'schedule_draft'], true)), 'actor_ref');
        self::assertCount(2, $refs);
        self::assertSame($refs[0], $refs[1]);
    }

    public function testDenyCrossTenantAndMissingRole(): void
    {
        [$client, $db, $f] = $this->fixture();
        /** @var AssetHistoryService $history */
        $history = static::getContainer()->get(AssetHistoryService::class);
        // Direct service access never crosses the tenant boundary.
        self::assertNull($history->forAsset($f['org'], $f['foreign_asset']));
        self::assertNull($history->forAsset($f['foreign_org'], $f['asset']));
        self::assertNull($history->forAsset($f['org'], Uuid::v7()->toRfc4122()));
        self::assertNotNull($history->forAsset($f['org'], $f['asset']));

        $anonymous = $client;
        $anonymous->request('GET', '/api/admin/vault/'.$f['asset'].'/history');
        self::assertResponseStatusCodeSame(401);
        $anonymous->request('GET', '/api/admin/library/permissions');
        self::assertResponseStatusCodeSame(401);

        $this->login($client, $f['editor']);
        foreach ([$f['foreign_asset'], Uuid::v7()->toRfc4122()] as $unsafe) {
            $client->request('GET', '/api/admin/vault/'.$unsafe.'/history');
            self::assertResponseStatusCodeSame(404);
        }
        // A member of another organization cannot read this tenant's history, and a
        // membership revoked mid-session is rejected before any data is read.
        $db->delete('gf_identity_memberships', ['user_id' => $f['editor'], 'organization_id' => $f['org']]);
        foreach ([$f['asset'], $f['foreign_asset']] as $asset) {
            $client->request('GET', '/api/admin/vault/'.$asset.'/history');
            // Access changed (403) or the organization must be selected again (409): never data.
            self::assertContains($client->getResponse()->getStatusCode(), [403, 409]);
            self::assertArrayNotHasKey('data', json_decode((string) $client->getResponse()->getContent(), true));
        }
        /** @var LibraryPermissions $library */
        $library = static::getContainer()->get(LibraryPermissions::class);
        self::assertSame(array_fill_keys(LibraryPermissions::CAPABILITIES, false), $library->forMember($f['org'], $f['editor']));
    }

    /** @return array<string, mixed> */
    private function historyOf(KernelBrowser $client, string $asset): array
    {
        $client->request('GET', '/api/admin/vault/'.$asset.'/history');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true)['data'];
    }

    private function manageCsrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();

        return (string) json_decode((string) $client->getResponse()->getContent(), true)['data']['vault_manage_csrf'];
    }

    private function login(KernelBrowser $client, string $user): void
    {
        $client->restart();
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $user.'@example.test', 'password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects('/organizations');
        $selector = $client->request('GET', '/organizations');
        $form = $selector->filter('.identity-orgs form')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin');
    }

    /** @return array{0: KernelBrowser, 1: Connection, 2: array<string, string>} */
    private function fixture(): array
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $f = [];
        foreach (['admin', 'editor', 'model', 'foreign_user', 'org', 'foreign_org', 'asset', 'variant', 'foreign_asset'] as $key) {
            $f[$key] = Uuid::v7()->toRfc4122();
        }
        $at = gmdate('Y-m-d H:i:s');
        foreach (['admin' => 'Ada Admin', 'editor' => 'Zed Editor Secret', 'model' => 'Mia Model', 'foreign_user' => 'Out Sider'] as $key => $name) {
            $db->insert('gf_identity_users', [
                'id' => $f[$key], 'name' => $name, 'email' => $f[$key].'@example.test',
                'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                'platform_role' => 'model', 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        foreach ([$f['org'] => 'Own workspace', $f['foreign_org'] => 'Other workspace'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name, 'slug' => 'ci-'.bin2hex(random_bytes(12)), // v7 ids created back to back share their first 30 chars
                'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        foreach ([['admin', 'org', 'admin'], ['editor', 'org', 'editor'], ['model', 'org', 'model'], ['foreign_user', 'foreign_org', 'admin']] as [$user, $org, $role]) {
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $f[$user], 'organization_id' => $f[$org],
                'role' => $role, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        foreach (['asset' => 'org', 'variant' => 'org', 'foreign_asset' => 'foreign_org'] as $asset => $org) {
            $db->insert('gf_vault_assets', [
                'id' => $f[$asset], 'organization_id' => $f[$org], 'uploaded_by' => $f['admin'],
                'original_name' => $f[$asset].'.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
                'sha256' => hash('sha256', $f[$asset]), 'storage_key' => $f[$asset], 'created_at' => $at,
            ]);
        }

        return [$client, $db, $f];
    }
}
