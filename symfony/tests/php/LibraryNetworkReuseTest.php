<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Library\AssetNetworkRules;
use GrindFlow\Scheduling\ScheduleDraftApplicationService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Library #45/#46: per-asset network rules and reuse policy (disposable DB only). */
final class LibraryNetworkReuseTest extends WebTestCase
{
    private const JSON = ['CONTENT_TYPE' => 'application/json'];
    private const PASSWORD = 'network-fixture-only-123';
    private const FB = 'facebook_page';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testDeniedNetworkIsNeverScheduled(): void
    {
        [$client, $db, $f] = $this->fixture();
        $rule = fn (mixed $network, mixed $effect, ?string $asset = null, array $extra = []): array => $this->post(
            $client, '/api/admin/vault/'.($asset ?? $f['asset']).'/network-rules',
            ['network' => $network, 'effect' => $effect] + $extra, $f['csrf'],
        );
        $slot = $this->firstSlot($client);

        // Baseline: nothing denies the asset.
        [$status] = $this->reserveHttp($client, $f, $f['second'], $slot);
        self::assertSame(201, $status);

        // Management API is strict, CSRF-protected and tenant-safe.
        $client->request('POST', '/api/admin/vault/'.$f['asset'].'/network-rules', server: self::JSON,
            content: '{"network":"facebook_page","effect":"deny"}');
        self::assertResponseStatusCodeSame(403);
        foreach ([['instagram', 'deny'], [self::FB, 'block'], [7, 'deny'], [self::FB, 1]] as [$network, $effect]) {
            self::assertSame(422, $rule($network, $effect)[0]);
        }
        self::assertSame(422, $rule(self::FB, 'deny', null, ['organization_id' => $f['foreign']])[0]);
        foreach ([$f['foreign_asset'], $f['trashed'], Uuid::v7()->toRfc4122()] as $unsafe) {
            self::assertSame(404, $rule(self::FB, 'deny', $unsafe)[0]);
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_vault_asset_network_rules'));

        // Deny: reservation (manual and automatic path) and preview are blocked with a stable reason.
        [$status, $payload] = $rule(self::FB, 'deny');
        self::assertSame(200, $status);
        self::assertSame([['network' => self::FB, 'effect' => 'deny']], $payload['data']['rules']);
        [$status, $payload] = $this->reserveHttp($client, $f, $f['asset'], $slot);
        self::assertSame(409, $status);
        self::assertSame('asset_not_eligible', $payload['error']['code']);
        self::assertContains('network_denied', $payload['error']['blocking_reasons']);
        /** @var ScheduleDraftApplicationService $service */
        $service = static::getContainer()->get(ScheduleDraftApplicationService::class);
        $automatic = $service->reserve($f['org'], $f['user'], $f['asset'], $slot, true, '123456');
        self::assertSame('blocked', $automatic['status']);
        self::assertContains('network_denied', $automatic['reasons']);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_schedule_drafts WHERE asset_id = ?', [$f['asset']]));
        $assets = array_column($this->preview($client)['assets'], null, 'id');
        self::assertFalse($assets[$f['asset']]['eligible']);
        self::assertContains('network_denied', $assets[$f['asset']]['blocking_reasons']);
        // Other assets and other networks are unaffected; a rule never grants publication.
        self::assertTrue($assets[$f['third']]['eligible']);
        $rules = static::getContainer()->get(AssetNetworkRules::class);
        self::assertTrue($rules->isDenied($f['org'], $f['asset'], self::FB));
        self::assertFalse($rules->isDenied($f['org'], $f['asset'], 'instagram_reels'));
        self::assertFalse($rules->isDenied($f['org'], $f['second'], self::FB));
        self::assertTrue($rules->isDenied($f['org'], $f['asset'], 'tiktok'), 'unknown network is denied');
        self::assertFalse($this->preview($client)['can_publish']);

        // Explicit allow and clearing restore the previous behavior.
        self::assertSame(200, $rule(self::FB, 'allow')[0]);
        self::assertSame(201, $this->reserveHttp($client, $f, $f['asset'], $slot)[0]);
        self::assertSame(200, $rule(self::FB, null)[0]);
        self::assertSame([], $rules->forAsset($f['org'], $f['asset'])['rules']);

        // A role without edit cannot change rules.
        $db->update('gf_identity_memberships', ['role' => 'model'], ['user_id' => $f['user'], 'organization_id' => $f['org']]);
        self::assertSame(403, $rule(self::FB, 'deny')[0]);
    }

    public function testMaxNAndMinimumIntervalAreEnforced(): void
    {
        [$client, $db, $f] = $this->fixture();
        /** @var AssetNetworkRules $rules */
        $rules = static::getContainer()->get(AssetNetworkRules::class);
        $base = 1_900_000_000;
        $at = static fn (int $hours): string => gmdate('Y-m-d H:i:s', $base + $hours * 3600);
        $draft = function (string $asset, int $hours, string $status = 'draft', ?string $provider = null) use ($db, $f, $at, $base): void {
            $db->insert('gf_schedule_drafts', [
                'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $asset,
                'created_by' => $f['user'], 'scheduled_at_utc' => $at($hours), 'timezone' => 'UTC',
                'local_date' => gmdate('Y-m-d', $base + $hours * 3600), 'local_time' => gmdate('H:i', $base + $hours * 3600),
                'status' => $status, 'created_at' => gmdate('Y-m-d H:i:s'),
                'delivery_provider' => $provider, 'delivery_destination_id' => $provider === null ? null : '1234567890',
                'cancelled_at' => $status === 'cancelled' ? gmdate('Y-m-d H:i:s') : null,
                'cancelled_by' => $status === 'cancelled' ? $f['user'] : null,
            ]);
        };
        $policy = fn (string $network, ?string $mode, ?int $max, int $interval): bool => $rules->setPolicy(
            $f['org'], $f['asset'], $network, $mode, $max, $interval, $f['user'],
        );
        $reasons = static fn (int $hours, string $network = self::FB): array => $rules->blockingReasons($f['org'], $f['asset'], $network, $at($hours));

        self::assertSame([], $reasons(0), 'no policy: unlimited');
        // single_use: one existing draft at another time exhausts it; the same slot is the same draft.
        self::assertTrue($policy(self::FB, 'single_use', null, 0));
        self::assertSame([], $reasons(0));
        $draft($f['asset'], 100);
        self::assertSame(['reuse_exhausted'], $reasons(0));
        self::assertSame([], $reasons(100), 'idempotent re-reservation of the same slot');
        // max_n counts only non-cancelled drafts of that network.
        self::assertTrue($policy(self::FB, 'max_n', 2, 0));
        self::assertSame([], $reasons(0));
        $draft($f['asset'], 200);
        $draft($f['asset'], 300, 'cancelled');
        self::assertSame(['reuse_exhausted'], $reasons(0));
        self::assertSame([], $reasons(0, 'instagram_reels'), 'other network has no policy');
        self::assertTrue($policy(self::FB, 'max_n', 3, 0));
        self::assertSame([], $reasons(0), 'cancelled drafts never count');
        // Minimum interval around every counted draft.
        self::assertTrue($policy(self::FB, 'unlimited', null, 24));
        self::assertSame(['reuse_interval'], $reasons(110));
        self::assertSame(['reuse_interval'], $reasons(77), '23h before another use');
        self::assertSame([], $reasons(76), 'exactly 24h away is allowed');
        self::assertSame([], $reasons(100), 'same slot = same draft');
        self::assertSame(['reuse_interval'], $reasons(223));
        // Generic `any` policy counts every network; a network-specific policy wins over it.
        $draft($f['asset'], 400, 'draft', self::FB);
        self::assertTrue($policy(self::FB, null, null, 0));
        self::assertTrue($policy('any', 'max_n', 3, 0));
        self::assertSame(['reuse_exhausted'], $reasons(0, self::FB), '3 uses across networks');
        self::assertTrue($policy(self::FB, 'unlimited', null, 0));
        self::assertSame([], $reasons(0, self::FB), 'specific policy overrides any');
        // Validation of impossible policies.
        foreach ([['max_n', null, 0], ['max_n', 0, 0], ['max_n', 1001, 0], ['single_use', 2, 0], ['unlimited', null, 9000], ['forever', null, 0]] as [$mode, $max, $interval]) {
            self::assertFalse($policy(self::FB, $mode, $max, $interval), "$mode/$max/$interval");
        }
        self::assertFalse($rules->setPolicy($f['org'], $f['foreign_asset'], self::FB, 'single_use', null, 0, $f['user']), 'cross-tenant asset');
        self::assertFalse($rules->setPolicy($f['org'], $f['asset'], 'tiktok', 'single_use', null, 0, $f['user']));

        // The reservation applies the same policy at the requested slot (HTTP, real slot).
        $slot = $this->firstSlot($client);
        $slotDb = str_replace(['T', 'Z'], [' ', ''], $slot);
        self::assertTrue($policy(self::FB, 'single_use', null, 0));
        self::assertTrue($policy('any', null, null, 0));
        $db->insert('gf_schedule_drafts', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $f['org'], 'asset_id' => $f['asset'],
            'created_by' => $f['user'], 'scheduled_at_utc' => gmdate('Y-m-d H:i:s', strtotime($slotDb.' UTC') + 7 * 86400),
            'timezone' => 'UTC', 'local_date' => '2030-01-01', 'local_time' => '10:00', 'status' => 'draft', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        [$status, $payload] = $this->reserveHttp($client, $f, $f['asset'], $slot);
        self::assertSame(409, $status);
        self::assertContains('reuse_exhausted', $payload['error']['blocking_reasons']);
        self::assertContains(
            'reuse_exhausted',
            array_column($this->preview($client)['assets'], null, 'id')[$f['asset']]['blocking_reasons'],
        );
        // The management API sets and clears policies with a strict body.
        $post = fn (array $body): array => $this->post($client, '/api/admin/vault/'.$f['second'].'/reuse-policy', $body, $f['csrf']);
        self::assertSame(200, $post(['network' => 'any', 'mode' => 'max_n', 'max_uses' => 3, 'min_interval_hours' => 12])[0]);
        self::assertSame(
            [['network' => 'any', 'mode' => 'max_n', 'max_uses' => 3, 'min_interval_hours' => 12]],
            $rules->forAsset($f['org'], $f['second'])['policies'],
        );
        foreach ([
            ['network' => 'any', 'mode' => 'max_n', 'max_uses' => null, 'min_interval_hours' => 0],
            ['network' => 'any', 'mode' => 'forever', 'max_uses' => null, 'min_interval_hours' => 0],
            ['network' => 'tiktok', 'mode' => 'single_use', 'max_uses' => null, 'min_interval_hours' => 0],
            ['network' => 'any', 'mode' => 'single_use', 'max_uses' => null, 'min_interval_hours' => -1],
            ['network' => 'any', 'mode' => null, 'max_uses' => null, 'min_interval_hours' => 5],
            ['network' => 'any', 'mode' => 'single_use'],
        ] as $invalid) {
            self::assertSame(422, $post($invalid)[0], json_encode($invalid));
        }
        self::assertSame(200, $post(['network' => 'any', 'mode' => null, 'max_uses' => null, 'min_interval_hours' => 0])[0]);
        self::assertSame([], $rules->forAsset($f['org'], $f['second'])['policies']);
    }

    public function testMissingRulesApplyDocumentedSafeDefault(): void
    {
        [$client, $db, $f] = $this->fixture();
        /** @var AssetNetworkRules $rules */
        $rules = static::getContainer()->get(AssetNetworkRules::class);
        $now = gmdate('Y-m-d H:i:s');
        // No rule and no policy: allowed (documented default), also visible in the API.
        self::assertSame([], $rules->blockingReasons($f['org'], $f['asset'], self::FB, $now));
        self::assertSame([], $rules->blockingReasons($f['org'], $f['asset'], 'instagram_reels', $now));
        self::assertSame(['rules' => [], 'policies' => []], $rules->forAsset($f['org'], $f['asset']));
        $client->request('GET', '/api/admin/vault/'.$f['asset'].'/network-rules');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame(['facebook_page', 'instagram_reels'], $data['networks']);
        self::assertSame([], $data['rules']);
        $client->request('GET', '/api/admin/vault/'.$f['foreign_asset'].'/network-rules');
        self::assertResponseStatusCodeSame(404);
        // Fail closed: unknown network, malformed instant, or any evaluation failure.
        self::assertSame(['network_unknown'], $rules->blockingReasons($f['org'], $f['asset'], 'tiktok', $now));
        self::assertSame(['network_unknown'], $rules->blockingReasons($f['org'], $f['asset'], self::FB, 'tomorrow'));
        $broken = $this->createMock(Connection::class);
        $broken->method('fetchOne')->willThrowException(new \RuntimeException('database unavailable'));
        $broken->method('fetchAssociative')->willThrowException(new \RuntimeException('database unavailable'));
        $failing = new AssetNetworkRules($broken);
        self::assertSame(['network_unknown'], $failing->blockingReasons($f['org'], $f['asset'], self::FB, $now));
        self::assertTrue($failing->isDenied($f['org'], $f['asset'], self::FB));
        // The scheduler keeps retrying an asset whose block depends on the slot.
        self::assertSame(['asset_not_yet_valid', 'asset_expired', 'reuse_interval'], ScheduleDraftApplicationService::SLOT_DEPENDENT_REASONS);
        self::assertSame(['network_denied', 'network_unknown', 'reuse_exhausted', 'reuse_interval'], AssetNetworkRules::REASONS);
    }

    /** @param array<string, string> $f */
    private function reserveHttp(KernelBrowser $client, array $f, string $asset, string $slot): array
    {
        return $this->post($client, '/api/admin/schedules', ['asset_id' => $asset, 'scheduled_at_utc' => $slot], $f['schedule_csrf']);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function post(KernelBrowser $client, string $path, array $body, string $csrf): array
    {
        $client->request('POST', $path, server: self::JSON + ['HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body, JSON_THROW_ON_ERROR));

        return [$client->getResponse()->getStatusCode(), json_decode((string) $client->getResponse()->getContent(), true) ?? []];
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

    /** @return array{0: KernelBrowser, 1: Connection, 2: array<string, string>} */
    private function fixture(): array
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $f = [];
        foreach (['user', 'org', 'foreign', 'asset', 'second', 'third', 'trashed', 'foreign_asset'] as $key) {
            $f[$key] = Uuid::v7()->toRfc4122();
        }
        $at = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $f['user'], 'name' => 'Network editor', 'email' => $f['user'].'@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ([$f['org'] => 'Own workspace', $f['foreign'] => 'Other workspace'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name, 'slug' => 'ci-'.bin2hex(random_bytes(12)), // v7 ids created back to back share their first 30 chars
                'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $f['user'], 'organization_id' => $f['org'],
            'role' => 'admin', 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach (['asset', 'second', 'third', 'trashed', 'foreign_asset'] as $key) {
            $org = $key === 'foreign_asset' ? $f['foreign'] : $f['org'];
            $db->insert('gf_vault_assets', [
                'id' => $f[$key], 'organization_id' => $org, 'uploaded_by' => $f['user'],
                'original_name' => $f[$key].'.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
                'sha256' => hash('sha256', $f[$key]), 'storage_key' => $f[$key], 'usage_scope' => 'needs_review',
                'created_at' => $at, 'deleted_at' => $key === 'trashed' ? $at : null,
            ]);
            if ($org === $f['org']) {
                foreach (['gf_content_review_events' => 'approve', 'gf_distribution_authorization_events' => 'grant'] as $table => $action) {
                    $db->insert($table, [
                        'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $org, 'asset_id' => $f[$key],
                        'actor_id' => $f['user'], 'action' => $action, 'created_at' => $at,
                    ]);
                }
            }
        }
        $db->insert('gf_content_rules', [
            'organization_id' => $f['org'], 'timezone' => 'UTC', 'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
            'local_time' => '23:59', 'max_per_day' => 5, 'mode' => 'review_only', 'updated_by' => $f['user'],
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form(['email' => $f['user'].'@example.test', 'password' => self::PASSWORD]));
        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());
        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        $f['csrf'] = (string) $context['vault_manage_csrf'];
        $f['schedule_csrf'] = (string) $context['schedule_draft_csrf'];

        return [$client, $db, $f];
    }
}
