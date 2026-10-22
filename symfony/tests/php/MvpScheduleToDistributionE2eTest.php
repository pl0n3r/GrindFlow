<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPagePublicationService;
use GrindFlow\Distribution\FacebookPageTransport;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * An HTTP-only, synthetic S4 vertical: explicit distribution consent followed
 * by a tenant-scoped schedule draft. Neither operation calls a real provider.
 */
final class MvpScheduleToDistributionE2eTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testScheduleIntentAndDistributionAuthorizationShareTenantBoundary(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $fixture = $this->seed($db);
        $transport = $this->installNoNetworkProvider($db, $fixture['mine']);
        $this->login($client, $fixture);

        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertTrue($context['permissions']['distribution_authorize']);
        self::assertTrue($context['permissions']['content_prepare']);
        $authorizationCsrf = $context['distribution_authorization_csrf'];
        $scheduleCsrf = $context['schedule_draft_csrf'];
        self::assertIsString($authorizationCsrf);
        self::assertIsString($scheduleCsrf);

        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertFalse($preview['can_publish']);
        self::assertNotSame([], $preview['slots']);
        $slot = $preview['slots'][0]['scheduled_at_utc'];
        self::assertNotContains($fixture['foreignAsset'], array_column($preview['assets'], 'id'));

        $client->request('PUT', '/api/admin/distribution-authorizations/'.$fixture['asset'], server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $authorizationCsrf,
        ], content: '{"authorized":true}');
        self::assertResponseIsSuccessful();
        $authorization = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertTrue($authorization['authorized']);
        self::assertTrue($authorization['changed']);
        self::assertFalse($authorization['publishes']);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = :organization AND asset_id = :asset',
            ['organization' => $fixture['mine'], 'asset' => $fixture['asset']],
        ));

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $scheduleCsrf,
        ], content: json_encode([
            'asset_id' => $fixture['asset'],
            'scheduled_at_utc' => $slot,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertTrue($created['changed']);
        self::assertFalse($created['publishes']);
        self::assertSame('draft', $created['draft']['status']);
        self::assertSame($fixture['asset'], $created['draft']['asset_id']);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id = :organization AND asset_id = :asset AND status = :status',
            ['organization' => $fixture['mine'], 'asset' => $fixture['asset'], 'status' => 'draft'],
        ));

        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        $agenda = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(1, $agenda['total']);
        self::assertFalse($agenda['can_publish']);
        self::assertSame([$created['draft']['id']], array_column($agenda['drafts'], 'id'));
        self::assertSame(0, $transport->calls);
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id IN (:own, :other)',
            ['own' => $fixture['mine'], 'other' => $fixture['foreign']],
        ));
    }

    public function testCrossTenantAndRoleDenialsLeaveNoPublicationOrExternalCall(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $fixture = $this->seed($db);
        $transport = $this->installNoNetworkProvider($db, $fixture['mine']);

        $client->request('PUT', '/api/admin/distribution-authorizations/'.$fixture['asset'], server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"authorized":true}');
        self::assertResponseStatusCodeSame(401);
        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['asset_id' => $fixture['asset'], 'scheduled_at_utc' => '2030-01-01T00:00:00Z'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);

        $this->login($client, $fixture);
        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $slots = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['slots'];
        self::assertNotSame([], $slots);
        $schedule = json_encode([
            'asset_id' => $fixture['asset'],
            'scheduled_at_utc' => $slots[0]['scheduled_at_utc'],
        ], JSON_THROW_ON_ERROR);

        // A valid session without an action-specific CSRF token cannot write.
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$fixture['asset'], server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"authorized":true}');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $schedule);
        self::assertResponseStatusCodeSame(403);

        // A foreign resource is masked with 404 even with both valid CSRF tokens.
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$fixture['foreignAsset'], server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['distribution_authorization_csrf'],
        ], content: '{"authorized":true}');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['schedule_draft_csrf'],
        ], content: json_encode([
            'asset_id' => $fixture['foreignAsset'],
            'scheduled_at_utc' => $slots[0]['scheduled_at_utc'],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        // Permission revocation is read from the database, not a stale UI token.
        $db->update('gf_identity_memberships', [
            'role' => 'model',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['user_id' => $fixture['user'], 'organization_id' => $fixture['mine']]);
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$fixture['asset'], server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['distribution_authorization_csrf'],
        ], content: '{"authorized":true}');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['schedule_draft_csrf'],
        ], content: $schedule);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id IN (:own, :other)',
            ['own' => $fixture['mine'], 'other' => $fixture['foreign']],
        ));
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id IN (:own, :other)',
            ['own' => $fixture['mine'], 'other' => $fixture['foreign']],
        ));
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id IN (:own, :other)',
            ['own' => $fixture['mine'], 'other' => $fixture['foreign']],
        ));
        self::assertSame(0, $transport->calls);
    }

    /** @return array{user:string,mine:string,foreign:string,asset:string,foreignAsset:string,password:string} */
    private function seed(Connection $db): array
    {
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-mvp-e2e-password-123';
        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Synthetic S4 E2E', 'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([$mine => 'E2E own', $foreign => 'E2E foreign'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name, 'slug' => 'mvp-'.substr($id, 0, 30),
                'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user, 'organization_id' => $mine,
            'role' => 'admin', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[$asset, $mine], [$foreignAsset, $foreign]] as [$id, $organization]) {
            $db->insert('gf_vault_assets', [
                'id' => $id, 'organization_id' => $organization, 'uploaded_by' => $user,
                'original_name' => $id.'.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
                'sha256' => hash('sha256', $id), 'storage_key' => $id,
                'usage_scope' => 'needs_review', 'created_at' => $now,
            ]);
        }
        $db->insert('gf_content_review_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $mine,
            'asset_id' => $asset, 'actor_id' => $user, 'action' => 'approve', 'created_at' => $now,
        ]);
        $db->insert('gf_content_rules', [
            'organization_id' => $mine, 'timezone' => 'UTC',
            'weekdays' => 'mon,tue,wed,thu,fri,sat,sun', 'local_time' => '23:59',
            'max_per_day' => 1, 'mode' => 'review_only', 'updated_by' => $user,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return compact('user', 'mine', 'foreign', 'asset', 'foreignAsset', 'password');
    }

    /** @param array{user:string,mine:string,foreign:string,asset:string,foreignAsset:string,password:string} $fixture */
    private function login(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $fixture): void
    {
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $fixture['user'].'@example.test',
            'password' => $fixture['password'],
        ]));
        $organizations = $client->request('GET', '/organizations');
        $client->submit($organizations->filter('.identity-orgs form')->form());
    }

    private function installNoNetworkProvider(Connection $db, string $organization): MvpNoNetworkTransport
    {
        $transport = new MvpNoNetworkTransport();
        $provider = new FacebookPageProvider(
            new FacebookPageConfiguration($organization, '1234567890', 'synthetic-token-never-sent', 'v26.0'),
            $transport,
        );
        static::getContainer()->set(FacebookPageProvider::class, $provider);
        static::getContainer()->set(
            FacebookPagePublicationService::class,
            new FacebookPagePublicationService($db, $provider),
        );
        return $transport;
    }
}

final class MvpNoNetworkTransport implements FacebookPageTransport
{
    public int $calls = 0;

    public function postFeed(string $graphVersion, string $pageId, string $accessToken, array $payload): array
    {
        ++$this->calls;
        throw new \LogicException('Provider network calls are forbidden in synthetic MVP E2E.');
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
        throw new \LogicException('Provider network calls are forbidden in synthetic MVP E2E.');
    }
}
