<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Contrato HTTP de seguridad CSRF con usuarios y organizaciones sintéticos. */
final class WeeklyRuleCsrfTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMissingOrInvalidCsrfIsRejectedWith403AndKeepsRule(): void
    {
        $this->forAuthenticatedRole('editor', static function ($client, Connection $db, string $org, string $user): void {
            $now = gmdate('Y-m-d H:i:s');
            $db->insert('gf_content_rules', [
                'organization_id' => $org, 'timezone' => 'UTC',
                'weekdays' => 'mon,wed', 'local_time' => '10:30', 'max_per_day' => 2,
                'mode' => 'review_only', 'updated_by' => $user,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $initial = $db->fetchAssociative(
                'SELECT timezone, weekdays, local_time, max_per_day, mode, updated_by FROM gf_content_rules WHERE organization_id = ?',
                [$org],
            );
            self::assertIsArray($initial);
            foreach ([null, 'synthetic-invalid-csrf'] as $token) {
                $client->request('PUT', '/api/admin/rules/weekly', [], [], self::headers($token), self::validRule());
                self::assertResponseStatusCodeSame(403);
                $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('invalid_csrf', $payload['error']['code']);
                self::assertSame($initial, $db->fetchAssociative(
                    'SELECT timezone, weekdays, local_time, max_per_day, mode, updated_by FROM gf_content_rules WHERE organization_id = ?',
                    [$org],
                ));
            }
        });
    }

    public function testRoleWithoutContentPrepareIsForbiddenBeforeCsrf(): void
    {
        $this->forAuthenticatedRole('model', static function ($client, Connection $db, string $org): void {
            $client->request('PUT', '/api/admin/rules/weekly', [], [], self::headers(null), self::validRule());
            self::assertResponseStatusCodeSame(403);
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('rule_management_forbidden', $payload['error']['code']);
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_content_rules WHERE organization_id = ?', [$org],
            ));
        });
    }

    public function testValidCsrfPassesTheCsrfGate(): void
    {
        $this->forAuthenticatedRole('editor', static function ($client, Connection $db, string $org): void {
            $tokens = static::getContainer()->get(CsrfTokenManagerInterface::class);
            $csrf = (string) $tokens->getToken('grindflow_weekly_rule')->getValue();
            self::assertNotSame('', $csrf);
            $client->request('PUT', '/api/admin/rules/weekly', [], [], self::headers($csrf), self::validRule());
            self::assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('review_only', $payload['data']['rule']['mode']);
            self::assertSame(['mon', 'wed'], $payload['data']['rule']['weekdays']);
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_content_rules WHERE organization_id = ?', [$org],
            ));
        });
    }

    /** @return array<string,string> */
    private static function headers(?string $csrf): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf !== null) {
            $headers['HTTP_X_CSRF_TOKEN'] = $csrf;
        }

        return $headers;
    }

    private static function validRule(): string
    {
        return json_encode([
            'timezone' => 'America/Bogota',
            'weekdays' => ['mon', 'wed'],
            'local_time' => '09:45',
            'max_per_day' => 3,
        ], JSON_THROW_ON_ERROR);
    }

    /** @param callable $assertions */
    private function forAuthenticatedRole(string $role, callable $assertions): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $org = Uuid::v7()->toRfc4122();
        $password = 'synthetic-csrf-weekly-password-123';
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Synthetic weekly CSRF', 'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('gf_identity_organizations', [
            'id' => $org, 'name' => 'Synthetic weekly organization',
            'slug' => 'weekly-'.substr($org, 0, 24), 'type' => 'independent',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user,
            'organization_id' => $org, 'role' => $role,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => $password,
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');
            $assertions($client, $db, $org, $user);
        } finally {
            $db->delete('gf_content_rules', ['organization_id' => $org]);
            $db->delete('gf_identity_security_audit', ['user_id' => $user]);
            $db->delete('gf_identity_memberships', ['organization_id' => $org]);
            $db->delete('gf_identity_organizations', ['id' => $org]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }
}
