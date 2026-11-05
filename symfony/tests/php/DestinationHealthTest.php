<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GrindFlow\Distribution\DestinationHealthService;
use GrindFlow\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class DestinationHealthTest extends TestCase
{
    private Connection $db;
    private DestinationHealthService $health;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE gf_external_publication_attempts (
                id TEXT PRIMARY KEY,
                organization_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                status TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);
        $this->health = new DestinationHealthService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testHealthClassifierAndFailClosed(): void
    {
        $at = new DateTimeImmutable('2026-11-05T12:00:00Z');
        $fresh = ['credential' => 'verified', 'permission' => 'granted',
            'provider_availability' => 'available', 'verified_at' => '2026-11-05T11:59:30Z'];
        self::assertSame('unknown', $this->health->classify([], $at)['state']);
        self::assertSame('connected', $this->health->classify($fresh, $at)['state']);
        self::assertTrue($this->health->classify($fresh, $at)['can_enqueue']);
        self::assertSame('unknown', $this->health->classify(
            [...$fresh, 'verified_at' => '2026-11-05T11:40:00Z'], $at,
        )['state']);
        self::assertSame('unknown', $this->health->classify(
            [...$fresh, 'verified_at' => '2026-11-05T12:05:00Z'], $at,
        )['state']);
        self::assertSame('unknown', $this->health->classify(
            ['last_delivery_status' => 'published'], $at,
        )['state']);
        foreach ([
            [['credential' => 'revoked'], 'attention_required', 'token_revoked'],
            [['credential' => 'expired'], 'attention_required', 'token_expired'],
            [['credential' => 'permission_revoked'], 'attention_required', 'permission_revoked'],
            [['credential' => 'disconnected'], 'blocked', 'destination_disconnected'],
            [['disabled' => true], 'blocked', 'destination_disconnected'],
            [['last_delivery_status' => 'authentication_failed'], 'attention_required', 'token_revoked'],
            [['last_delivery_status' => 'rate_limited'], 'temporarily_unavailable', 'provider_temporarily_unavailable'],
            [['last_delivery_status' => 'ambiguous'], 'blocked', 'ambiguous_delivery'],
            [['provider_availability' => 'down'], 'temporarily_unavailable', 'provider_temporarily_unavailable'],
        ] as [$signals, $state, $reason]) {
            $result = $this->health->classify([...$fresh, ...$signals], $at);
            self::assertSame($state, $result['state']);
            self::assertSame($reason, $result['reason_code']);
            self::assertFalse($result['can_enqueue']);
        }
        $malicious = $this->health->classify([
            'credential' => 'TOKEN_SECRET', 'last_delivery_status' => 'API_BODY_SECRET',
            'verified_at' => "malformed TOKEN_SECRET\n",
        ], $at);
        self::assertSame('unknown', $malicious['state']);
        self::assertStringNotContainsString('SECRET', json_encode($malicious, JSON_THROW_ON_ERROR));
    }

    public function testTenantBoundaryAndSelectivePause(): void
    {
        $at = new DateTimeImmutable('2026-11-05T12:00:00Z');
        $tenant = Uuid::v7()->toRfc4122();
        $otherTenant = Uuid::v7()->toRfc4122();
        $this->db->insert('gf_external_publication_attempts', [
            'id' => '1', 'organization_id' => $tenant, 'provider' => 'facebook_page',
            'status' => 'authentication_failed', 'updated_at' => '2026-11-05 11:50:00',
        ]);
        $this->db->insert('gf_external_publication_attempts', [
            'id' => '2', 'organization_id' => $otherTenant, 'provider' => 'facebook_page',
            'status' => 'published', 'updated_at' => '2026-11-05 11:51:00',
        ]);

        $mine = $this->health->forOrganization($tenant, $at);
        $theirs = $this->health->forOrganization($otherTenant, $at);
        self::assertSame('attention_required', $mine[0]['state']);
        self::assertSame('unknown', $theirs[0]['state']);
        self::assertSame('unknown', $mine[1]['state']);
        self::assertStringNotContainsString($otherTenant, json_encode($mine, JSON_THROW_ON_ERROR));

        $sendCalls = [];
        $connected = ['credential' => 'verified', 'permission' => 'granted',
            'provider_availability' => 'available', 'verified_at' => '2026-11-05T11:59:30Z'];
        $candidates = [
            ['organization_id' => $tenant, 'provider' => 'facebook_page',
                'destination_id' => '11', 'signals' => ['credential' => 'revoked']],
            ['organization_id' => $tenant, 'provider' => 'instagram_reels',
                'destination_id' => '22', 'signals' => $connected],
            ['organization_id' => $otherTenant, 'provider' => 'facebook_page',
                'destination_id' => '33', 'signals' => $connected],
            ['organization_id' => $tenant, 'provider' => 'facebook_page',
                'destination_id' => '44', 'signals' => ['last_delivery_status' => 'ambiguous']],
        ];
        $result = $this->health->dispatchEligible(
            $tenant, $candidates,
            static function (string $provider, string $id) use (&$sendCalls): void {
                $sendCalls[] = $provider.':'.$id;
            },
            $at,
        );
        self::assertSame(['instagram_reels:22'], $sendCalls);
        self::assertSame([false, true, false, false], array_column($result, 'dispatched'));
        self::assertSame(['attention_required', 'connected', 'blocked', 'blocked'], array_column($result, 'state'));
        self::assertSame('unavailable', $result[2]['destination_id']);
    }
}

final class DestinationHealthHttpTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAuthenticatedOrganizationScopeCannotEnumerateOtherTenant(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/admin/destination-health');
        self::assertResponseStatusCodeSame(401);

        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $password = 'SYNTHETIC-health-tenant-password-123';
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Health Fixture', 'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([$mine => 'Current', $other => 'Foreign'] as $id => $label) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $label,
                'slug' => 'health-'.substr($id, 0, 28),
                'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user,
            'organization_id' => $mine, 'role' => 'model',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $user.'@example.test', 'password' => $password,
        ]));
        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());

        $client->request('GET', '/api/admin/destination-health/'.$other);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/admin/destination-health');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('selected_organization', $body['data']['scope']);
        self::assertSame('independent', $body['data']['view']);
        self::assertSame($mine, $body['data']['organization']['id']);
        self::assertFalse($body['data']['provider_calls']);
        self::assertFalse($body['data']['verified_provider_connection']);
        self::assertCount(2, $body['data']['destinations']);
        self::assertNotContains('connected', array_column($body['data']['destinations'], 'state'));
        self::assertStringNotContainsString($other, (string) $client->getResponse()->getContent());
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        $db->update('gf_identity_organizations', ['type' => 'studio'], ['id' => $mine]);
        $client->request('GET', '/api/admin/destination-health');
        self::assertResponseIsSuccessful();
        self::assertSame('studio', json_decode((string) $client->getResponse()->getContent(), true)['data']['view']);
    }
}
