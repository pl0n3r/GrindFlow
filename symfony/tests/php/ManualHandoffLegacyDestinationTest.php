<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ManualHandoffLegacyDestinationTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testPreparedLegacyHandoffCanGainOneExplicitDestinationWithoutLosingHistory(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $user = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $draft = Uuid::v7()->toRfc4122();
        $destination = Uuid::v7()->toRfc4122();
        $legacyEvent = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-legacy-handoff-password-123';

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Legacy S4',
            'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->insert('gf_identity_organizations', [
            'id' => $organization,
            'name' => 'Legacy org',
            'slug' => 'legacy-'.substr($organization, 0, 28),
            'type' => 'independent',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $user,
            'organization_id' => $organization,
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->insert('gf_vault_assets', [
            'id' => $asset,
            'organization_id' => $organization,
            'uploaded_by' => $user,
            'original_name' => 'legacy.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
            'sha256' => hash('sha256', $asset),
            'storage_key' => $asset,
            'usage_scope' => 'needs_review',
            'created_at' => $now,
        ]);
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $db->insert('gf_schedule_drafts', [
            'id' => $draft,
            'organization_id' => $organization,
            'asset_id' => $asset,
            'created_by' => $user,
            'scheduled_at_utc' => $future,
            'timezone' => 'UTC',
            'local_date' => substr($future, 0, 10),
            'local_time' => substr($future, 11, 5),
            'status' => 'draft',
            'created_at' => $now,
        ]);
        $db->insert('gf_manual_destinations', [
            'id' => $destination,
            'organization_id' => $organization,
            'label' => 'Destino migrado',
            'created_by' => $user,
            'created_at' => $now,
        ]);
        $db->insert('gf_manual_handoff_events', [
            'id' => $legacyEvent,
            'organization_id' => $organization,
            'draft_id' => $draft,
            'destination_id' => null,
            'actor_id' => $user,
            'action' => 'prepare',
            'created_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $user.'@example.test',
            'password' => $password,
        ]));
        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());

        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $csrf = json_decode((string) $client->getResponse()->getContent(), true)['data']['manual_handoff_csrf'];
        self::assertIsString($csrf);

        $client->request('PUT', '/api/admin/schedules/'.$draft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"action":"complete"}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'manual_destination_required',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );
        self::assertSame(
            1,
            (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_manual_handoff_events WHERE organization_id = :organization AND draft_id = :draft',
                ['organization' => $organization, 'draft' => $draft],
            ),
        );

        $client->request('PUT', '/api/admin/schedules/'.$draft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $destination,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($data['changed']);
        self::assertSame('prepared', $data['status']);
        self::assertSame($destination, $data['destination_id']);
        self::assertFalse($data['publishes']);
        self::assertFalse($data['provider_calls']);

        $events = $db->fetchAllAssociative(
            <<<'SQL'
                SELECT id, action, destination_id
                FROM gf_manual_handoff_events
                WHERE organization_id = :organization AND draft_id = :draft
                ORDER BY created_at, id
                SQL,
            ['organization' => $organization, 'draft' => $draft],
        );
        self::assertCount(2, $events);
        self::assertSame($legacyEvent, $events[0]['id']);
        self::assertNull($events[0]['destination_id']);
        self::assertSame('prepare', $events[1]['action']);
        self::assertSame($destination, $events[1]['destination_id']);

        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        $drafts = json_decode((string) $client->getResponse()->getContent(), true)['data']['drafts'];
        self::assertSame('Destino migrado', $drafts[0]['manual_destination']['label']);
    }
}
