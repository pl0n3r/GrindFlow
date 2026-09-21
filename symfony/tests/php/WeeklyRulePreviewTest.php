<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** S3 preview remains read-only, tenant-scoped and blocked from publication. */
final class WeeklyRulePreviewTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testWeeklyPreviewIsTenantSafeAndExplainsEveryBlocker(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-preview-password-123';

        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseStatusCodeSame(401);

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Preview S3', 'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ([$mine => 'Organización propia', $foreign => 'Organización ajena'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name, 'slug' => 'ci-'.substr($id, 0, 30),
                'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user,
            'organization_id' => $mine, 'role' => 'editor',
            'created_at' => $at, 'updated_at' => $at,
        ]);

        $ownAssets = [
            'unclassified' => Uuid::v7()->toRfc4122(),
            'internal_only' => Uuid::v7()->toRfc4122(),
            'needs_review' => Uuid::v7()->toRfc4122(),
        ];
        $trash = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();

        try {
            foreach ($ownAssets as $scope => $id) {
                $db->insert('gf_vault_assets', [
                    'id' => $id, 'organization_id' => $mine, 'uploaded_by' => $user,
                    'original_name' => $scope.'.png', 'mime_type' => 'image/png',
                    'size_bytes' => 69, 'sha256' => hash('sha256', $id),
                    'storage_key' => $id, 'usage_scope' => $scope, 'created_at' => $at,
                ]);
            }
            $db->insert('gf_vault_assets', [
                'id' => $trash, 'organization_id' => $mine, 'uploaded_by' => $user,
                'original_name' => 'papelera.png', 'mime_type' => 'image/png',
                'size_bytes' => 69, 'sha256' => hash('sha256', $trash),
                'storage_key' => $trash, 'usage_scope' => 'unclassified',
                'created_at' => $at, 'deleted_at' => $at,
            ]);
            $db->insert('gf_vault_assets', [
                'id' => $foreignAsset, 'organization_id' => $foreign, 'uploaded_by' => $user,
                'original_name' => 'ajena.png', 'mime_type' => 'image/png',
                'size_bytes' => 69, 'sha256' => hash('sha256', $foreignAsset),
                'storage_key' => $foreignAsset, 'usage_scope' => 'unclassified',
                'created_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => $password,
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/api/admin/rules/weekly/preview');
            self::assertResponseIsSuccessful();
            $withoutRule = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame(3, $withoutRule['total_active_assets']);
            self::assertSame(3, $withoutRule['visible']);
            self::assertFalse($withoutRule['can_publish']);
            self::assertSame('review_only', $withoutRule['mode']);
            self::assertNull($withoutRule['rule']);
            self::assertNotContains($trash, array_column($withoutRule['assets'], 'id'));
            self::assertNotContains($foreignAsset, array_column($withoutRule['assets'], 'id'));
            foreach ($withoutRule['assets'] as $asset) {
                self::assertFalse($asset['eligible']);
                self::assertContains('weekly_rule_missing', $asset['blocking_reasons']);
                self::assertContains('distribution_authorization_missing', $asset['blocking_reasons']);
            }

            $db->insert('gf_content_rules', [
                'organization_id' => $mine, 'timezone' => 'America/Bogota',
                'weekdays' => 'mon,wed,fri', 'local_time' => '10:30', 'max_per_day' => 2,
                'mode' => 'review_only', 'updated_by' => $user,
                'created_at' => $at, 'updated_at' => $at,
            ]);

            $client->request('GET', '/api/admin/rules/weekly/preview');
            self::assertResponseIsSuccessful();
            $preview = json_decode((string) $client->getResponse()->getContent(), true)['data'];
            self::assertSame('America/Bogota', $preview['rule']['timezone']);
            self::assertSame(['mon', 'wed', 'fri'], $preview['rule']['weekdays']);
            self::assertFalse($preview['can_publish']);

            $byScope = [];
            foreach ($preview['assets'] as $asset) {
                $byScope[$asset['usage_scope']] = $asset;
                self::assertFalse($asset['eligible']);
                self::assertNotContains('weekly_rule_missing', $asset['blocking_reasons']);
                self::assertContains('distribution_authorization_missing', $asset['blocking_reasons']);
            }
            self::assertContains('classification_missing', $byScope['unclassified']['blocking_reasons']);
            self::assertContains('internal_only', $byScope['internal_only']['blocking_reasons']);
            self::assertContains('content_review_required', $byScope['needs_review']['blocking_reasons']);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $db->delete('gf_identity_memberships', ['user_id' => $user, 'organization_id' => $mine]);
            $client->request('GET', '/api/admin/rules/weekly/preview');
            self::assertResponseStatusCodeSame(403);
            self::assertSame('organization_access_changed',
                json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
            $client->request('GET', '/api/admin/rules/weekly/preview');
            self::assertResponseStatusCodeSame(409);
        } finally {
            $db->delete('gf_content_rules', ['organization_id' => $mine]);
            $db->delete('gf_vault_assets', ['organization_id' => $mine]);
            $db->delete('gf_vault_assets', ['organization_id' => $foreign]);
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $mine]);
            $db->delete('gf_identity_organizations', ['id' => $foreign]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }
}
