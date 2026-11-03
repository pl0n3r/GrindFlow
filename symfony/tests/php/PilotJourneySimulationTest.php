<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPagePublicationService;
use GrindFlow\Distribution\FacebookPageTransport;
use GrindFlow\Distribution\ExternalPublicationLedger;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionOutcome;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * GF795: synthetic creator onboarding to local ledger publication.
 * No transport request, no external identity or social account.
 * Uses only a disposable CI MariaDB and an in-memory callback.
 */
final class PilotJourneySimulationTest extends WebTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function test_criterio_principal(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $steps = array_fill_keys([
            'registro', 'ingreso', 'subida', 'clasificacion',
            'revision', 'autorizacion', 'programacion', 'publicacion_simulada',
        ], 'NO_EJECUTADO');
        $current = 'registro';
        try {
        $fixture = $this->seed($db);
        $steps['registro'] = 'OK';
        $current = 'ingreso';
        $transport = $this->installNoNetworkTransport($db, $fixture['mine']);
        $this->login($client, $fixture);
        $steps['ingreso'] = 'OK';
        $current = 'subida';

        $client->request('GET', '/api/admin/context');
        self::assertResponseIsSuccessful();
        $context = $this->data($client);
        self::assertTrue($context['permissions']['content_prepare']);
        self::assertTrue($context['permissions']['content_review_decide']);
        self::assertTrue($context['permissions']['distribution_authorize']);

        // Do not shortcut Vault by seeding the own asset: create it through HTTP.
        $tmp = tempnam(sys_get_temp_dir(), 'gf-journey-');
        self::assertIsString($tmp);
        try {
            $png = base64_decode(self::PNG, true);
            self::assertIsString($png);
            file_put_contents($tmp, $png);
            $client->request('POST', '/api/admin/vault', [], [
                'file' => new UploadedFile($tmp, 'journey-synthetic.png', 'image/png', null, true),
            ], ['HTTP_X_CSRF_TOKEN' => $context['vault_upload_csrf']]);
            self::assertResponseStatusCodeSame(201);
            $asset = $this->data($client)['asset']['id'];
            self::assertTrue(Uuid::isValid($asset));
            self::assertSame(hash('sha256', $png), (string) $db->fetchOne(
                'SELECT sha256 FROM gf_vault_assets WHERE id = :id AND organization_id = :organization',
                ['id' => $asset, 'organization' => $fixture['mine']],
            ));
        } finally {
            @unlink($tmp);
        }

        $steps['subida'] = 'OK';
        $current = 'clasificacion';
        $client->request('GET', '/api/admin/vault');
        self::assertResponseIsSuccessful();
        $vault = $this->data($client);
        self::assertContains($asset, array_column($vault['assets'], 'id'));
        self::assertNotContains($fixture['foreignAsset'], array_column($vault['assets'], 'id'));

        // Classify + explicitly review the same uploaded original: this is the
        // current HTTP content-preparation surface, not a fictional Composer API.
        $client->request('POST', '/api/admin/vault/'.$asset.'/usage', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['vault_manage_csrf'],
        ], content: '{"usage_scope":"needs_review"}');
        self::assertResponseIsSuccessful();
        self::assertSame($asset, $this->data($client)['id']);

        $steps['clasificacion'] = 'OK';
        $current = 'revision';
        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $before = $this->assetInPreview($this->data($client), $asset);
        self::assertFalse($before['eligible']);
        self::assertContains('content_review_required', $before['blocking_reasons']);
        self::assertContains('distribution_authorization_missing', $before['blocking_reasons']);

        $client->request('PUT', '/api/admin/content-reviews/'.$asset, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['content_review_csrf'],
        ], content: '{"approved":true}');
        self::assertResponseIsSuccessful();
        $review = $this->data($client);
        self::assertSame($asset, $review['asset_id']);
        self::assertTrue($review['approved']);
        self::assertFalse($review['publishes']);
        self::assertFalse($review['schedules']);

        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $pendingAuthorization = $this->assetInPreview($this->data($client), $asset);
        self::assertTrue($pendingAuthorization['content_review_approved']);
        self::assertFalse($pendingAuthorization['eligible']);
        self::assertContains('distribution_authorization_missing', $pendingAuthorization['blocking_reasons']);

        $steps['revision'] = 'OK';
        $current = 'autorizacion';
        $client->request('PUT', '/api/admin/distribution-authorizations/'.$asset, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['distribution_authorization_csrf'],
        ], content: '{"authorized":true}');
        self::assertResponseIsSuccessful();
        $authorization = $this->data($client);
        self::assertSame($asset, $authorization['asset_id']);
        self::assertTrue($authorization['authorized']);
        self::assertFalse($authorization['publishes']);

        $client->request('GET', '/api/admin/rules/weekly/preview');
        self::assertResponseIsSuccessful();
        $preview = $this->data($client);
        $ready = $this->assetInPreview($preview, $asset);
        self::assertTrue($ready['content_review_approved']);
        self::assertTrue($ready['distribution_authorized']);
        self::assertTrue($ready['eligible']);
        self::assertFalse($preview['can_publish']);
        self::assertNotSame([], $preview['slots']);

        $steps['autorizacion'] = 'OK';
        $current = 'programacion';
        $client->request('POST', '/api/admin/schedules', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $context['schedule_draft_csrf'],
        ], content: json_encode([
            'asset_id' => $asset,
            'scheduled_at_utc' => $preview['slots'][0]['scheduled_at_utc'],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $draft = $this->data($client);
        self::assertSame($asset, $draft['draft']['asset_id']);
        self::assertSame('draft', $draft['draft']['status']);
        self::assertFalse($draft['publishes']);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id = :org AND asset_id = :asset AND status = :status',
            ['org' => $fixture['mine'], 'asset' => $asset, 'status' => 'draft'],
        ));
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_content_review_events WHERE organization_id = :org AND asset_id = :asset AND action = :action',
            ['org' => $fixture['mine'], 'asset' => $asset, 'action' => 'approve'],
        ));
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = :org AND asset_id = :asset AND action = :action',
            ['org' => $fixture['mine'], 'asset' => $asset, 'action' => 'grant'],
        ));
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id IN (:own, :foreign)',
            ['own' => $fixture['mine'], 'foreign' => $fixture['foreign']],
        ));
        self::assertSame(0, $transport->calls);
        $steps['programacion'] = 'OK';
        $current = 'publicacion_simulada';

        // A fake callback exercises the actual append-only idempotency ledger.
        // It is never wired to Facebook or Instagram transport.
        $ledger = new ExternalPublicationLedger($db);
        $command = new DistributionCommand(
            $fixture['mine'], 'gf795-'.$draft['draft']['id'], 'GF795 synthetic only',
        );
        $callbackCount = 0;
        $send = static function () use (&$callbackCount): DistributionOutcome {
            ++$callbackCount;
            return new DistributionOutcome('SIMULATED-NOT-EXTERNAL-GF795');
        };
        $published = $ledger->publish($command, 'facebook_page', 'page_id', '1234567890', $send);
        self::assertSame('SIMULATED-NOT-EXTERNAL-GF795', $published->externalPublicationId);
        // Re-delivering a callback must replay the stored result, not publish twice.
        $again = $ledger->publish($command, 'facebook_page', 'page_id', '1234567890', $send);
        self::assertSame($published->externalPublicationId, $again->externalPublicationId);
        self::assertSame(1, $callbackCount);
        self::assertSame('published', $db->fetchOne(
            'SELECT status FROM gf_external_publication_attempts
             WHERE organization_id = :org AND idempotency_key = :id',
            ['org' => $fixture['mine'], 'id' => $command->idempotencyKey],
        ));
        self::assertSame(0, $transport->calls, 'Real social media transport must not be used.');
        $steps['publicacion_simulada'] = 'OK';
        } catch (\Throwable $failure) {
            $steps[$current] = 'FALLA';
            throw $failure;
        } finally {
            foreach ($steps as $name => $status) {
                fwrite(STDOUT, "GF795-SIM {$name}={$status}\n");
            }
        }
    }

    /** @return array{user:string,mine:string,foreign:string,asset:string,foreignAsset:string,password:string} */
    private function seed(Connection $db): array
    {
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $asset = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $password = 'synthetic-full-journey-password-123';
        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Synthetic journey operator',
            'email' => $user.'@example.test', 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([$mine => 'Journey own', $foreign => 'Journey foreign'] as $id => $name) {
            $db->insert('gf_identity_organizations', [
                'id' => $id, 'name' => $name, 'slug' => 'journey-'.substr($id, 0, 24),
                'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user, 'organization_id' => $mine,
            'role' => 'admin', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[$asset, $mine], [$foreignAsset, $foreign]] as [$id, $organization]) {
            $db->insert('gf_vault_assets', [
                'id' => $id, 'organization_id' => $organization, 'uploaded_by' => $user,
                'original_name' => $id.'.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
                'sha256' => hash('sha256', $id), 'storage_key' => $id,
                'usage_scope' => 'needs_review', 'created_at' => $now,
            ]);
        }
        $db->insert('gf_content_rules', [
            'organization_id' => $mine, 'timezone' => 'UTC',
            'weekdays' => 'mon,tue,wed,thu,fri,sat,sun', 'local_time' => '23:59',
            'max_per_day' => 1, 'mode' => 'review_only',
            'updated_by' => $user, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return compact('user', 'mine', 'foreign', 'asset', 'foreignAsset', 'password');
    }

    /** @param array{user:string,mine:string,foreign:string,asset:string,foreignAsset:string,password:string} $fixture */
    private function login(KernelBrowser $client, array $fixture): void
    {
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => $fixture['user'].'@example.test', 'password' => $fixture['password'],
        ]));
        $organizations = $client->request('GET', '/organizations');
        $client->submit($organizations->filter('.identity-orgs form')->form());
    }

    /** @return array<string, mixed> */
    private function data(KernelBrowser $client): array
    {
        /** @var array{data:array<string,mixed>} $response */
        $response = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        return $response['data'];
    }

    /** @param array<string,mixed> $preview
     *  @return array<string,mixed>
     */
    private function assetInPreview(array $preview, string $asset): array
    {
        $matches = array_values(array_filter(
            $preview['assets'],
            static fn (array $entry): bool => $entry['id'] === $asset,
        ));
        self::assertCount(1, $matches);
        return $matches[0];
    }

    private function installNoNetworkTransport(Connection $db, string $organization): PilotSimulationNoNetworkTransport
    {
        $transport = new PilotSimulationNoNetworkTransport();
        $provider = new FacebookPageProvider(
            new FacebookPageConfiguration($organization, '1234567890', 'synthetic-token-never-sent', 'v26.0'),
            $transport,
        );
        static::getContainer()->set(FacebookPageProvider::class, $provider);
        static::getContainer()->set(
            FacebookPagePublicationService::class,
            new FacebookPagePublicationService($db, $provider),
        );
        return $transport;
    }
}

final class PilotSimulationNoNetworkTransport implements FacebookPageTransport
{
    public int $calls = 0;

    public function postFeed(string $graphVersion, string $pageId, string $accessToken, array $payload): array
    {
        ++$this->calls;
        throw new \LogicException('Network requests are forbidden in synthetic journey tests.');
    }

    public function postPhoto(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        string $caption,
        string $filePath,
        string $mimeType,
    ): array {
        ++$this->calls;
        throw new \LogicException('Network requests are forbidden in synthetic journey tests.');
    }
}
