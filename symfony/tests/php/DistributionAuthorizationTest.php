<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class DistributionAuthorizationTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAuthorizationIsExplicitTenantSafeRevocableAndNeverPublishes(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-distribution-password-123';

        $client->request('PUT', '/api/admin/distribution-authorizations/'.$asset, server: ['CONTENT_TYPE' => 'application/json'], content: '{"authorized":true}');
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Autorizador S3', 'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1, 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ([$mine => 'Propia', $foreign => 'Ajena'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name, 'slug' => 'auth-'.substr($id, 0, 30),
                'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user, 'organization_id' => $mine,
            'role' => 'admin', 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ([[$asset, $mine], [$foreignAsset, $foreign]] as [$id, $organization]) {
            $db->insert('gf_vault_assets', [
                'id' => $id, 'organization_id' => $organization, 'uploaded_by' => $user,
                'original_name' => $id.'.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
                'sha256' => hash('sha256', $id), 'storage_key' => $id, 'usage_scope' => 'needs_review',
                'created_at' => $at,
            ]);
        }

        $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => $password,
            ]));
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());

            $client->request('GET', '/api/admin/context');
            $context = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            $csrf = $context['distribution_authorization_csrf'];
            self::assertTrue($context['permissions']['distribution_authorize']);

            $client->request('PUT', '/api/admin/distribution-authorizations/'.$foreignAsset, server: [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
            ], content: '{"authorized":true}');
            self::assertResponseStatusCodeSame(404);
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = :organization',
                ['organization' => $mine],
            ));

            $client->request('PUT', '/api/admin/distribution-authorizations/'.$asset, server: [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
            ], content: '{"authorized":true}');
            self::assertResponseIsSuccessful();
            $grant = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertTrue($grant['authorized']);
            self::assertTrue($grant['changed']);
            self::assertFalse($grant['publishes']);

            $client->request('GET', '/api/admin/rules/weekly/preview');
            $preview = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertFalse($preview['can_publish']);
            $row = array_values(array_filter($preview['assets'], static fn (array $item): bool => $item['id'] === $asset))[0];
            self::assertTrue($row['distribution_authorized']);
            self::assertNotContains('distribution_authorization_missing', $row['blocking_reasons']);

            $client->request('PUT', '/api/admin/distribution-authorizations/'.$asset, server: [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
            ], content: '{"authorized":false}');
            self::assertResponseIsSuccessful();
            self::assertSame(2, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = :organization AND asset_id = :asset',
                ['organization' => $mine, 'asset' => $asset],
            ));
    }
}
