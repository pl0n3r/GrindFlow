<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Scheduling\ScheduleDraftApplicationService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Library MVP #41: optional UTC validity window, fail-closed for automation (disposable DB only). */
final class VaultValidityTest extends WebTestCase
{
    private const JSON = ['CONTENT_TYPE' => 'application/json'];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testContentPrepareCanSetAndClearUtcValidityWithoutCrossTenantMutation(): void
    {
        [$client, $db, $f] = $this->fixture(login: false);
        $endpoint = static fn (string $id): string => '/api/admin/vault/'.$id.'/validity';
        $body = static fn (mixed $from, mixed $until): string => json_encode(
            ['valid_from_at' => $from, 'valid_until_at' => $until], JSON_THROW_ON_ERROR,
        );
        $read = static fn (string $id): array => $db->fetchAssociative(
            'SELECT valid_from_at, valid_until_at FROM gf_vault_assets WHERE id = ?', [$id],
        );

        // Unauthenticated, missing CSRF.
        $client->request('POST', $endpoint($f['asset']), server: self::JSON, content: $body(null, null));
        self::assertResponseStatusCodeSame(401);
        $f = $this->login($client, $f);
        $client->request('POST', $endpoint($f['asset']), server: self::JSON, content: $body(null, null));
        self::assertResponseStatusCodeSame(403);

        $headers = self::JSON + ['HTTP_X_CSRF_TOKEN' => $f['csrf']];
        // Invalid windows are rejected without touching the asset.
        foreach ([
            '{}', '{"valid_from_at":null}',
            $body('2030-01-01 00:00:00', null), $body('2030-01-01T00:00:00+02:00', null),
            $body('2030-02-30T00:00:00Z', null), $body(20300101, null), $body(null, ''),
            $body('2030-01-02T00:00:00Z', '2030-01-01T00:00:00Z'),
            $body('2030-01-01T00:00:00Z', '2030-01-01T00:00:00Z'),
            json_encode(['valid_from_at' => null, 'valid_until_at' => null, 'organization_id' => $f['foreign']], JSON_THROW_ON_ERROR),
        ] as $invalid) {
            $client->request('POST', $endpoint($f['asset']), server: $headers, content: $invalid);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['valid_from_at' => null, 'valid_until_at' => null], $read($f['asset']));
        }

        // Cross tenant / trashed / missing assets are indistinguishable 404s and never mutate.
        foreach ([$f['foreign_asset'], $f['trashed'], Uuid::v7()->toRfc4122()] as $unsafe) {
            $client->request('POST', $endpoint($unsafe), server: $headers,
                content: $body('2030-01-01T00:00:00Z', '2031-01-01T00:00:00Z'));
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame(['valid_from_at' => null, 'valid_until_at' => null], $read($f['foreign_asset']));
        self::assertSame(['valid_from_at' => null, 'valid_until_at' => null], $read($f['trashed']));

        // Set, re-send (idempotent) and clear.
        $client->request('POST', $endpoint($f['asset']), server: $headers,
            content: $body('2030-01-01T00:00:00Z', '2031-01-01T12:30:15Z'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $saved = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame([
            'id' => $f['asset'], 'valid_from_at' => '2030-01-01T00:00:00Z',
            'valid_until_at' => '2031-01-01T12:30:15Z', 'validity_state' => 'not_yet_valid',
        ], $saved);
        self::assertSame(
            ['valid_from_at' => '2030-01-01 00:00:00', 'valid_until_at' => '2031-01-01 12:30:15'],
            $read($f['asset']),
        );
        $client->request('POST', $endpoint($f['asset']), server: $headers,
            content: $body('2030-01-01T00:00:00Z', '2031-01-01T12:30:15Z'));
        self::assertResponseIsSuccessful();
        $client->request('POST', $endpoint($f['asset']), server: $headers, content: $body(null, null));
        self::assertResponseIsSuccessful();
        self::assertSame('valid', json_decode((string) $client->getResponse()->getContent(), true)['data']['validity_state']);
        self::assertSame(['valid_from_at' => null, 'valid_until_at' => null], $read($f['asset']));

        // A role without content_prepare cannot edit, even with a captured token.
        $db->update('gf_identity_memberships', ['role' => 'model'], [
            'user_id' => $f['user'], 'organization_id' => $f['organization'],
        ]);
        $client->request('POST', $endpoint($f['asset']), server: $headers,
            content: $body('2030-01-01T00:00:00Z', null));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['valid_from_at' => null, 'valid_until_at' => null], $read($f['asset']));
    }

    public function testExpiredAssetRemainsVisibleWithStableValidityState(): void
    {
        [$client, $db, $f] = $this->fixture();
        $past = gmdate('Y-m-d H:i:s', time() - 7200);
        $recent = gmdate('Y-m-d H:i:s', time() - 3600);
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $db->update('gf_vault_assets', ['valid_from_at' => $past, 'valid_until_at' => $recent], ['id' => $f['asset']]);
        $db->update('gf_vault_assets', ['valid_from_at' => $future], ['id' => $f['second']]);
        $db->update('gf_vault_assets', ['valid_from_at' => $past, 'valid_until_at' => $future], ['id' => $f['third']]);
        $db->update('gf_vault_assets', ['valid_until_at' => $recent], ['id' => $f['trashed']]);

        $client->request('GET', '/api/admin/vault');
        self::assertResponseIsSuccessful();
        $listed = json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'];
        $states = array_column($listed, 'validity_state', 'id');
        // Expired and not-yet-valid assets stay in Library by the normal lifecycle.
        self::assertSame('expired', $states[$f['asset']]);
        self::assertSame('not_yet_valid', $states[$f['second']]);
        self::assertSame('valid', $states[$f['third']]);
        self::assertArrayNotHasKey($f['trashed'], $states);
        foreach ($listed as $asset) {
            // Only sanitized bounds and a derived state; no license/approval semantics.
            self::assertSame(
                ['valid_from_at', 'valid_until_at', 'validity_state'],
                array_values(array_filter(array_keys($asset), static fn (string $key): bool => str_starts_with($key, 'valid'))),
            );
            self::assertArrayNotHasKey('licensed', $asset);
            self::assertArrayNotHasKey('can_publish', $asset);
        }
        $expired = array_values(array_filter($listed, static fn (array $row): bool => $row['id'] === $f['asset']))[0];
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', strtotime($past.' UTC')), $expired['valid_from_at']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', strtotime($recent.' UTC')), $expired['valid_until_at']);

