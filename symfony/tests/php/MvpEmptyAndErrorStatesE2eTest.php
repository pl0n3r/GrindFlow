<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPagePublicationService;
use GrindFlow\Distribution\FacebookPageTransport;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Real Symfony HTTP states with disposable tenant fixtures; no live service I/O. */
final class MvpEmptyAndErrorStatesE2eTest extends WebTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR4nGP8z8Dwn4GBgYGJAQoAHxcCAk+Uzr4AAAAASUVORK5CYII=';

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
        self::assertError($client, 422, 'invalid_page', 'Selecciona una página válida (1 a 1000).');
        $client->request('GET', '/api/admin/library/collections?after=not-a-uuid');
        self::assertError($client, 422, 'invalid_cursor', 'Cursor de página inválido.');

        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $tokens['schedule_draft_csrf'],
        ], content: '{"asset_id":"not-a-uuid","scheduled_at_utc":"2026-10-26T00:00:00Z"}');
        self::assertError($client, 422, 'invalid_schedule', 'Selecciona un recurso y un slot UTC válido.');

        $client->request('PUT', '/api/admin/distribution-authorizations/not-a-uuid', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $tokens['distribution_authorization_csrf'],
        ], content: '{"authorized":true}');
        self::assertError($client, 404, 'asset_not_found', 'El recurso no está disponible en esta organización.');

        $client->request('POST', '/api/admin/manual-destinations', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $tokens['manual_destination_csrf'],
        ], content: '{"label":" "}');
        self::assertError($client, 422, 'invalid_manual_destination', 'El nombre debe tener entre 2 y 80 caracteres visibles.');
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

        $client->request('GET', '/api/admin/context');
        $context = self::data($client);
        $client->request('POST', '/api/admin/schedules/'.Uuid::v7()->toRfc4122().'/publish-facebook', server: [
            'HTTP_X_CSRF_TOKEN' => $context['schedule_draft_csrf'],
        ]);
        self::assertError($client, 409, 'facebook_page_unavailable', 'Facebook Page no está configurada para esta organización.');
        self::assertSame(0, $transport->calls);
        self::assertStringNotContainsString('fixture-secret-never-shared', (string) $client->getResponse()->getContent());
    }


    public function testSyntheticProviderFailureKeepsTheResponsePrivate(): void
    {
        [$client, $organization, $user] = $this->authenticatedClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $assetId = Uuid::v7()->toRfc4122();
        $draftId = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $blob = base64_decode(self::PNG, true);
        self::assertIsString($blob);
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $vaultDir = $projectDir.'/var/vault';
        if (!is_dir($vaultDir)) {
            self::assertTrue(mkdir($vaultDir, 0700, true) || is_dir($vaultDir));
        }
        $blobPath = $vaultDir.'/'.$assetId.'.blob';
        self::assertSame(strlen($blob), file_put_contents($blobPath, $blob));

        try {
            $db->insert('gf_vault_assets', [
                'id' => $assetId, 'organization_id' => $organization, 'uploaded_by' => $user,
                'original_name' => 'synthetic.png', 'mime_type' => 'image/png',
                'size_bytes' => strlen($blob), 'sha256' => hash('sha256', $blob),
                'storage_key' => $assetId, 'usage_scope' => 'needs_review',
                'created_at' => $now,
            ]);
            foreach ([
                'gf_content_review_events' => 'approve',
                'gf_distribution_authorization_events' => 'grant',
            ] as $table => $action) {
                $db->insert($table, [
                    'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $organization,
                    'asset_id' => $assetId, 'actor_id' => $user,
                    'action' => $action, 'created_at' => $now,
                ]);
            }
            $db->insert('gf_schedule_drafts', [
                'id' => $draftId, 'organization_id' => $organization, 'asset_id' => $assetId,
                'created_by' => $user, 'scheduled_at_utc' => $now,
                'timezone' => 'UTC', 'local_date' => substr($now, 0, 10),
                'local_time' => substr($now, 11, 5), 'status' => 'draft',
                'caption' => 'Synthetic offline distribution smoke test',
                'delivery_provider' => 'facebook_page',
                'delivery_destination_id' => '1234567890',
                'created_at' => $now,
            ]);

            $transport = new class implements FacebookPageTransport {
                public int $calls = 0;

                public function postFeed(string $graphVersion, string $pageId, string $accessToken, array $payload): array
                {
                    ++$this->calls;
                    throw new \LogicException('Synthetic provider failure, never call a remote service.');
                }

                public function postPhoto(
                    string $graphVersion, string $pageId, string $accessToken,
                    string $caption, string $filePath, string $mimeType,
                ): array {
                    ++$this->calls;
                    throw new \LogicException('Synthetic provider failure, never call a remote service.');
                }
            };
            $provider = new FacebookPageProvider(
                new FacebookPageConfiguration($organization, '1234567890', 'fixture-secret-never-shared', 'v26.0'),
                $transport,
            );
            static::getContainer()->set(FacebookPageProvider::class, $provider);
            static::getContainer()->set(
                FacebookPagePublicationService::class,
                new FacebookPagePublicationService($db, $provider),
            );
            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();
            $context = self::data($client);
            $client->request('POST', '/api/admin/schedules/'.$draftId.'/publish-facebook', server: [
                'HTTP_X_CSRF_TOKEN' => $context['schedule_draft_csrf'],
            ]);
            self::assertError($client, 409, 'ambiguous', 'El resultado externo es incierto. No reintentes a ciegas.');
            self::assertSame(1, $transport->calls);
            self::assertStringNotContainsString('fixture-secret-never-shared', (string) $client->getResponse()->getContent());
        } finally {
            if (is_file($blobPath)) {
                unlink($blobPath);
            }
        }
    }

    public function testUnauthenticatedModulesRejectBeforeReturningInternalData(): void
    {
        $client = static::createClient();
        foreach ([
            '/api/admin/vault' => 'Inicia sesión para continuar.',
            '/api/admin/schedules' => 'Inicia sesión para continuar.',
            '/api/admin/manual-destinations' => 'Inicia sesión para continuar.',
            '/api/admin/library/collections' => 'Inicia sesión.',
        ] as $path => $message) {
            $client->request('GET', $path);
            self::assertError($client, 401, 'authentication_required', $message);
        }
    }

    /** @return array{KernelBrowser,string,string} */
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
        return [$client, $organization, $user];
    }

    /** @return array<string,mixed> */
    private static function data(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['data'] ?? null);
        return $body['data'];
    }

    private static function assertError(KernelBrowser $client, int $status, string $code, string $message): void
    {
        self::assertResponseStatusCodeSame($status);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($code, $body['error']['code'] ?? null);
        self::assertSame($message, $body['error']['message'] ?? null);
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
