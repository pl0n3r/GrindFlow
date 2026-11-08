<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** E2E of the read-only admin boundary; synthetic tenants and no providers. */
final class NetworkAdaptationAdminPreviewTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAnonymousCannotReadNetworkAdaptationPreview(): void
    {
        $client = static::createClient();
        $id = Uuid::v7()->toRfc4122();
        $client->request('GET', '/api/admin/schedules/'.$id.'/network-adaptation-preview',
            server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
        $response = $client->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('authentication_required', json_decode(
            (string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR
        )['error']['code']);
    }

    public function testCurrentTenantAndRoleGatePreviewWithoutExposingUnverifiedVariant(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $myAsset = Uuid::v7()->toRfc4122();
        $otherAsset = Uuid::v7()->toRfc4122();
        $myDraft = Uuid::v7()->toRfc4122();
        $otherDraft = Uuid::v7()->toRfc4122();
        $membership = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-network-preview-password-123';
        $email = $user.'@example.test';
        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Network preview fixture', 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ([$mine => 'Red propia', $other => 'Red ajena'] as $organization => $label) {
            $db->insert('gf_identity_organizations', [
                'id' => $organization, 'name' => $label,
                'slug' => 'adapt-'.substr($organization, 0, 27),
                'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => $membership, 'user_id' => $user, 'organization_id' => $mine,
            'role' => 'editor', 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ([[$myAsset, $mine], [$otherAsset, $other]] as [$assetId, $organization]) {
            $db->insert('gf_vault_assets', [
                'id' => $assetId, 'organization_id' => $organization,
                'uploaded_by' => $user, 'original_name' => 'private-asset.png',
                'mime_type' => 'image/png', 'size_bytes' => 10,
                'sha256' => hash('sha256', $assetId), 'storage_key' => $assetId,
                'usage_scope' => 'unclassified', 'created_at' => $at,
            ]);
        }
        foreach ([[$myDraft, $mine, $myAsset], [$otherDraft, $other, $otherAsset]]
            as [$draft, $organization, $asset]) {
            $db->insert('gf_schedule_drafts', [
                'id' => $draft, 'organization_id' => $organization,
                'asset_id' => $asset, 'created_by' => $user,
                'scheduled_at_utc' => $at, 'timezone' => 'UTC',
                'local_date' => substr($at, 0, 10), 'local_time' => '12:00',
                'status' => 'draft', 'caption' => 'Texto original confidencial',
                'created_at' => $at,
            ]);
        }

        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $email, 'password' => $password,
        ]));
        $selection = $client->request('GET', '/organizations');
        $client->submit($selection->filter('.identity-orgs form')->form());

        // Authorized, eligible synthetic asset with a persisted delivery:
        // storage format is unverified, so keep the destination in review.
        $db->update('gf_vault_assets', ['usage_scope' => 'needs_review'], ['id' => $myAsset]);
        $db->insert('gf_content_review_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $mine,
            'asset_id' => $myAsset, 'actor_id' => $user,
            'action' => 'approve', 'created_at' => $at,
        ]);
        $db->insert('gf_distribution_authorization_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $mine,
            'asset_id' => $myAsset, 'actor_id' => $user,
            'action' => 'grant', 'created_at' => $at,
        ]);
        $group = Uuid::v7()->toRfc4122();
        $db->insert('gf_distribution_fanout_groups', [
            'id' => $group, 'organization_id' => $mine, 'draft_id' => $myDraft,
            'request_key' => 'preview-fixture', 'request_fingerprint' => hash('sha256', 'fixture'),
            'created_at' => $at,
        ]);
        $db->insert('gf_distribution_fanout_deliveries', [
            'id' => Uuid::v7()->toRfc4122(), 'group_id' => $group,
            'organization_id' => $mine, 'provider' => 'facebook_page',
            'destination_id' => '123456789', 'idempotency_key' => 'preview-fixture:1',
            'created_at' => $at,
        ]);

        $path = '/api/admin/schedules/'.$myDraft.'/network-adaptation-preview';
        $client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $raw = (string) $response->getContent();
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame('needs_review', $data['plan']['status']);
        self::assertSame('variant_catalog_unverifiable', $data['plan']['reason_code']);
        self::assertCount(1, $data['plan']['destinations']);
        self::assertSame('facebook_page', $data['plan']['destinations'][0]['provider']);
        self::assertSame('123456789', $data['plan']['destinations'][0]['destination_id']);
        self::assertSame('needs_review', $data['plan']['destinations'][0]['status']);
        self::assertSame('variant_catalog_unverifiable', $data['plan']['destinations'][0]['reason_code']);
        self::assertNull($data['plan']['destinations'][0]['variant_id']);
        self::assertTrue($data['plan']['requires_human_review']);
        self::assertFalse($data['plan']['provider_calls']);
        self::assertFalse($data['provider_calls']);
        self::assertFalse($data['publishes']);
        self::assertFalse($data['can_edit_caption']);
        self::assertSame('independent', $data['role']);
        self::assertStringNotContainsString('Texto original confidencial', $raw);
        self::assertStringNotContainsString('private-asset', $raw);

        // Legacy persisted intent must also survive when no fan-out rows exist.
        $legacyDraft = Uuid::v7()->toRfc4122();
        $db->insert('gf_schedule_drafts', [
            'id' => $legacyDraft, 'organization_id' => $mine,
            'asset_id' => $myAsset, 'created_by' => $user,
            'scheduled_at_utc' => $at, 'timezone' => 'UTC',
            'local_date' => substr($at, 0, 10), 'local_time' => '12:01',
            'status' => 'draft', 'caption' => 'Caption de prueba',
            'delivery_provider' => 'facebook_page', 'delivery_destination_id' => '987654321',
            'created_at' => $at,
        ]);
        $client->request('GET', '/api/admin/schedules/'.$legacyDraft.'/network-adaptation-preview');
        self::assertResponseIsSuccessful();
        $legacyPlan = json_decode((string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR)['data']['plan'];
        self::assertSame('needs_review', $legacyPlan['status']);
        self::assertSame('variant_catalog_unverifiable', $legacyPlan['reason_code']);
        self::assertSame('987654321', $legacyPlan['destinations'][0]['destination_id']);
        self::assertNull($legacyPlan['destinations'][0]['variant_id']);

        $client->request('GET', '/api/admin/schedules/'.$otherDraft.'/network-adaptation-preview');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/admin/schedules/not-a-uuid/network-adaptation-preview');
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $path);
        self::assertResponseStatusCodeSame(405);

        $db->update('gf_identity_memberships', ['role' => 'model'], ['id' => $membership]);
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
    }
}
