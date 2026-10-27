<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPageTransport;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Real Symfony HTTP states with disposable tenant fixtures; no live service I/O. */
final class MvpEmptyAndErrorStatesE2eTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testEmptyLibrarySchedulingAndDistributionStayExplicitAndPrivate(): void
    {
        [$client] = $this->authenticatedClient();

        $client->request('GET', '/api/admin/vault');
        self::assertResponseIsSuccessful();
        $vault = self::data($client);
        self::assertSame([], $vault['assets']);
        self::assertSame(0, $vault['total']);
        self::assertSame(0, $vault['quota']['used_assets']);
        self::assertPrivate($client);

        $client->request('GET', '/api/admin/library/collections');
        self::assertResponseIsSuccessful();
        self::assertSame([], self::data($client)['collections']);
        self::assertPrivate($client);

        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        $agenda = self::data($client);
        self::assertSame([], $agenda['drafts']);
        self::assertSame(0, $agenda['total']);
        self::assertFalse($agenda['can_publish']);
        self::assertPrivate($client);

        $client->request('GET', '/api/admin/manual-destinations');
        self::assertResponseIsSuccessful();
        $destinations = self::data($client);
        self::assertSame([], $destinations['destinations']);
        self::assertSame(0, $destinations['total']);
        self::assertFalse($destinations['provider_calls']);
        self::assertPrivate($client);
    }

    public function testInvalidInputsAcrossModulesFailWithBoundedPublicErrors(): void
    {
        [$client] = $this->authenticatedClient();
        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $tokens = self::data($client);

        $client->request('GET', '/api/admin/vault?page=0');
        self::assertError($client, 422, 'invalid_page');
        $client->request('GET', '/api/admin/library/collections?after=not-a-uuid');
        self::assertError($client, 422, 'invalid_cursor');

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $tokens['schedule_draft_csrf'],
        ], content: '{"asset_id":"not-a-uuid","scheduled_at_utc":"2026-10-26T00:00:00Z"}');
        self::assertError($client, 422, 'invalid_schedule');

        $client->request('PUT', '/api/admin/distribution-authorizations/not-a-uuid', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $tokens['distribution_authorization_csrf'],
        ], content: '{"authorized":true}');
        self::assertError($client, 404, 'asset_not_found');

        $client->request('POST', '/api/admin/manual-destinations', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $tokens['manual_destination_csrf'],
        ], content: '{"label":" "}');
        self::assertError($client, 422, 'invalid_manual_destination');
    }

    public function testUnavailableDistributionProviderCannotEnablePublishingOrLeakConfiguration(): void
    {
        [$client] = $this->authenticatedClient();
        $transport = new class implements FacebookPageTransport {
            public int $calls = 0;

            public function postFeed(string $graphVersion, string $pageId, string $accessToken, array $payload): array
            {
                ++$this->calls;
                throw new \LogicException('Synthetic transport must never be invoked.');
            }

            public function postPhoto(
                string $graphVersion, string $pageId, string $accessToken,
                string $caption, string $filePath, string $mimeType,
            ): array {
                ++$this->calls;
                throw new \LogicException('Synthetic transport must never be invoked.');
            }
        };
        static::getContainer()->set(FacebookPageProvider::class, new FacebookPageProvider(
            new FacebookPageConfiguration(Uuid::v7()->toRfc4122(), '123456789', 'fixture-secret-never-shared', 'v26.0'),
            $transport,
        ));

        $client->request('GET', '/api/admin/schedules');
        self::assertResponseIsSuccessful();
        $agenda = self::data($client);
        self::assertSame([], $agenda['drafts']);
        self::assertFalse($agenda['external_delivery_ready']);
        self::assertNull($agenda['external_destination_id']);
        self::assertFalse($agenda['can_publish']);
        self::assertSame(0, $transport->calls);
        self::assertSame([], array_intersect(['access_token', 'password', 'exception', 'trace'], array_keys($agenda)));
        self::assertStringNotContainsString('fixture-secret-never-shared', (string) $client->getResponse()->getContent());
        self::assertPrivate($client);
    }

    public function testUnauthenticatedModulesRejectBeforeReturningInternalData(): void
    {
        $client = static::createClient();
        foreach (['/api/admin/vault', '/api/admin/schedules', '/api/admin/manual-destinations', '/api/admin/library/collections'] as $path) {
            $client->request('GET', $path);
            self::assertError($client, 401, 'authentication_required');
        }
    }

    /** @return array{KernelBrowser,string} */
    private function authenticatedClient(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-gf667-test-only-password';
        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Synthetic GF667', 'email' => $user.'@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('gf_identity_organizations', [
            'id' => $organization, 'name' => 'Synthetic GF667 tenant',
            'slug' => 'gf667-'.substr($organization, 0, 23), 'type' => 'independent',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user,
            'organization_id' => $organization, 'role' => 'admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $user.'@example.test', 'password' => $password,
        ]));
        $selector = $client->request('GET', '/organizations');
        $client->submit($selector->filter('.identity-orgs form')->form());
        return [$client, $organization];
    }

    /** @return array<string,mixed> */
    private static function data(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['data'] ?? null);
        return $body['data'];
    }

    private static function assertError(KernelBrowser $client, int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($code, $body['error']['code'] ?? null);
        self::assertSame(['code', 'message'], array_keys($body['error']));
        self::assertStringNotContainsString('trace', strtolower((string) $client->getResponse()->getContent()));
        self::assertStringNotContainsString('exception', strtolower((string) $client->getResponse()->getContent()));
        self::assertPrivate($client);
    }

    private static function assertPrivate(KernelBrowser $client): void
    {
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }
}