        $client->request('GET', '/api/admin/vault/'.$f['asset']);
        self::assertResponseIsSuccessful();
        $detail = json_decode((string) $client->getResponse()->getContent(), true)['data']['asset'];
        self::assertSame('expired', $detail['validity_state']);
        self::assertSame($expired['valid_until_at'], $detail['valid_until_at']);

        // An expired original can still be trashed and restored: lifecycle is unchanged.
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/trash', server: self::JSON + ['HTTP_X_CSRF_TOKEN' => $f['csrf']]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/admin/vault?view=trash');
        self::assertResponseIsSuccessful();
        $trash = json_decode((string) $client->getResponse()->getContent(), true)['data']['assets'];
        self::assertContains($f['asset'], array_column($trash, 'id'));
        self::assertSame('expired', array_column($trash, 'validity_state', 'id')[$f['asset']]);

        // Single source of truth: half-open [from, until) in UTC, null = open.
        $state = ScheduleDraftApplicationService::validityState(...);
        self::assertSame('valid', $state(null, null, '2030-01-01 00:00:00'));
        self::assertSame('not_yet_valid', $state('2030-01-01 00:00:00', null, '2029-12-31 23:59:59'));
        self::assertSame('valid', $state('2030-01-01 00:00:00', '2030-01-02 00:00:00', '2030-01-01 00:00:00'));
        self::assertSame('expired', $state('2030-01-01 00:00:00', '2030-01-02 00:00:00', '2030-01-02 00:00:00'));
        self::assertSame('expired', $state(null, '2030-01-02 00:00:00', '2031-01-01 00:00:00'));
    }

    public function testPreviewAndDraftFailClosedOutsideValidityWindow(): void
    {
        [$client, $db, $f] = $this->fixture(withRule: true);
        $headers = self::JSON + ['HTTP_X_CSRF_TOKEN' => $f['schedule_csrf']];
        $reserve = function (string $asset, string $slot) use ($client, $headers): array {
            $client->request('POST', '/api/admin/schedules', server: $headers, content: json_encode([
                'asset_id' => $asset, 'scheduled_at_utc' => $slot,
            ], JSON_THROW_ON_ERROR));

            return [$client->getResponse()->getStatusCode(), json_decode((string) $client->getResponse()->getContent(), true)];
        };
        $preview = function () use ($client): array {
            $client->request('GET', '/api/admin/rules/weekly/preview');
            self::assertResponseIsSuccessful();

            return json_decode((string) $client->getResponse()->getContent(), true)['data'];
        };

        $data = $preview();
        $slot = $data['slots'][0]['scheduled_at_utc'];
        $byId = static fn (array $payload): array => array_column($payload['assets'], null, 'id');
        // Baseline: review + authorization valid and an open window is eligible.
        self::assertTrue($byId($data)[$f['asset']]['eligible']);
        self::assertSame('valid', $byId($data)[$f['asset']]['validity_state']);

        $slotDb = str_replace(['T', 'Z'], [' ', ''], $slot);
        $windows = [
            'expired' => [gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 60), 'asset_expired'],
            'not_yet_valid' => [gmdate('Y-m-d H:i:s', time() + 86400 * 400), null, 'asset_not_yet_valid'],
            // Valid right now, but the window closes before the requested slot.
            'closes_before_slot' => [null, gmdate('Y-m-d H:i:s', max(time() + 60, strtotime($slotDb.' UTC') - 60)), 'asset_expired'],
        ];
        foreach ($windows as $label => [$from, $until, $reason]) {
            $db->update('gf_vault_assets', ['valid_from_at' => $from, 'valid_until_at' => $until], ['id' => $f['asset']]);
            $row = $byId($preview())[$f['asset']];
            if ($label !== 'closes_before_slot') {
                self::assertFalse($row['eligible'], $label);
                self::assertContains($reason, $row['blocking_reasons'], $label);
            }
            [$status, $payload] = $reserve($f['asset'], $slot);
            self::assertSame(409, $status, $label);
            self::assertSame('asset_not_eligible', $payload['error']['code'], $label);
            self::assertContains($reason, $payload['error']['blocking_reasons'], $label);
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_schedule_drafts WHERE asset_id = ?', [$f['asset']],
            ), $label.': no draft may be written');
        }

        // Clearing the window restores the original review/authorization behavior.
        $db->update('gf_vault_assets', ['valid_from_at' => null, 'valid_until_at' => null], ['id' => $f['asset']]);
        [$status] = $reserve($f['asset'], $slot);
        self::assertSame(201, $status);
    }

    /**
     * @return array{0: KernelBrowser, 1: Connection, 2: array<string, string>}
     */
    private function fixture(bool $withRule = false, bool $login = true): array
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $f = [];
        foreach (['user', 'organization', 'foreign', 'asset', 'second', 'third', 'trashed', 'foreign_asset'] as $key) {
            $f[$key] = Uuid::v7()->toRfc4122();
        }
        $at = gmdate('Y-m-d H:i:s');
        $password = 'validity-fixture-only-123';
        $db->insert('gf_identity_users', [
            'id' => $f['user'], 'name' => 'Validity editor', 'email' => $f['user'].'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ([$f['organization'] => 'Own workspace', $f['foreign'] => 'Other workspace'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name, 'slug' => 'ci-'.substr($id, 0, 30),
                'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $f['user'],
            'organization_id' => $f['organization'], 'role' => 'admin',
            'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ([
            $f['asset'] => [$f['organization'], 'needs_review'], $f['second'] => [$f['organization'], 'needs_review'],
            $f['third'] => [$f['organization'], 'needs_review'], $f['trashed'] => [$f['organization'], 'needs_review'],
            $f['foreign_asset'] => [$f['foreign'], 'needs_review'],
        ] as $id => [$organization, $scope]) {
            $db->insert('gf_vault_assets', [
                'id' => $id, 'organization_id' => $organization, 'uploaded_by' => $f['user'],
                'original_name' => $id.'.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
                'sha256' => hash('sha256', $id), 'storage_key' => $id, 'usage_scope' => $scope,
                'created_at' => $at, 'deleted_at' => $id === $f['trashed'] ? $at : null,
            ]);
            if ($organization === $f['organization']) {
                foreach (['gf_content_review_events' => 'approve', 'gf_distribution_authorization_events' => 'grant'] as $table => $action) {
                    $db->insert($table, [
                        'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $organization, 'asset_id' => $id,
                        'actor_id' => $f['user'], 'action' => $action, 'created_at' => $at,
                    ]);
                }
            }
        }
        if ($withRule) {
            $db->insert('gf_content_rules', [
                'organization_id' => $f['organization'], 'timezone' => 'UTC',
                'weekdays' => 'mon,tue,wed,thu,fri,sat,sun', 'local_time' => '23:59', 'max_per_day' => 3,
                'mode' => 'review_only', 'updated_by' => $f['user'], 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        if ($login) {
            $f = $this->login($client, $f);
        }

        return [$client, $db, $f];
    }

    /**
     * @param array<string, string> $f
     * @return array<string, string>
     */
    private function login(KernelBrowser $client, array $f): array
    {
        $password = 'validity-fixture-only-123';
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $f['user'].'@example.test', 'password' => $password,
        ]));
        self::assertResponseRedirects('/organizations');
        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());
        self::assertResponseRedirects('/admin');
        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        $f['csrf'] = (string) $context['vault_manage_csrf'];
        $f['schedule_csrf'] = (string) ($context['schedule_draft_csrf'] ?? '');
        self::assertNotSame('', $f['csrf']);

        return $f;
    }
}
