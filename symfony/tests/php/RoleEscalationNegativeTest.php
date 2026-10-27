<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GF346 — regresiones RBAC en API Symfony, MariaDB descartable.
 *
 * Las identidades, contraseñas y organizaciones son sintéticas. Ninguna
 * solicitud usa red externa, producción, conectores ni información personal.
 */
final class RoleEscalationNegativeTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testTenantRoleCannotEscalateBySpoofingPlatformRoleOrApiPayload(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $actor = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $membership = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-rbac-ci-only';
        $asset = Uuid::v7()->toRfc4122();

        $db->insert('gf_identity_users', [
            'id' => $actor,
            'name' => 'Actor ficticio RBAC',
            'email' => $actor.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            // A platform-wide admin designation cannot replace tenant membership.
            'platform_role' => 'admin',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $db->insert('gf_identity_users', [
            'id' => $other,
            'name' => 'Otra cuenta ficticia',
            'email' => $other.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        try {
            $db->insert('gf_identity_organizations', [
                'id' => $organization,
                'name' => 'Organización original de pruebas',
                'slug' => 'rbac-'.substr(str_replace('-', '', $organization), 0, 20),
                'type' => 'independent',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $db->insert('gf_identity_memberships', [
                'id' => $membership,
                'user_id' => $actor,
                'organization_id' => $organization,
                'role' => 'editor',
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $actor.'@example.test',
                'password' => $password,
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');

            // Both restricted membership levels must reject real privileged
            // endpoints before parsing the client-controlled payload.
            foreach (['editor', 'model'] as $role) {
                $db->update('gf_identity_memberships', ['role' => $role], ['id' => $membership]);
                $client->request('GET', '/api/admin/context', server: ['HTTP_ACCEPT' => 'application/json']);
                self::assertResponseIsSuccessful();
                $context = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
                self::assertSame($role, $context['data']['organization']['role']);
                foreach (['organization_manage', 'distribution_authorize', 'manual_handoff_manage'] as $permission) {
                    self::assertFalse($context['data']['permissions'][$permission], $role.' / '.$permission);
                }
                self::assertNull($context['data']['organization_name_csrf']);
                self::assertNull($context['data']['distribution_authorization_csrf']);
                self::assertNull($context['data']['manual_handoff_csrf']);

                $this->requestJson($client, 'POST', '/api/admin/organization/name', [
                    'name' => 'Renombrada por rol inferior', 'role' => 'admin',
                    'organization_id' => $organization,
                ]);
                $this->assertDenied($client, 403, 'organization_management_forbidden');
                self::assertSame('Organización original de pruebas', $db->fetchOne(
                    'SELECT name FROM gf_identity_organizations WHERE id = ?', [$organization],
                ));

                $this->requestJson($client, 'PUT', '/api/admin/distribution-authorizations/'.$asset, [
                    'authorized' => true, 'role' => 'admin',
                ]);
                $this->assertDenied($client, 403, 'distribution_authorization_forbidden');

                $this->requestJson($client, 'POST', '/api/admin/manual-destinations', [
                    'label' => 'Conector ficticio', 'role' => 'studio',
                ]);
                $this->assertDenied($client, 403, 'manual_destination_forbidden');

                $this->requestJson($client, 'PUT', '/api/admin/schedules/'.$asset.'/manual-handoff', [
                    'action' => 'complete', 'role' => 'admin',
                ]);
                $this->assertDenied($client, 403, 'manual_handoff_forbidden');

                // Self-profile endpoint can change only the name, not role,
                // platform privileges, tenant or target account.
                $this->requestJson($client, 'POST', '/api/admin/profile/name', [
                    'name' => 'Elevado', 'role' => 'admin', 'platform_role' => 'admin',
                    'user_id' => $other, 'organization_id' => $organization,
                ], $context['data']['profile_name_csrf']);
                $this->assertDenied($client, 422, 'invalid_name');

                self::assertSame($role, $db->fetchOne(
                    'SELECT role FROM gf_identity_memberships WHERE id = ?', [$membership],
                ));
                self::assertSame('admin', $db->fetchOne(
                    'SELECT platform_role FROM gf_identity_users WHERE id = ?', [$actor],
                ));
                self::assertSame('Otra cuenta ficticia', $db->fetchOne(
                    'SELECT name FROM gf_identity_users WHERE id = ?', [$other],
                ));
                self::assertSame(0, (int) $db->fetchOne(
                    'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = ?', [$organization],
                ));
            }

            // Reusing a CSRF token minted while an actor was privileged must
            // not confer privileges once their tenant membership is downgraded.
            $db->update('gf_identity_memberships', ['role' => 'studio'], ['id' => $membership]);
            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $privilegedContext = json_decode(
                (string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR,
            );
            self::assertNotEmpty($privilegedContext['data']['organization_name_csrf']);
            $db->update('gf_identity_memberships', ['role' => 'editor'], ['id' => $membership]);
            $this->requestJson($client, 'POST', '/api/admin/organization/name', [
                'name' => 'Intento con token obsoleto',
            ], $privilegedContext['data']['organization_name_csrf']);
            $this->assertDenied($client, 403, 'organization_management_forbidden');
            self::assertSame('Organización original de pruebas', $db->fetchOne(
                'SELECT name FROM gf_identity_organizations WHERE id = ?', [$organization],
            ));
        } finally {
            $db->delete('gf_identity_memberships', ['id' => $membership]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
            $db->delete('gf_identity_users', ['id' => $actor]);
            $db->delete('gf_identity_users', ['id' => $other]);
        }
    }

    public function testUnimplementedPrivilegedApiRoutesCannotBecomeUnauditedBackdoors(): void
    {
        $client = static::createClient();
        // These routes are not part of the approved Symfony API. A future
        // implementation must introduce explicit authorization and tests
        // before any of them can start returning successful responses.
        $attempts = [
            ['DELETE', '/api/admin/organizations/00000000-0000-4000-8000-000000000001'],
            ['POST', '/api/admin/roles'],
            ['POST', '/api/admin/memberships/00000000-0000-4000-8000-000000000001/role'],
            ['GET', '/api/admin/billing'],
            ['POST', '/api/admin/connectors'],
        ];
        foreach ($attempts as [$method, $path]) {
            $client->request($method, $path, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertContains(
                $client->getResponse()->getStatusCode(),
                [401, 403, 404, 405],
                $method.' '.$path.' should remain inaccessible without explicit RBAC implementation',
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function requestJson(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $method,
        string $path,
        array $payload,
        ?string $csrf = null,
    ): void {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf !== null) {
            $headers['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $client->request($method, $path, server: $headers, content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function assertDenied(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        int $status,
        string $errorCode,
    ): void {
        self::assertResponseStatusCodeSame($status);
        $response = (string) $client->getResponse()->getContent();
        $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($errorCode, $decoded['error']['code'] ?? null);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertArrayNotHasKey('data', $decoded);
    }
}
