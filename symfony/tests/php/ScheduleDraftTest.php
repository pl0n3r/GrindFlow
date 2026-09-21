<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ScheduleDraftTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $secondAsset = Uuid::v7()->toRfc4122();
        $blockedAsset = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $foreignDraft = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-schedule-draft-password-123';

        $client->request(
            'POST',
            '/api/admin/schedules',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'asset_id' => $asset,
                'scheduled_at_utc' => '2026-09-22T14:30:00Z',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Scheduler S4',
            'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        foreach ([$mine => 'Agenda propia', $foreign => 'Agenda ajena'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id,
                'name' => $name,
                'slug' => 'schedule-'.substr($id, 0, 25),
                'type' => 'independent',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $user,
            'organization_id' => $mine,
            'role' => 'admin',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        foreach ([
            [$asset, $mine, 'needs_review', 'principal.png'],
            [$secondAsset, $mine, 'needs_review', 'segunda.png'],
            [$blockedAsset, $mine, 'unclassified', 'sin-clasificar.png'],
            [$foreignAsset, $foreign, 'needs_review', 'ajena.png'],
        ] as [$id, $organization, $scope, $name]) {
            $db->insert('gf_vault_assets', [
                'id' => $id,
                'organization_id' => $organization,
                'uploaded_by' => $user,
                'original_name' => $name,
                'mime_type' => 'image/png',
                'size_bytes' => 10,
                'sha256' => hash('sha256', $id),
                'storage_key' => $id,
                'usage_scope' => $scope,
                'created_at' => $at,
            ]);
        }

        $db->insert('gf_content_rules', [
            'organization_id' => $mine,
            'timezone' => 'UTC',
            'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
            'local_time' => '23:59',
            'max_per_day' => 1,
            'mode' => 'review_only',
            'updated_by' => $user,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        foreach ([$asset, $secondAsset, $foreignAsset] as $reviewed) {
            $organization = $reviewed === $foreignAsset ? $foreign : $mine;
            $db->insert('gf_content_review_events', [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $organization,
                'asset_id' => $reviewed,
                'actor_id' => $user,
                'action' => 'approve',
                'created_at' => $at,
            ]);
            $db->insert('gf_distribution_authorization_events', [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $organization,
                'asset_id' => $reviewed,
                'actor_id' => $user,
                'action' => 'grant',
                'created_at' => $at,
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
        $csrf = $context['schedule_draft_csrf'];
        self::assertIsString($csrf);

        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertNotSame([], $preview['slots']);
        $slot = $preview['slots'][0]['scheduled_at_utc'];

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_id' => $asset,
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'asset_id' => $foreignAsset,
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'asset_id' => $blockedAsset,
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        $blocked = json_decode((string) $client->getResponse()->getContent(), true)['error'];
        self::assertSame('asset_not_eligible', $blocked['code']);
        self::assertContains('classification_missing', $blocked['blocking_reasons']);

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'asset_id' => $asset,
            'scheduled_at_utc' => '2030-01-01T00:00:00Z',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'slot_changed',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'asset_id' => $asset,
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($created['changed']);
        self::assertFalse($created['publishes']);
        self::assertSame('draft', $created['draft']['status']);
        self::assertSame($asset, $created['draft']['asset_id']);
        self::assertSame($slot, $created['draft']['scheduled_at_utc']);
        $draftId = $created['draft']['id'];

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'asset_id' => $asset,
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $duplicate = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertFalse($duplicate['changed']);
        self::assertSame($draftId, $duplicate['draft']['id']);
        self::assertSame(1, (int) $db->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM gf_schedule_drafts
                WHERE organization_id = :organization AND asset_id = :asset
                  AND status = 'draft'
                SQL,
            ['organization' => $mine, 'asset' => $asset],
        ));

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'asset_id' => $secondAsset,
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'slot_full',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );

        $foreignDateTime = str_replace(['T', 'Z'], [' ', ''], $slot);
        $db->insert('gf_schedule_drafts', [
            'id' => $foreignDraft,
            'organization_id' => $foreign,
            'asset_id' => $foreignAsset,
            'created_by' => $user,
            'scheduled_at_utc' => $foreignDateTime,
            'timezone' => 'UTC',
            'local_date' => substr($foreignDateTime, 0, 10),
            'local_time' => '23:59',
            'status' => 'draft',
            'created_at' => $at,
        ]);

        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        $agenda = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame(1, $agenda['total']);
        self::assertFalse($agenda['can_publish']);
        self::assertSame('review_only', $agenda['mode']);
        self::assertSame([$draftId], array_column($agenda['drafts'], 'id'));

        $client->request('POST', '/api/admin/schedules/'.$foreignDraft.'/cancel', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/api/admin/schedules/'.$draftId.'/cancel');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/api/admin/schedules/'.$draftId.'/cancel', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();
        $cancelled = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($cancelled['changed']);
        self::assertFalse($cancelled['publishes']);
        self::assertSame('cancelled', $cancelled['draft']['status']);
        self::assertNotNull($cancelled['draft']['cancelled_at']);

        $client->request('POST', '/api/admin/schedules/'.$draftId.'/cancel', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse(
            json_decode((string) $client->getResponse()->getContent(), true)['data']['changed'],
        );

        self::assertSame('cancelled', $db->fetchOne(
            'SELECT status FROM gf_schedule_drafts WHERE id = :id AND organization_id = :organization',
            ['id' => $draftId, 'organization' => $mine],
        ));
        self::assertSame($user, $db->fetchOne(
            'SELECT cancelled_by FROM gf_schedule_drafts WHERE id = :id AND organization_id = :organization',
            ['id' => $draftId, 'organization' => $mine],
        ));

        $rewriteBlocked = false;
        try {
            $db->update(
                'gf_schedule_drafts',
                ['status' => 'draft', 'cancelled_at' => null, 'cancelled_by' => null],
                ['id' => $draftId],
            );
        } catch (\Doctrine\DBAL\Exception) {
            $rewriteBlocked = true;
        }
        self::assertTrue($rewriteBlocked, 'MariaDB must reject rewriting cancelled schedule history.');

        $deleteBlocked = false;
        try {
            $db->delete('gf_schedule_drafts', ['id' => $draftId]);
        } catch (\Doctrine\DBAL\Exception) {
            $deleteBlocked = true;
        }
        self::assertTrue($deleteBlocked, 'MariaDB must reject deleting schedule history.');

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'asset_id' => $secondAsset,
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $replacement = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($replacement['changed']);
        self::assertFalse($replacement['publishes']);

        $client->request('GET', '/api/admin/schedules');
        $history = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame(2, $history['total']);
        self::assertContains('cancelled', array_column($history['drafts'], 'status'));
        self::assertContains('draft', array_column($history['drafts'], 'status'));

        $db->update(
            'gf_identity_memberships',
            ['role' => 'model', 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['user_id' => $user, 'organization_id' => $mine],
        );
        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode([
            'asset_id' => $asset,
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        self::assertSame(
            2,
            json_decode((string) $client->getResponse()->getContent(), true)['data']['total'],
        );
    }
}
