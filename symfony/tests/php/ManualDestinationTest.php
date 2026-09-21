<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ManualDestinationTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testDestinationCatalogIsTenantSafeReversibleAndRoleGuarded(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $foreignDestination = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-manual-destination-password-123';

        $client->request('GET', '/api/admin/manual-destinations');
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Destinos S4',
            'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([$mine => 'Destino propio', $foreign => 'Destino ajeno'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id,
                'name' => $name,
                'slug' => 'destination-'.substr($id, 0, 23),
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

        $db->insert('gf_manual_destinations', [
            'id' => $foreignDestination,
            'organization_id' => $foreign,
            'label' => 'Invisible',
            'created_by' => $user,
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
        $csrf = $context['manual_destination_csrf'];
        self::assertIsString($csrf);

        $client->request('GET', '/api/admin/manual-destinations');
        self::assertResponseIsSuccessful();
        $empty = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($empty['ready']);
        self::assertSame([], $empty['destinations']);
        self::assertSame(0, $empty['total']);
        self::assertSame(100, $empty['limit']);
        self::assertFalse($empty['provider_calls']);

        $client->request(
            'POST',
            '/api/admin/manual-destinations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"label":"Mesa principal"}',
        );
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/api/admin/manual-destinations', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"label":" "}');
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', '/api/admin/manual-destinations', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"label":"  Mesa   principal  "}');
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($created['changed']);
        self::assertSame('Mesa principal', $created['destination']['label']);
        self::assertTrue($created['destination']['active']);
        self::assertFalse($created['provider_calls']);
        $destinationId = $created['destination']['id'];

        $client->request('POST', '/api/admin/manual-destinations', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"label":"mesa PRINCIPAL"}');
        self::assertResponseIsSuccessful();
        $duplicate = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertFalse($duplicate['changed']);
        self::assertSame($destinationId, $duplicate['destination']['id']);
        self::assertFalse($duplicate['provider_calls']);

        $client->request('GET', '/api/admin/manual-destinations');
        self::assertResponseIsSuccessful();
        $catalog = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame(1, $catalog['total']);
        self::assertSame([$destinationId], array_column($catalog['destinations'], 'id'));
        self::assertNotContains($foreignDestination, array_column($catalog['destinations'], 'id'));

        $client->request('PUT', '/api/admin/manual-destinations/'.$foreignDestination, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"active":false}');
        self::assertResponseStatusCodeSame(404);

        $client->request('PUT', '/api/admin/manual-destinations/'.$destinationId, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"active":false}');
        self::assertResponseIsSuccessful();
        $disabled = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($disabled['changed']);
        self::assertFalse($disabled['destination']['active']);
        self::assertFalse($disabled['provider_calls']);

        $client->request('PUT', '/api/admin/manual-destinations/'.$destinationId, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"active":false}');
        self::assertResponseIsSuccessful();
        self::assertFalse(
            json_decode((string) $client->getResponse()->getContent(), true)['data']['changed'],
        );

        $client->request('PUT', '/api/admin/manual-destinations/'.$destinationId, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"active":true}');
        self::assertResponseIsSuccessful();
        $reactivated = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertTrue($reactivated['changed']);
        self::assertTrue($reactivated['destination']['active']);

        self::assertSame(
            1,
            (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_manual_destinations WHERE id = :id AND organization_id = :organization',
                ['id' => $destinationId, 'organization' => $mine],
            ),
        );

        $renameBlocked = false;
        try {
            $db->update('gf_manual_destinations', ['label' => 'Renombrado por SQL'], ['id' => $destinationId]);
        } catch (\Doctrine\DBAL\Exception) {
            $renameBlocked = true;
        }
        self::assertTrue($renameBlocked, 'MariaDB must reject rewriting destination identity.');

        $deleteBlocked = false;
        try {
            $db->delete('gf_manual_destinations', ['id' => $destinationId]);
        } catch (\Doctrine\DBAL\Exception) {
            $deleteBlocked = true;
        }
        self::assertTrue($deleteBlocked, 'MariaDB must reject deleting manual destinations.');

        $client->request('PUT', '/api/admin/manual-destinations/'.$destinationId, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"active":false}');
        self::assertResponseIsSuccessful();

        $lifecycleRewriteBlocked = false;
        try {
            $db->update(
                'gf_manual_destinations',
                ['disabled_at' => '2000-01-01 00:00:00'],
                ['id' => $destinationId],
            );
        } catch (\Doctrine\DBAL\Exception) {
            $lifecycleRewriteBlocked = true;
        }
        self::assertTrue($lifecycleRewriteBlocked, 'MariaDB must reject rewriting a disable event.');

        $client->request('PUT', '/api/admin/manual-destinations/'.$destinationId, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"active":true}');
        self::assertResponseIsSuccessful();

        $db->update(
            'gf_identity_memberships',
            ['role' => 'editor', 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['user_id' => $user, 'organization_id' => $mine],
        );

        $client->request('POST', '/api/admin/manual-destinations', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], content: '{"label":"Otra mesa"}');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/admin/manual-destinations');
        self::assertResponseIsSuccessful();
        $readonly = json_decode((string) $client->getResponse()->getContent(), true)['data'];
        self::assertSame(1, $readonly['total']);
        self::assertSame($destinationId, $readonly['destinations'][0]['id']);
    }
}
