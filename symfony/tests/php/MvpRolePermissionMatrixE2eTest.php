<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GF342: synthetic HTTP role/tenant matrix across Library, Scheduling and Distribution.
 * Disposable Symfony test database only: no provider, network or production writes.
 */
final class MvpRolePermissionMatrixE2eTest extends WebTestCase
{
    private const PASSWORD = 'synthetic-role-matrix-fixture-only';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAnonymousCannotAccessOrMutateAnyMvpRoute(): void
    {
        [$client, $db, $f] = $this->fixture();
        foreach (['/api/admin/context', '/api/admin/vault', '/api/admin/schedules',
                  '/api/admin/rules/weekly/preview', '/api/admin/vault/'.$f['asset'].'/history'] as $uri) {
            $client->request('GET', $uri);
            self::assertResponseStatusCodeSame(401, $uri);
        }
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$f['asset'],
            server: self::jsonHeaders(), content: '{"authorized":true}');
        self::assertResponseStatusCodeSame(401);
        $client->request('POST', '/api/admin/schedules', server: self::jsonHeaders(),
            content: '{"asset_id":"'.$f['asset'].'","scheduled_at_utc":"2030-01-01T00:00:00Z"}');
        self::assertResponseStatusCodeSame(401);
        $this->assertNoExternalAttempts($db, $f);
    }

    public function testOwnerCanReadLibraryAuthorizeDistributionAndScheduleDraft(): void
    {
        [$client, $db, $f] = $this->fixture();
        $this->login($client, $f['admin']);
        $context = $this->context($client);
        self::assertTrue($context['permissions']['content_prepare']);
        self::assertTrue($context['permissions']['distribution_authorize']);
        $this->assertOwnReadAccess($client, $f);
        // Library write must succeed for the owner, not merely return a read-only 200.
        $this->assertLibraryUsageRoundTrip($client, $db, $f, $context['vault_manage_csrf']);
        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $slots = $this->data($client)['slots'];
        self::assertNotSame([], $slots);
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$f['asset'],
            server: self::jsonHeaders($context['distribution_authorization_csrf']),
            content: '{"authorized":true}');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->data($client)['authorized']);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = ? AND asset_id = ?',
            [$f['org'], $f['asset']],
        ));
        $client->request('POST', '/api/admin/schedules',
            server: self::jsonHeaders($context['schedule_draft_csrf']),
            content: json_encode([
                'asset_id' => $f['asset'],
                'scheduled_at_utc' => $slots[0]['scheduled_at_utc'],
            ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $draft = $this->data($client);
        self::assertSame('draft', $draft['draft']['status']);
        self::assertFalse($draft['publishes']);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id = ? AND asset_id = ?',
            [$f['org'], $f['asset']],
        ));
        $this->assertNoExternalAttempts($db, $f);
    }

    public function testEditorPreparesAfterOwnerConsentButCannotGrantDistribution(): void
    {
        [$client, $db, $f] = $this->fixture();
        // The owner grants first: an editor must never forge authorization.
        $this->login($client, $f['admin']);
        $owner = $this->context($client);
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$f['asset'],
            server: self::jsonHeaders($owner['distribution_authorization_csrf']),
            content: '{"authorized":true}');
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = ?', [$f['org']],
        ));

        // A previously valid owner CSRF cannot bypass a current membership downgrade.
        // Without this check, a missing editor CSRF alone could explain a 403.
        $db->update('gf_identity_memberships', [
            'role' => 'editor', 'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['user_id' => $f['admin'], 'organization_id' => $f['org']]);
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$f['asset'],
            server: self::jsonHeaders($owner['distribution_authorization_csrf']),
            content: '{"authorized":false}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = ?', [$f['org']],
        ));

        // A separate real editor membership may still prepare after owner consent.
        $this->login($client, $f['editor']);
        $editor = $this->context($client);
        self::assertTrue($editor['permissions']['content_prepare']);
        self::assertFalse($editor['permissions']['distribution_authorize']);
        $this->assertOwnReadAccess($client, $f);
        // Editors can classify their own asset, despite being unable to authorize distribution.
        $this->assertLibraryUsageRoundTrip($client, $db, $f, $editor['vault_manage_csrf']);
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$f['asset'],
            server: self::jsonHeaders($editor['distribution_authorization_csrf']),
            content: '{"authorized":false}');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $slots = $this->data($client)['slots'];
        self::assertNotSame([], $slots);
        $client->request('POST', '/api/admin/schedules',
            server: self::jsonHeaders($editor['schedule_draft_csrf']),
            content: json_encode([
                'asset_id' => $f['asset'],
                'scheduled_at_utc' => $slots[0]['scheduled_at_utc'],
            ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id = ?', [$f['org']],
        ));
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = ?', [$f['org']],
        ));
        $this->assertNoExternalAttempts($db, $f);
    }

    public function testReaderCanReadOwnWorkspaceButCannotMutate(): void
    {
        [$client, $db, $f] = $this->fixture();
        $this->login($client, $f['admin']);
        $owner = $this->context($client);
        self::assertNotNull($owner['vault_manage_csrf']);
        self::assertNotNull($owner['distribution_authorization_csrf']);
        self::assertNotNull($owner['schedule_draft_csrf']);

        // Keep the owner's valid session and action-specific tokens, then revoke
        // write permission in the authoritative database. A missing CSRF would
        // otherwise make every 403 pass without proving any role enforcement.
        $db->update('gf_identity_memberships', [
            'role' => 'model', 'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['user_id' => $f['admin'], 'organization_id' => $f['org']]);
        $downgraded = $this->context($client);
        self::assertFalse($downgraded['permissions']['content_prepare']);
        self::assertFalse($downgraded['permissions']['distribution_authorize']);
        $this->assertOwnReadAccess($client, $f);
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/usage',
            server: self::jsonHeaders($owner['vault_manage_csrf']),
            content: '{"usage_scope":"internal_only"}');
        self::assertResponseStatusCodeSame(403);
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$f['asset'],
            server: self::jsonHeaders($owner['distribution_authorization_csrf']),
            content: '{"authorized":true}');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/api/admin/schedules',
            server: self::jsonHeaders($owner['schedule_draft_csrf']),
            content: '{"asset_id":"'.$f['asset'].'","scheduled_at_utc":"2030-01-01T00:00:00Z"}');
        self::assertResponseStatusCodeSame(403);

        // Also verify an independently authenticated reader receives read-only
        // permissions and no writable CSRF tokens from the context endpoint.
        $this->login($client, $f['reader']);
        $reader = $this->context($client);
        self::assertFalse($reader['permissions']['content_prepare']);
        self::assertFalse($reader['permissions']['distribution_authorize']);
        self::assertNull($reader['vault_manage_csrf']);
        self::assertNull($reader['distribution_authorization_csrf']);
        self::assertNull($reader['schedule_draft_csrf']);
        $this->assertOwnReadAccess($client, $f);
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id = ?', [$f['org']],
        ));
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = ?', [$f['org']],
        ));
        $this->assertNoExternalAttempts($db, $f);
    }

    public function testForeignTenantCannotReadOrMutateOtherWorkspace(): void
    {
        [$client, $db, $f] = $this->fixture();
        $this->login($client, $f['foreign']);
        $context = $this->context($client);
        self::assertTrue($context['permissions']['content_prepare']);
        $client->request('GET', '/api/admin/vault');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($f['asset'], (string) $client->getResponse()->getContent());
        // A policy that hides every asset is not valid tenant isolation.
        self::assertStringContainsString($f['foreign_asset'], (string) $client->getResponse()->getContent());
        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->data($client)['total']);
        $client->request('GET', '/api/admin/vault/'.$f['asset'].'/history');
        self::assertResponseStatusCodeSame(404);
        // A legitimate foreign-admin CSRF is insufficient to change another tenant's Library data.
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/usage',
            server: self::jsonHeaders($context['vault_manage_csrf']),
            content: '{"usage_scope":"internal_only"}');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('needs_review', $db->fetchOne(
            'SELECT usage_scope FROM gf_vault_assets WHERE id = ?', [$f['asset']],
        ));
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$f['asset'],
            server: self::jsonHeaders($context['distribution_authorization_csrf']),
            content: '{"authorized":true}');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $slots = $this->data($client)['slots'];
        self::assertNotSame([], $slots);
        $client->request('POST', '/api/admin/schedules',
            server: self::jsonHeaders($context['schedule_draft_csrf']),
            content: json_encode([
                'asset_id' => $f['asset'],
                'scheduled_at_utc' => $slots[0]['scheduled_at_utc'],
            ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = ?', [$f['foreign_org']],
        ));
        $this->assertNoExternalAttempts($db, $f);
    }

    /** @return array{0: KernelBrowser, 1: Connection, 2: array<string,string>} */
    private function fixture(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $f = [];
        foreach (['admin', 'editor', 'reader', 'foreign', 'org', 'foreign_org', 'asset', 'foreign_asset'] as $key) {
            $f[$key] = Uuid::v7()->toRfc4122();
        }
        $now = gmdate('Y-m-d H:i:s');
        foreach (['admin', 'editor', 'reader', 'foreign'] as $role) {
            $db->insert('gf_identity_users', [
                'id' => $f[$role], 'name' => 'Synthetic GF342 '.$role,
                'email' => $f[$role].'@example.test',
                'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                'platform_role' => 'model', 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([$f['org'] => 'Synthetic own workspace', $f['foreign_org'] => 'Synthetic foreign workspace'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name,
                'slug' => 'role-matrix-'.bin2hex(random_bytes(12)),
                'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([['admin', 'org', 'admin'], ['editor', 'org', 'editor'],
                  ['reader', 'org', 'model'], ['foreign', 'foreign_org', 'admin']] as [$who, $org, $role]) {
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $f[$who],
                'organization_id' => $f[$org], 'role' => $role,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([[$f['asset'], $f['org'], $f['admin']],
                  [$f['foreign_asset'], $f['foreign_org'], $f['foreign']]] as [$asset, $org, $uploader]) {
            $db->insert('gf_vault_assets', [
                'id' => $asset, 'organization_id' => $org, 'uploaded_by' => $uploader,
                'original_name' => $asset.'.png', 'mime_type' => 'image/png',
                'size_bytes' => 10, 'sha256' => hash('sha256', $asset),
                'storage_key' => $asset, 'usage_scope' => 'needs_review',
                'created_at' => $now,
            ]);
        }
        $db->insert('gf_content_review_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'],
            'asset_id' => $f['asset'], 'actor_id' => $f['admin'], 'action' => 'approve',
            'created_at' => $now,
        ]);
        $db->insert('gf_content_review_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['foreign_org'],
            'asset_id' => $f['foreign_asset'], 'actor_id' => $f['foreign'], 'action' => 'approve',
            'created_at' => $now,
        ]);
        foreach ([[$f['org'], $f['admin']], [$f['foreign_org'], $f['foreign']]] as [$organization, $actor]) {
            $db->insert('gf_content_rules', [
                'organization_id' => $organization, 'timezone' => 'UTC',
                'weekdays' => 'mon,tue,wed,thu,fri,sat,sun', 'local_time' => '23:59',
                'max_per_day' => 1, 'mode' => 'review_only', 'updated_by' => $actor,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return [$client, $db, $f];
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
        $client->submit($selector->filter('.identity-orgs form')->form());
        self::assertResponseRedirects('/admin');
    }

    /** @return array<string,mixed> */
    private function context(KernelBrowser $client): array
    {
        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        return $this->data($client);
    }

    private function assertOwnReadAccess(KernelBrowser $client, array $f): void
    {
        $client->request('GET', '/api/admin/vault');
        self::assertResponseIsSuccessful();
        // Exercise positive visibility of the selected tenant, not only absence
        // of foreign data; an empty result for all tenants is a false positive.
        self::assertStringContainsString($f['asset'], (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($f['foreign_asset'], (string) $client->getResponse()->getContent());
        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/admin/vault/'.$f['asset'].'/history');
        self::assertResponseIsSuccessful();
    }

    /** Prove actual tenant-scoped Library mutation and restore initial fixture state. */
    private function assertLibraryUsageRoundTrip(KernelBrowser $client, Connection $db, array $f, string $csrf): void
    {
        foreach (['internal_only', 'needs_review'] as $scope) {
            $client->request('POST', '/api/admin/vault/'.$f['asset'].'/usage',
                server: self::jsonHeaders($csrf),
                content: json_encode(['usage_scope' => $scope], JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertSame($scope, $this->data($client)['usage_scope']);
            self::assertSame($scope, $db->fetchOne(
                'SELECT usage_scope FROM gf_vault_assets WHERE id = ?', [$f['asset']],
            ));
        }
    }

    /** @return array<string,mixed> */
    private function data(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    /** @return array<string,string> */
    private static function jsonHeaders(?string $csrf = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf !== null) {
            $headers['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        return $headers;
    }

    private function assertNoExternalAttempts(Connection $db, array $f): void
    {
        foreach ([$f['org'], $f['foreign_org']] as $organization) {
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id = ?', [$organization],
            ));
        }
    }
}
