<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ContentReviewDecisionTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testReviewDecisionIsTenantSafeIdempotentAndUnlocksOnlyInternalEligibility(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $notReviewable = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-content-review-password-123';

        $client->request(
            'PUT',
            '/api/admin/content-reviews/'.$asset,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"approved":true}',
        );
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Revisor S3',
            'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        foreach ([$mine => 'Revisión propia', $foreign => 'Revisión ajena'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id,
                'name' => $name,
                'slug' => 'review-'.substr($id, 0, 28),
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
            [$asset, $mine, 'needs_review'],
            [$notReviewable, $mine, 'unclassified'],
            [$foreignAsset, $foreign, 'needs_review'],
        ] as [$id, $organization, $scope]) {
            $db->insert('gf_vault_assets', [
                'id' => $id,
                'organization_id' => $organization,
                'uploaded_by' => $user,
                'original_name' => $id.'.png',
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
            'timezone' => 'America/Bogota',
            'weekdays' => strtolower(gmdate('D')),
            'local_time' => '23:59',
            'max_per_day' => 1,
            'mode' => 'review_only',
            'updated_by' => $user,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $db->insert('gf_distribution_authorization_events', [
            'id' => Uuid::v7()->toRfc4122(),
            'organization_id' => $mine,
            'asset_id' => $asset,
            'actor_id' => $user,
            'action' => 'grant',
            'created_at' => $at,
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
        $context = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($context['permissions']['content_review_decide']);
        $csrf = $context['content_review_csrf'];
        self::assertIsString($csrf);

        $client->request(
            'PUT',
            '/api/admin/content-reviews/'.$asset,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"approved":true}',
        );
        self::assertResponseStatusCodeSame(403);

        $client->request('PUT', '/api/admin/content-reviews/'.$foreignAsset, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"approved":true}');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_content_review_events'));

        $client->request('PUT', '/api/admin/content-reviews/'.$notReviewable, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"approved":true}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('content_review_not_required',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);

        $client->request('GET', '/api/admin/rules/weekly/preview');
        $before = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        $beforeAsset = array_values(array_filter(
            $before['assets'],
            static fn (array $item): bool => $item['id'] === $asset,
        ))[0];
        self::assertFalse($beforeAsset['content_review_approved']);
        self::assertContains('content_review_required', $beforeAsset['blocking_reasons']);
        self::assertNotContains('distribution_authorization_missing', $beforeAsset['blocking_reasons']);
        self::assertFalse($beforeAsset['eligible']);

        $client->request('PUT', '/api/admin/content-reviews/'.$asset, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"approved":true}');
        self::assertResponseIsSuccessful();
        $approved = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($approved['approved']);
        self::assertTrue($approved['changed']);
        self::assertFalse($approved['schedules']);
        self::assertFalse($approved['publishes']);

        $client->request('PUT', '/api/admin/content-reviews/'.$asset, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"approved":true}');
        self::assertResponseIsSuccessful();
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['data']['changed']);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_content_review_events WHERE organization_id = :organization AND asset_id = :asset',
            ['organization' => $mine, 'asset' => $asset],
        ));

        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        $ready = array_values(array_filter(
            $preview['assets'],
            static fn (array $item): bool => $item['id'] === $asset,
        ))[0];
        self::assertTrue($ready['content_review_approved']);
        self::assertTrue($ready['distribution_authorized']);
        self::assertNotContains('content_review_required', $ready['blocking_reasons']);
        self::assertTrue($ready['eligible']);
        self::assertFalse($preview['can_publish']);

        $client->request('PUT', '/api/admin/content-reviews/'.$asset, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"approved":false}');
        self::assertResponseIsSuccessful();
        self::assertSame(2, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_content_review_events WHERE organization_id = :organization AND asset_id = :asset',
            ['organization' => $mine, 'asset' => $asset],
        ));

        $client->request('GET', '/api/admin/rules/weekly/preview');
        $revoked = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        $revokedAsset = array_values(array_filter(
            $revoked['assets'],
            static fn (array $item): bool => $item['id'] === $asset,
        ))[0];
        self::assertFalse($revokedAsset['content_review_approved']);
        self::assertFalse($revokedAsset['eligible']);
        self::assertContains('content_review_required', $revokedAsset['blocking_reasons']);
    }
}
