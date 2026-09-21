<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ManualHandoffTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testManualHandoffRequiresExplicitTenantDestinationAndNeverPublishes(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $destination = Uuid::v7()->toRfc4122();
        $alternateDestination = Uuid::v7()->toRfc4122();
        $disabledDestination = Uuid::v7()->toRfc4122();
        $foreignDestination = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $assetPast = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $futureDraft = Uuid::v7()->toRfc4122();
        $pastDraft = Uuid::v7()->toRfc4122();
        $cancelledDraft = Uuid::v7()->toRfc4122();
        $foreignDraft = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $past = gmdate('Y-m-d H:i:s', time() - 3600);
        $password = 'synthetic-manual-handoff-password-123';

        $client->request(
            'PUT',
            '/api/admin/schedules/'.$futureDraft.'/manual-handoff',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => 'prepare', 'destination_id' => $destination], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Operador S4',
            'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([$mine => 'Handoff propio', $foreign => 'Handoff ajeno'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id,
                'name' => $name,
                'slug' => 'handoff-'.substr($id, 0, 27),
                'type' => 'independent',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $user,
            'organization_id' => $mine,
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            [$destination, $mine, 'Mesa principal', null, null],
            [$alternateDestination, $mine, 'Mesa alterna', null, null],
            [$disabledDestination, $mine, 'Mesa pausada', $now, $user],
            [$foreignDestination, $foreign, 'Mesa ajena', null, null],
        ] as [$id, $organization, $label, $disabledAt, $disabledBy]) {
            $db->insert('gf_manual_destinations', [
                'id' => $id,
                'organization_id' => $organization,
                'label' => $label,
                'created_by' => $user,
                'created_at' => $now,
                'disabled_at' => $disabledAt,
                'disabled_by' => $disabledBy,
            ]);
        }

        foreach ([
            [$asset, $mine, 'future.png'],
            [$assetPast, $mine, 'past.png'],
            [$foreignAsset, $foreign, 'foreign.png'],
        ] as [$id, $organization, $name]) {
            $db->insert('gf_vault_assets', [
                'id' => $id,
                'organization_id' => $organization,
                'uploaded_by' => $user,
                'original_name' => $name,
                'mime_type' => 'image/png',
                'size_bytes' => 10,
                'sha256' => hash('sha256', $id),
                'storage_key' => $id,
                'usage_scope' => 'needs_review',
                'created_at' => $now,
            ]);
        }

        foreach ([
            [$futureDraft, $mine, $asset, $future, 'draft', null, null],
            [$pastDraft, $mine, $assetPast, $past, 'draft', null, null],
            [$cancelledDraft, $mine, $asset, $future, 'cancelled', $now, $user],
            [$foreignDraft, $foreign, $foreignAsset, $future, 'draft', null, null],
        ] as [$id, $organization, $assetId, $scheduled, $status, $cancelledAt, $cancelledBy]) {
            $db->insert('gf_schedule_drafts', [
                'id' => $id,
                'organization_id' => $organization,
                'asset_id' => $assetId,
                'created_by' => $user,
                'scheduled_at_utc' => $scheduled,
                'timezone' => 'UTC',
                'local_date' => substr($scheduled, 0, 10),
                'local_time' => substr($scheduled, 11, 5),
                'status' => $status,
                'created_at' => $now,
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $cancelledBy,
            ]);
        }

        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $user.'@example.test',
            'password' => $password,
        ]));
        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());

        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($context['permissions']['manual_handoff_manage']);
        $csrf = $context['manual_handoff_csrf'];
        self::assertIsString($csrf);

        $client->request(
            'PUT',
            '/api/admin/schedules/'.$futureDraft.'/manual-handoff',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => 'prepare', 'destination_id' => $destination], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);

        $client->request('PUT', '/api/admin/schedules/'.$futureDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"action":"prepare"}');
        self::assertResponseStatusCodeSame(422);

        foreach ([$foreignDestination, $disabledDestination] as $unavailable) {
            $client->request('PUT', '/api/admin/schedules/'.$futureDraft.'/manual-handoff', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $csrf,
            ], content: json_encode([
                'action' => 'prepare',
                'destination_id' => $unavailable,
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409);
            self::assertSame(
                'manual_destination_unavailable',
                json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
            );
        }

        $client->request('PUT', '/api/admin/schedules/'.$foreignDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $destination,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $client->request('PUT', '/api/admin/schedules/'.$cancelledDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $destination,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'draft_cancelled',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );

        $client->request('PUT', '/api/admin/schedules/'.$futureDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $destination,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $preparedFuture = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($preparedFuture['changed']);
        self::assertSame('prepared', $preparedFuture['status']);
        self::assertSame($destination, $preparedFuture['destination_id']);
        self::assertFalse($preparedFuture['publishes']);
        self::assertFalse($preparedFuture['provider_calls']);
        self::assertFalse($preparedFuture['external_evidence']);

        $client->request('PUT', '/api/admin/schedules/'.$futureDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $destination,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertFalse(
            json_decode((string) $client->getResponse()->getContent(), true)['data']['changed'],
        );
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_manual_handoff_events WHERE organization_id = :organization AND draft_id = :draft',
            ['organization' => $mine, 'draft' => $futureDraft],
        ));

        $client->request('PUT', '/api/admin/schedules/'.$futureDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $alternateDestination,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'manual_destination_change_requires_retry',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );

        $client->request('PUT', '/api/admin/schedules/'.$futureDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"action":"complete"}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'manual_handoff_not_due',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );

        $client->request('POST', '/api/admin/schedules/'.$futureDraft.'/cancel', server: [
            'HTTP_X_CSRF_TOKEN' => $context['schedule_draft_csrf'],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'manual_handoff_active',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );

        $client->request('GET', '/api/admin/manual-handoff-queue');
        self::assertResponseIsSuccessful();
        $futureQueue = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($futureQueue['ready']);
        self::assertSame(1, $futureQueue['total']);
        self::assertSame($futureDraft, $futureQueue['items'][0]['draft_id']);
        self::assertSame('prepared', $futureQueue['items'][0]['status']);
        self::assertFalse($futureQueue['items'][0]['due']);
        self::assertSame('Mesa principal', $futureQueue['items'][0]['destination']['label']);
        self::assertFalse($futureQueue['provider_calls']);

        $client->request('PUT', '/api/admin/schedules/'.$pastDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $destination,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('PUT', '/api/admin/schedules/'.$pastDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"action":"fail"}');
        self::assertResponseIsSuccessful();
        $failed = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame('failed', $failed['status']);
        self::assertSame($destination, $failed['destination_id']);

        $client->request('PUT', '/api/admin/schedules/'.$pastDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $alternateDestination,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $retried = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame('prepared', $retried['status']);
        self::assertSame($alternateDestination, $retried['destination_id']);

        $client->request('PUT', '/api/admin/schedules/'.$pastDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"action":"complete"}');
        self::assertResponseIsSuccessful();
        $completed = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame('completed', $completed['status']);
        self::assertSame($alternateDestination, $completed['destination_id']);
        self::assertFalse($completed['publishes']);
        self::assertFalse($completed['external_evidence']);

        $client->request('PUT', '/api/admin/schedules/'.$pastDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"action":"complete"}');
        self::assertResponseIsSuccessful();
        self::assertFalse(
            json_decode((string) $client->getResponse()->getContent(), true)['data']['changed'],
        );

        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        $agenda = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($agenda['manual_handoff_ready']);
        self::assertTrue($agenda['manual_destination_ready']);
        self::assertSame(3, $agenda['total']);
        $byId = [];
        foreach ($agenda['drafts'] as $draft) {
            $byId[$draft['id']] = $draft;
        }
        self::assertSame('prepared', $byId[$futureDraft]['manual_handoff_status']);
        self::assertSame('Mesa principal', $byId[$futureDraft]['manual_destination']['label']);
        self::assertSame('completed', $byId[$pastDraft]['manual_handoff_status']);
        self::assertSame('Mesa alterna', $byId[$pastDraft]['manual_destination']['label']);
        self::assertSame('none', $byId[$cancelledDraft]['manual_handoff_status']);
        self::assertNull($byId[$cancelledDraft]['manual_destination']);
        self::assertArrayNotHasKey($foreignDraft, $byId);
        self::assertFalse($agenda['can_publish']);

        $event = $db->fetchAssociative(
            <<<'SQL'
                SELECT id, destination_id FROM gf_manual_handoff_events
                WHERE organization_id = :organization AND draft_id = :draft
                ORDER BY created_at DESC, id DESC LIMIT 1
                SQL,
            ['organization' => $mine, 'draft' => $pastDraft],
        );
        self::assertIsArray($event);
        self::assertSame($alternateDestination, $event['destination_id']);

        $updateBlocked = false;
        try {
            $db->update('gf_manual_handoff_events', ['action' => 'fail'], ['id' => $event['id']]);
        } catch (Exception) {
            $updateBlocked = true;
        }
        self::assertTrue($updateBlocked, 'MariaDB must reject rewriting manual handoff history.');

        $deleteBlocked = false;
        try {
            $db->delete('gf_manual_handoff_events', ['id' => $event['id']]);
        } catch (Exception) {
            $deleteBlocked = true;
        }
        self::assertTrue($deleteBlocked, 'MariaDB must reject deleting manual handoff history.');

        $db->update(
            'gf_identity_memberships',
            ['role' => 'editor', 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['user_id' => $user, 'organization_id' => $mine],
        );
        $client->request('PUT', '/api/admin/schedules/'.$futureDraft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"action":"fail"}');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/admin/manual-handoff-queue');
        self::assertResponseIsSuccessful();
        self::assertFalse(
            json_decode((string) $client->getResponse()->getContent(), true)['data']['can_manage'],
        );
    }
}
