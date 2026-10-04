<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPagePublicationService;
use GrindFlow\Distribution\FacebookPageTransport;
use GrindFlow\Kernel;
use GrindFlow\Scheduling\ScheduleDraftApplicationService;
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
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(
            ScheduleDraftApplicationService::class,
            static::getContainer()->get(ScheduleDraftApplicationService::class),
        );

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

        $transport = new SchedulePhotoTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"page_photo_123"}'],
        ]);
        $provider = new FacebookPageProvider(
            new FacebookPageConfiguration(
                $mine,
                '1234567890',
                'test-facebook-page-token-do-not-log',
                'v26.0',
            ),
            $transport,
        );
        static::getContainer()->set(FacebookPageProvider::class, $provider);
        static::getContainer()->set(
            FacebookPagePublicationService::class,
            new FacebookPagePublicationService($db, $provider),
        );

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

        $lockedNullPairBlocked = false;
        try {
            $db->insert('gf_schedule_drafts', [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $mine,
                'asset_id' => $secondAsset,
                'created_by' => $user,
                'scheduled_at_utc' => $foreignDateTime,
                'timezone' => 'UTC',
                'local_date' => substr($foreignDateTime, 0, 10),
                'local_time' => '23:59',
                'status' => 'draft',
                'caption' => 'Locked draft requires a complete delivery pair',
                'delivery_provider' => null,
                'delivery_destination_id' => null,
                'delivery_locked_at' => $at,
                'created_at' => $at,
            ]);
        } catch (\Doctrine\DBAL\Exception) {
            $lockedNullPairBlocked = true;
        }
        self::assertTrue(
            $lockedNullPairBlocked,
            'MariaDB must reject a locked draft with a NULL delivery pair.',
        );

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

        $activeDraftId = $replacement['draft']['id'];
        foreach ([
            ['delivery_provider' => 'facebook_page', 'delivery_destination_id' => null],
            ['delivery_provider' => null, 'delivery_destination_id' => '1234567890'],
        ] as $partialDelivery) {
            $partialPairBlocked = false;
            try {
                $db->update('gf_schedule_drafts', $partialDelivery, ['id' => $activeDraftId]);
            } catch (\Doctrine\DBAL\Exception) {
                $partialPairBlocked = true;
            }
            self::assertTrue(
                $partialPairBlocked,
                'MariaDB must reject partial delivery provider/destination pairs.',
            );
        }

        $client->request('PUT', '/api/admin/schedules/'.$foreignDraft.'/delivery-intent', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode(['caption' => 'Caption ajeno'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $client->request('PUT', '/api/admin/schedules/'.$activeDraftId.'/delivery-intent', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['caption' => 'Caption seguro'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        $client->request('PUT', '/api/admin/schedules/'.$activeDraftId.'/delivery-intent', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode(['caption' => "  Caption seguro\r\npara Facebook  "], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $intent = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($intent['changed']);
        self::assertFalse($intent['publishes']);
        self::assertFalse($intent['provider_calls']);
        self::assertSame("Caption seguro\npara Facebook", $intent['draft']['caption']);
        self::assertSame('facebook_page', $intent['draft']['delivery_provider']);
        self::assertSame('1234567890', $intent['draft']['delivery_destination_id']);
        self::assertNull($intent['draft']['delivery_locked_at']);

        $client->request('PUT', '/api/admin/schedules/'.$activeDraftId.'/delivery-intent', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode(['caption' => "Caption seguro\npara Facebook"], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertFalse(
            json_decode((string) $client->getResponse()->getContent(), true)['data']['changed'],
        );

        $blob = 'synthetic-private-photo';
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $vaultDir = $projectDir.'/var/vault';
        if (!is_dir($vaultDir)) {
            self::assertTrue(mkdir($vaultDir, 0700, true) || is_dir($vaultDir));
        }
        $blobPath = $vaultDir.'/'.$secondAsset.'.blob';
        self::assertNotFalse(file_put_contents($blobPath, $blob));
        $db->update('gf_vault_assets', [
            'size_bytes' => strlen($blob),
            'sha256' => hash('sha256', $blob),
        ], ['id' => $secondAsset, 'organization_id' => $mine]);

        $db->executeStatement(
            'RENAME TABLE gf_external_publication_attempts TO gf_external_publication_attempts_unavailable',
        );
        try {
            $client->request('POST', '/api/admin/schedules/'.$activeDraftId.'/publish-facebook', server: [
                'HTTP_X_CSRF_TOKEN' => $csrf,
            ]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame(
                'external_delivery_unavailable',
                json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
            );
            self::assertNull($db->fetchOne(
                'SELECT delivery_locked_at FROM gf_schedule_drafts WHERE id = :id',
                ['id' => $activeDraftId],
            ));
            self::assertSame(0, $transport->calls);
        } finally {
            $db->executeStatement(
                'RENAME TABLE gf_external_publication_attempts_unavailable TO gf_external_publication_attempts',
            );
        }

        $client->request('POST', '/api/admin/schedules/'.$activeDraftId.'/publish-facebook', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();
        $published = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame('published', $published['status']);
        self::assertSame('page_photo_123', $published['external_publication_id']);
        self::assertFalse($published['automatic_retry']);
        self::assertSame(1, $transport->calls);
        self::assertNotNull($db->fetchOne(
            'SELECT delivery_locked_at FROM gf_schedule_drafts WHERE id = :id',
            ['id' => $activeDraftId],
        ));

        $client->request('POST', '/api/admin/schedules/'.$activeDraftId.'/publish-facebook', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $transport->calls, 'An identical replay must not perform second provider I/O.');

        $client->request('PUT', '/api/admin/schedules/'.$activeDraftId.'/delivery-intent', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: json_encode(['caption' => 'Intento de mutación'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'delivery_intent_locked',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );

        $client->request('POST', '/api/admin/schedules/'.$activeDraftId.'/cancel', server: [
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'delivery_intent_locked',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );

        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        $externalDrafts = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        $externalDraft = current(array_filter(
            $externalDrafts['drafts'],
            static fn (array $draft): bool => $draft['id'] === $activeDraftId,
        ));
        self::assertIsArray($externalDraft);
        self::assertSame('published', $externalDraft['external_delivery_status']);
        self::assertSame('page_photo_123', $externalDraft['external_publication_id']);
        self::assertNotNull($externalDraft['published_at']);

        @unlink($blobPath);

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


/** @internal test-only transport; no network access. */
final class SchedulePhotoTransport implements FacebookPageTransport
{
    public int $calls = 0;

    /** @param list<array{status:int,headers:array<string,string>,body:string}> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function postFeed(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        array $payload,
    ): array {
        throw new \RuntimeException('Schedule composer test must use the photo route.');
    }

    public function postPhoto(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        string $caption,
        string $filePath,
        string $mimeType,
    ): array {
        ++$this->calls;
        if ($graphVersion !== 'v26.0'
            || $pageId !== '1234567890'
            || $accessToken === ''
            || $caption !== "Caption seguro\npara Facebook"
            || !is_file($filePath)
            || $mimeType !== 'image/png') {
            throw new \RuntimeException('Unexpected photo request.');
        }

        $response = array_shift($this->responses);
        if (!is_array($response)) {
            return ['status' => 200, 'headers' => [], 'body' => '{"id":"page_photo_default"}'];
        }

        return $response;
    }
}
