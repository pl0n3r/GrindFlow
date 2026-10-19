<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Library\AssetLifecycle;
use GrindFlow\Library\LibraryPermissions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Library #44/#54: audited lifecycle, reversible trash and explicit permanent delete (disposable DB only). */
final class LibraryLifecycleTrashTest extends WebTestCase
{
    private const JSON = ['CONTENT_TYPE' => 'application/json'];
    private const PASSWORD = 'lifecycle-fixture-only-123';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testInvalidTransitionsAreRejectedAndAudited(): void
    {
        [$client, $db, $f] = $this->fixture();
        $this->login($client, $f['admin']);
        $csrf = $this->csrf($client, 'vault_manage_csrf');
        $move = fn (mixed $to, ?string $asset = null, array $extra = []): array => $this->post(
            $client, '/api/admin/vault/'.($asset ?? $f['asset']).'/lifecycle', ['to' => $to] + $extra, $csrf,
        );
        $audit = static fn (string $outcome): int => (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_asset_lifecycle_events WHERE asset_id = ? AND outcome = ?', [$f['asset'], $outcome],
        );
        $state = static fn (): string => (string) $db->fetchOne('SELECT lifecycle_state FROM gf_vault_assets WHERE id = ?', [$f['asset']]);

        $client->request('GET', '/api/admin/vault/'.$f['asset'].'/lifecycle');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame(['new', 'new', ['ready', 'in_review', 'blocked', 'archived']], [$data['state'], $data['persisted_state'], $data['allowed_next']]);

        // Strict body, CSRF, tenant: nothing is audited for requests that never reach the machine.
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/lifecycle', server: self::JSON, content: '{"to":"ready"}');
        self::assertResponseStatusCodeSame(403);
        foreach ([[null], [7], ['published'], [['ready']]] as [$invalid]) {
            self::assertSame(422, $move($invalid)[0]);
        }
        self::assertSame(422, $move('ready', null, ['organization_id' => $f['foreign']])[0]);
        foreach ([$f['foreign_asset'], $f['trashed'], Uuid::v7()->toRfc4122()] as $unsafe) {
            self::assertSame(404, $move('ready', $unsafe)[0]);
        }
        self::assertSame(0, $audit('applied') + $audit('rejected'));

        // Valid path: new → ready → in_use → archived → ready, each audited as applied.
        foreach (['ready', 'in_use', 'archived', 'ready'] as $step) {
            [$status, $payload] = $move($step);
            self::assertSame(200, $status, $step);
            self::assertSame($step, $payload['data']['state']);
        }
        self::assertSame(4, $audit('applied'));

        // Invalid attempts: rejected with a stable reason, state unchanged, audited as rejected.
        self::assertSame(200, $move('in_review')[0]);
        $rejected = [
            ['in_use', 'invalid_transition'], ['in_review', 'same_state'],
            ['expired', 'derived_state'], ['new', 'invalid_transition'],
        ];
        foreach ($rejected as [$to, $reason]) {
            [$status, $payload] = $move($to);
            self::assertSame(409, $status, $to);
            self::assertSame('invalid_transition', $payload['error']['code']);
            self::assertSame($reason, $payload['error']['reason'], $to);
            self::assertSame('in_review', $state(), 'a rejected transition never changes the state');
        }
        self::assertSame(count($rejected), $audit('rejected'));
        $reasons = $db->fetchFirstColumn(
            "SELECT reason FROM gf_vault_asset_lifecycle_events WHERE asset_id = ? AND outcome = 'rejected' ORDER BY created_at, id", [$f['asset']],
        );
        self::assertSame(['invalid_transition', 'same_state', 'derived_state', 'invalid_transition'], $reasons);
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM gf_vault_asset_lifecycle_events WHERE outcome = 'applied' AND from_state = to_state"));
        self::assertSame(['ready', 'blocked', 'archived'], AssetLifecycle::allowedNext('in_review'));

        // `expired` is derived from the validity window and never persisted; archived stays archived.
        $db->update('gf_vault_assets', ['valid_until_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $f['asset']]);
        $client->request('GET', '/api/admin/vault/'.$f['asset'].'/lifecycle');
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame(['expired', 'in_review'], [$data['state'], $data['persisted_state']]);
        self::assertSame('expired', AssetLifecycle::effectiveState('paused', 'expired'));
        self::assertSame('archived', AssetLifecycle::effectiveState('archived', 'expired'));
        self::assertSame('new', AssetLifecycle::effectiveState('garbage', 'valid'));
        $db->update('gf_vault_assets', ['valid_until_at' => null], ['id' => $f['asset']]);

        // Paused, blocked and archived assets stay out of scheduling (reserve and preview).
        $slot = $this->firstSlot($client);
        foreach (['ready', 'paused', 'ready', 'blocked', 'ready', 'archived', 'ready'] as $to) {
            $move($to);
            $reason = ['paused' => 'lifecycle_paused', 'blocked' => 'lifecycle_blocked', 'archived' => 'lifecycle_archived'][$to] ?? null;
            $preview = array_column($this->preview($client)['assets'], null, 'id')[$f['asset']];
            [$status, $payload] = $this->post($client, '/api/admin/schedules', ['asset_id' => $f['asset'], 'scheduled_at_utc' => $slot], $this->csrf($client, 'schedule_draft_csrf'));
            if ($reason === null) {
                self::assertContains($preview['lifecycle_state'], ['ready']);
                self::assertNotContains('lifecycle_paused', $preview['blocking_reasons']);
                continue;
            }
            self::assertSame($to, $preview['lifecycle_state']);
            self::assertContains($reason, $preview['blocking_reasons']);
            self::assertFalse($preview['eligible']);
            self::assertSame(409, $status);
            self::assertContains($reason, $payload['error']['blocking_reasons']);
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_schedule_drafts WHERE asset_id = ?', [$f['asset']]));

        // The audit trail is part of the asset history, rejected attempts included.
        $client->request('GET', '/api/admin/vault/'.$f['asset'].'/history');
        $events = array_filter(json_decode((string) $client->getResponse()->getContent(), true)['data']['events'], static fn (array $e): bool => $e['type'] === 'lifecycle_transition');
        self::assertContains('rejected', array_column(array_column($events, 'detail'), 'outcome'));

        // A role without edit cannot change the state, with or without a captured token.
        $this->login($client, $f['model']);
        $csrf = $this->csrf($client, 'vault_manage_csrf');
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/lifecycle', server: self::JSON + ['HTTP_X_CSRF_TOKEN' => $csrf ?: 'x'], content: '{"to":"paused"}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRestoreRecoversAssetWithHistory(): void
    {
        [$client, $db, $f] = $this->fixture();
        $this->login($client, $f['admin']);
        $csrf = $this->csrf($client, 'vault_manage_csrf');
        $this->blob($f['asset']);
        $db->insert('gf_content_review_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $f['asset'],
            'actor_id' => $f['admin'], 'action' => 'approve', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertSame(200, $this->post($client, '/api/admin/vault/'.$f['asset'].'/lifecycle', ['to' => 'ready'], $csrf)[0]);
        $types = fn (): array => array_column($this->historyOf($client, $f['asset'])['events'], 'type');
        $before = $types();
        self::assertContains('content_review', $before);
        self::assertContains('lifecycle_transition', $before);

        // Reversible delete records actor and date and keeps the history readable.
        self::assertSame(200, $this->post($client, '/api/admin/vault/'.$f['asset'].'/trash', [], $csrf, true)[0]);
        $row = $db->fetchAssociative('SELECT deleted_at, deleted_by, lifecycle_state FROM gf_vault_assets WHERE id = ?', [$f['asset']]);
        self::assertNotNull($row['deleted_at']);
        self::assertSame($f['admin'], $row['deleted_by']);
        self::assertContains('archived', $types());
        $client->request('GET', '/api/admin/vault?view=trash');
        self::assertContains($f['asset'], array_column(json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'], 'id'));

        // Restore brings the asset back with its state and every prior event.
        self::assertSame(200, $this->post($client, '/api/admin/vault/'.$f['asset'].'/restore', [], $csrf, true)[0]);
        $client->request('GET', '/api/admin/vault');
        $active = array_column(json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'], null, 'id');
        self::assertSame('ready', $active[$f['asset']]['lifecycle_state']);
        $after = $types();
        foreach ($before as $type) {
            self::assertContains($type, $after, 'restore must keep the history');
        }
        self::assertNotContains('archived', $after);
        self::assertNull($db->fetchOne('SELECT deleted_at FROM gf_vault_assets WHERE id = ?', [$f['asset']]));
    }

    public function testPermanentDeleteRequiresAuthorizationAndPreservesMetrics(): void
    {
        [$client, $db, $f] = $this->fixture();
        $this->blob($f['asset']);
        $this->blob($f['trashed']);
        $draft = Uuid::v7()->toRfc4122();
        foreach ([$f['asset'], $f['trashed']] as $withHistory) {
            $db->insert('gf_content_review_events', [
                'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $withHistory,
                'actor_id' => $f['admin'], 'action' => 'approve', 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        $db->insert('gf_schedule_drafts', [
            'id' => $draft, 'organization_id' => $f['org'], 'asset_id' => $f['trashed'], 'created_by' => $f['admin'],
            'scheduled_at_utc' => gmdate('Y-m-d H:i:s', time() + 86400), 'timezone' => 'UTC', 'local_date' => gmdate('Y-m-d'),
            'local_time' => '10:00', 'status' => 'draft', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $purge = fn (string $asset, mixed $confirm, string $csrf, array $extra = []): array => $this->post(
            $client, '/api/admin/vault/'.$asset.'/purge', ['confirm_asset_id' => $confirm] + $extra, $csrf,
        );
        $path = static fn (string $asset): string => (string) static::getContainer()->getParameter('kernel.project_dir').'/var/vault/'.$asset.'.blob';

        // A role without the `delete` capability is refused even for a trashed asset.
        $this->login($client, $f['editor']);
        $editorCsrf = $this->csrf($client, 'vault_manage_csrf');
        self::assertSame(403, $purge($f['trashed'], $f['trashed'], $editorCsrf)[0]);
        self::assertFileExists($path($f['trashed']));

        $this->login($client, $f['admin']);
        $csrf = $this->csrf($client, 'vault_manage_csrf');
        $client->request('POST', '/api/admin/vault/'.$f['trashed'].'/purge', server: self::JSON, content: '{"confirm_asset_id":"'.$f['trashed'].'"}');
        self::assertResponseStatusCodeSame(403);
        foreach ([null, 'other', $f['asset'], 7] as $wrong) {
            self::assertSame(422, $purge($f['trashed'], $wrong, $csrf)[0]);
        }
        self::assertSame(422, $purge($f['trashed'], $f['trashed'], $csrf, ['force' => true])[0]);
        // Only an asset already in the trash, and only inside the organization.
        self::assertSame(409, $purge($f['asset'], $f['asset'], $csrf)[0]);
        self::assertFileExists($path($f['asset']));
        foreach ([$f['foreign_asset'], Uuid::v7()->toRfc4122()] as $unsafe) {
            self::assertSame(404, $purge($unsafe, $unsafe, $csrf)[0]);
        }
        // Individual restrictions can remove the capability even from an admin.
        /** @var LibraryPermissions $library */
        $library = static::getContainer()->get(LibraryPermissions::class);
        $library->deny($f['org'], $f['admin'], 'delete', $f['admin']);
        self::assertSame(403, $purge($f['trashed'], $f['trashed'], $csrf)[0]);
        $db->delete('gf_library_member_permissions', ['organization_id' => $f['org'], 'user_id' => $f['admin']]);
        self::assertFileExists($path($f['trashed']));

        $client->request('GET', '/api/admin/vault');
        $quotaBefore = json_decode((string) $client->getResponse()->getContent(), true)['data']['quota'];
        [$status, $payload] = $purge($f['trashed'], $f['trashed'], $csrf);
        self::assertSame(200, $status);
        self::assertSame('purged', $payload['data']['state']);
        self::assertTrue($payload['data']['storage_removed']);
        self::assertSame(
            ['schedule_drafts' => 1, 'content_reviews' => 1, 'distribution_authorizations' => 1, 'variants' => 0, 'lifecycle_events' => 0],
            $payload['data']['retained_metrics'],
        );
        self::assertFileDoesNotExist($path($f['trashed']));
        $row = $db->fetchAssociative('SELECT original_name, private_note, sha256, purged_at, purged_by, deleted_at FROM gf_vault_assets WHERE id = ?', [$f['trashed']]);
        self::assertSame('purged-'.substr($f['trashed'], 0, 8), $row['original_name']);
        self::assertNull($row['private_note']);
        self::assertSame(hash('sha256', 'purged|'.$f['trashed']), $row['sha256']);
        self::assertSame($f['admin'], $row['purged_by']);
        self::assertNotNull($row['purged_at']);
        // Historical metrics survive: every event and draft row is still there.
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_schedule_drafts WHERE asset_id = ?', [$f['trashed']]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_content_review_events WHERE asset_id = ?', [$f['trashed']]));
        $history = array_column($this->historyOf($client, $f['trashed'])['events'], 'type');
        self::assertContains('purged', $history);
        self::assertContains('content_review', $history);
        self::assertContains('schedule_draft', $history);
        // It no longer occupies quota, listing or the trash, and cannot be restored or purged again.
        $client->request('GET', '/api/admin/vault?view=trash');
        self::assertNotContains($f['trashed'], array_column(json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'], 'id'));
        $client->request('GET', '/api/admin/vault');
        $quotaAfter = json_decode((string) $client->getResponse()->getContent(), true)['data']['quota'];
        self::assertSame($quotaBefore['used_assets'] - 1, $quotaAfter['used_assets']);
        self::assertSame($quotaBefore['used_bytes'] - strlen((string) base64_decode(self::PNG)), $quotaAfter['used_bytes']);
        self::assertSame(404, $this->post($client, '/api/admin/vault/'.$f['trashed'].'/restore', [], $csrf, true)[0]);
        self::assertSame(409, $purge($f['trashed'], $f['trashed'], $csrf)[0]);
        // The untouched asset keeps its original.
        self::assertFileExists($path($f['asset']));
        @unlink($path($f['asset']));
    }

    private function blob(string $asset): void
    {
        $root = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/vault';
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            self::fail('Fixture private storage unavailable.');
        }
        file_put_contents($root.'/'.$asset.'.blob', base64_decode(self::PNG, true));
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function post(KernelBrowser $client, string $path, array $body, string $csrf, bool $emptyBody = false): array
    {
        $client->request('POST', $path, server: self::JSON + ['HTTP_X_CSRF_TOKEN' => $csrf], content: $emptyBody ? '' : json_encode($body, JSON_THROW_ON_ERROR));

        return [$client->getResponse()->getStatusCode(), json_decode((string) $client->getResponse()->getContent(), true) ?? []];
    }

    /** @return array<string, mixed> */
    private function historyOf(KernelBrowser $client, string $asset): array
    {
        $client->request('GET', '/api/admin/vault/'.$asset.'/history');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true)['data'];
    }

    /** @return array<string, mixed> */
    private function preview(KernelBrowser $client): array
    {
        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true)['data'];
    }

    private function firstSlot(KernelBrowser $client): string
    {
        $slots = $this->preview($client)['slots'];
        self::assertNotSame([], $slots);

        return (string) $slots[0]['scheduled_at_utc'];
    }

    private function csrf(KernelBrowser $client, string $key): string
    {
        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();

        return (string) (json_decode((string) $client->getResponse()->getContent(), true)['data'][$key] ?? '');
    }

    private function login(KernelBrowser $client, string $user): void
    {
        $client->restart();
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form(['email' => $user.'@example.test', 'password' => self::PASSWORD]));
        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());
        self::assertResponseRedirects('/admin');
    }

    /** @return array{0: KernelBrowser, 1: Connection, 2: array<string, string>} */
    private function fixture(): array
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $f = [];
        foreach (['admin', 'editor', 'model', 'org', 'foreign', 'asset', 'trashed', 'foreign_asset'] as $key) {
            $f[$key] = Uuid::v7()->toRfc4122();
        }
        $at = gmdate('Y-m-d H:i:s');
        $bytes = (string) base64_decode(self::PNG, true);
        foreach (['admin', 'editor', 'model'] as $key) {
            $db->insert('gf_identity_users', [
                'id' => $f[$key], 'name' => ucfirst($key).' user', 'email' => $f[$key].'@example.test',
                'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                'platform_role' => 'model', 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        foreach ([$f['org'] => 'Own workspace', $f['foreign'] => 'Other workspace'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name, 'slug' => 'ci-'.bin2hex(random_bytes(12)),
                'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        foreach (['admin' => 'admin', 'editor' => 'editor', 'model' => 'model'] as $key => $role) {
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $f[$key], 'organization_id' => $f['org'],
                'role' => $role, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        foreach (['asset', 'trashed', 'foreign_asset'] as $key) {
            $org = $key === 'foreign_asset' ? $f['foreign'] : $f['org'];
            $db->insert('gf_vault_assets', [
                'id' => $f[$key], 'organization_id' => $org, 'uploaded_by' => $f['admin'],
                'original_name' => $f[$key].'.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes), 'storage_key' => $f[$key], 'usage_scope' => 'needs_review',
                'private_note' => 'private note', 'created_at' => $at,
                'deleted_at' => $key === 'trashed' ? $at : null, 'deleted_by' => $key === 'trashed' ? $f['admin'] : null,
            ]);
            if ($org === $f['org']) {
                // Reviews are inserted per test where the history matters.
                $db->insert('gf_distribution_authorization_events', [
                    'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $org, 'asset_id' => $f[$key],
                    'actor_id' => $f['admin'], 'action' => 'grant', 'created_at' => $at,
                ]);
            }
        }
        $db->insert('gf_content_rules', [
            'organization_id' => $f['org'], 'timezone' => 'UTC', 'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
            'local_time' => '23:59', 'max_per_day' => 5, 'mode' => 'review_only', 'updated_by' => $f['admin'],
            'created_at' => $at, 'updated_at' => $at,
        ]);

        return [$client, $db, $f];
    }
}
