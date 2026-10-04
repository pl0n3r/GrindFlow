<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ManualHandoffExternalDeliveryLockTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testExternalDeliveryLockRejectsNewManualHandoff(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $user = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $destination = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $draft = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $scheduled = gmdate('Y-m-d H:i:s', time() + 3600);
        $password = 'synthetic-external-lock-password-123';

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Operador lock externo',
            'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->insert('gf_identity_organizations', [
            'id' => $organization,
            'name' => 'Lock externo',
            'slug' => 'lock-'.substr($organization, 0, 31),
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
        $db->insert('gf_manual_destinations', [
            'id' => $destination,
            'organization_id' => $organization,
            'label' => 'Mesa manual',
            'created_by' => $user,
            'created_at' => $now,
        ]);
        $db->insert('gf_vault_assets', [
            'id' => $asset,
            'organization_id' => $organization,
            'uploaded_by' => $user,
            'original_name' => 'locked.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
            'sha256' => hash('sha256', $asset),
            'storage_key' => $asset,
            'usage_scope' => 'needs_review',
            'created_at' => $now,
        ]);
        $db->insert('gf_schedule_drafts', [
            'id' => $draft,
            'organization_id' => $organization,
            'asset_id' => $asset,
            'caption' => 'Entrega externa ya bloqueada',
            'delivery_provider' => 'facebook_page',
            'delivery_destination_id' => '123456789',
            'delivery_locked_at' => $now,
            'created_by' => $user,
            'scheduled_at_utc' => $scheduled,
            'timezone' => 'UTC',
            'local_date' => substr($scheduled, 0, 10),
            'local_time' => substr($scheduled, 11, 5),
            'status' => 'draft',
            'created_at' => $now,
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
        self::assertTrue($context['permissions']['manual_handoff_manage']);

        $client->request('PUT', '/api/admin/schedules/'.$draft.'/manual-handoff', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['manual_handoff_csrf'],
        ], content: json_encode([
            'action' => 'prepare',
            'destination_id' => $destination,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'external_delivery_locked',
            json_decode((string) $client->getResponse()->getContent(), true)['error']['code'],
        );
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_manual_handoff_events WHERE organization_id = :organization AND draft_id = :draft',
            ['organization' => $organization, 'draft' => $draft],
        ));
    }
}
