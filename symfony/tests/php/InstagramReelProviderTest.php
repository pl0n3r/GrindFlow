<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionOutcome;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\InstagramReelConfiguration;
use GrindFlow\Distribution\InstagramReelProvider;
use GrindFlow\Distribution\InstagramReelPublicationService;
use GrindFlow\Distribution\InstagramReelTransport;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class InstagramReelProviderTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    private const string ORGANIZATION = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testHappyPathResumableUploadUsesFourFakeOnlyOperations(): void
    {
        $video = $this->videoFixture();
        $transport = new FakeInstagramReelTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://rupload.facebook.com/ig-api-upload/v26.0/123456"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"success":true}'],
            ['status' => 200, 'headers' => [], 'body' => '{"status_code":"FINISHED"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"id":"789012"}'],
        ]);
        try {
            $outcome = $this->provider($transport)->publish($this->command($video));
            self::assertInstanceOf(DistributionOutcome::class, $outcome);
            self::assertSame('789012', $outcome->externalPublicationId);
            self::assertSame(['create', 'upload', 'status', 'publish'], $transport->calls);
        } finally {
            @unlink($video);
        }
    }

    public function testNoConfigurationNoTenantAndMalformedMediaAreRejectedBeforeNetwork(): void
    {
        $transport = new FakeInstagramReelTransport([]);
        $disabled = $this->provider($transport, false);
        $video = $this->videoFixture();
        try {
            $this->assertKind(
                fn () => $disabled->publish($this->command($video)),
                DistributionProviderException::KIND_CONFIGURATION,
            );
            $this->assertKind(
                fn () => $this->provider($transport)->publish(new DistributionCommand(
                    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    'cross-tenant', 'Reel', null, $video, 'video/mp4', hash_file('sha256', $video),
                )),
                DistributionProviderException::KIND_CONFIGURATION,
            );
            $this->assertKind(
                fn () => $this->provider($transport)->publish(new DistributionCommand(
                    self::ORGANIZATION, 'bad-sha', 'Reel', null, $video, 'video/mp4', str_repeat('0', 64),
                )),
                DistributionProviderException::KIND_REJECTED,
            );
            self::assertSame([], $transport->calls);
        } finally {
            @unlink($video);
        }
    }

    public function testAuthAndRateLimitAreSafeAndBounded(): void
    {
        $video = $this->videoFixture();
        try {
            foreach ([401, 403] as $status) {
                $transport = new FakeInstagramReelTransport([
                    ['status' => $status, 'headers' => [], 'body' => '{"error":"sensitive"}'],
                ]);
                $this->assertKind(
                    fn () => $this->provider($transport)->publish($this->command($video)),
                    DistributionProviderException::KIND_AUTHENTICATION,
                );
                self::assertSame(['create'], $transport->calls);
            }
            $transport = new FakeInstagramReelTransport([
                ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '{}'],
            ]);
            $error = $this->capture(fn () => $this->provider($transport)->publish($this->command($video)));
            self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $error->kind);
            self::assertTrue($error->automaticRetryAllowed);
            self::assertSame(120, $error->retryAfterSeconds);
        } finally {
            @unlink($video);
        }
    }

    public function testUploadNotAcknowledgedDoesNotAdvanceToMediaPublish(): void
    {
        $video = $this->videoFixture();
        try {
            $transport = new FakeInstagramReelTransport([
                ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://rupload.facebook.com/ig-api-upload/v26.0/123456"}'],
                ['status' => 200, 'headers' => [], 'body' => '{"success":false}'],
            ]);
            $this->assertKind(
                fn () => $this->provider($transport)->publish($this->command($video)),
                DistributionProviderException::KIND_AMBIGUOUS,
            );
            self::assertSame(['create', 'upload'], $transport->calls);
        } finally {
            @unlink($video);
        }
    }

    public function testCaptionOverInstagramMaximumFailsBeforeNetwork(): void
    {
        $video = $this->videoFixture();
        $transport = new FakeInstagramReelTransport([]);
        try {
            $this->assertKind(
                fn () => $this->provider($transport)->publish(new DistributionCommand(
                    self::ORGANIZATION, 'caption-too-long', str_repeat('x', 2201),
                    null, $video, 'video/mp4', hash_file('sha256', $video),
                )),
                DistributionProviderException::KIND_REJECTED,
            );
            self::assertSame([], $transport->calls);
        } finally {
            @unlink($video);
        }
    }

    public function testPrivateVideoOutsideConfiguredVaultIsRejectedBeforeNetwork(): void
    {
        $video = $this->videoFixture();
        $vault = sys_get_temp_dir().'/gf-ig-vault-empty-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($vault, 0700));
        $transport = new FakeInstagramReelTransport([]);
        try {
            $this->assertKind(
                fn () => $this->provider($transport, true, $vault)->publish($this->command($video)),
                DistributionProviderException::KIND_REJECTED,
            );
            self::assertSame([], $transport->calls);
        } finally {
            @unlink($video);
            @rmdir($vault);
        }
    }

    public function testHostForgeryAndUnfinishedContainerAreAmbiguousNoSecondSend(): void
    {
        $video = $this->videoFixture();
        try {
            $transport = new FakeInstagramReelTransport([
                ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://evil.example/upload"}'],
            ]);
            $this->assertKind(
                fn () => $this->provider($transport)->publish($this->command($video)),
                DistributionProviderException::KIND_AMBIGUOUS,
            );
            self::assertSame(['create'], $transport->calls);
            $transport2 = new FakeInstagramReelTransport([
                ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://rupload.facebook.com/ig-api-upload/v26.0/123456"}'],
                ['status' => 200, 'headers' => [], 'body' => '{"success":true}'],
                ['status' => 200, 'headers' => [], 'body' => '{"status_code":"IN_PROGRESS"}'],
                ['status' => 200, 'headers' => [], 'body' => '{"status_code":"IN_PROGRESS"}'],
                ['status' => 200, 'headers' => [], 'body' => '{"status_code":"IN_PROGRESS"}'],
            ]);
            $this->assertKind(
                fn () => $this->provider($transport2)->publish($this->command($video)),
                DistributionProviderException::KIND_AMBIGUOUS,
            );
            self::assertNotContains('publish', $transport2->calls);
        } finally {
            @unlink($video);
        }
    }

    public function test429OnlyBeforeContainerCreationAllowsAutomaticRetry(): void
    {
        $video = $this->videoFixture();
        $created = ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://rupload.facebook.com/ig-api-upload/v26.0/123456"}'];
        $uploaded = ['status' => 200, 'headers' => [], 'body' => '{"success":true}'];
        $finished = ['status' => 200, 'headers' => [], 'body' => '{"status_code":"FINISHED"}'];
        $limited = ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '{}'];
        try {
            foreach ([[$created, $limited], [$created, $uploaded, $limited],
                      [$created, $uploaded, $finished, $limited]] as $responses) {
                $transport = new FakeInstagramReelTransport($responses);
                $error = $this->capture(fn () => $this->provider($transport)->publish($this->command($video)));
                self::assertSame(DistributionProviderException::KIND_AMBIGUOUS, $error->kind);
                self::assertFalse($error->automaticRetryAllowed);
                self::assertCount(count($responses), $transport->calls);
            }
        } finally {
            @unlink($video);
        }
    }

    public function testStatusPollingWaitsBetweenChecksWithoutRealSleep(): void
    {
        $video = $this->videoFixture();
        $sleepCalls = 0;
        $transport = new FakeInstagramReelTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://rupload.facebook.com/ig-api-upload/v26.0/123456"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"success":true}'],
            ['status' => 200, 'headers' => [], 'body' => '{"status_code":"IN_PROGRESS"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"status_code":"PROCESSING"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"status_code":"FINISHED"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"id":"789012"}'],
        ]);
        try {
            $outcome = $this->provider($transport, true, null, static function () use (&$sleepCalls): void {
                ++$sleepCalls;
            })->publish($this->command($video));
            self::assertSame('789012', $outcome->externalPublicationId);
            self::assertSame(2, $sleepCalls);
            self::assertSame(['create', 'upload', 'status', 'status', 'status', 'publish'], $transport->calls);
        } finally {
            @unlink($video);
        }
    }

    public function testVerifiedUploadKeepsOriginalBytesWhenVaultFileChanges(): void
    {
        $video = $this->videoFixture();
        $sha = hash_file('sha256', $video);
        self::assertIsString($sha);
        $transport = new FakeInstagramReelTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://rupload.facebook.com/ig-api-upload/v26.0/123456"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"success":true}'],
            ['status' => 200, 'headers' => [], 'body' => '{"status_code":"FINISHED"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"id":"789012"}'],
        ]);
        $transport->onCreate = static function () use ($video): void {
            file_put_contents($video, 'changed after verification');
        };
        try {
            $outcome = $this->provider($transport)->publish($this->command($video));
            self::assertSame('789012', $outcome->externalPublicationId);
            self::assertSame($sha, $transport->uploadedSha);
            self::assertNotSame($sha, hash_file('sha256', $video));
        } finally {
            @unlink($video);
        }
    }

    public function testInstagramLedgerPublishedReplayAndFingerprintConflictAreIsolated(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        // El CI revierte el CHECK a facebook_page al terminar PHPUnit.
        // Una tabla temporal por conexión evita dejar filas instagram_reels
        // en el ledger persistente sin alterar triggers ni la migración real.
        $db->executeStatement('CREATE TEMPORARY TABLE gf_external_publication_attempts LIKE gf_external_publication_attempts');
        $video = $this->videoFixture();
        $transport = new FakeInstagramReelTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://rupload.facebook.com/ig-api-upload/v26.0/123456"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"success":true}'],
            ['status' => 200, 'headers' => [], 'body' => '{"status_code":"FINISHED"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"id":"789012"}'],
        ]);
        try {
            $service = new InstagramReelPublicationService(
                $db, $this->provider($transport, true, null, null, $organization),
            );
            $key = 'ig-success-'.Uuid::v7()->toRfc4122();
            $command = $this->commandForOrganization($video, $organization, $key);
            $first = $service->publish($command);
            $second = $service->publish($command);
            self::assertSame('789012', $first->externalPublicationId);
            self::assertSame('789012', $second->externalPublicationId);
            self::assertSame(['create', 'upload', 'status', 'publish'], $transport->calls);
            $different = new DistributionCommand(
                $organization, $key, 'Contenido modificado', null,
                $video, 'video/mp4', hash_file('sha256', $video),
            );
            $this->assertKind(fn () => $service->publish($different), DistributionProviderException::KIND_REJECTED);
            self::assertCount(4, $transport->calls);
            self::assertSame('published', (string) $db->fetchOne(
                'SELECT status FROM gf_external_publication_attempts WHERE organization_id = :organization AND provider = :provider AND idempotency_key = :key',
                ['organization' => $organization, 'provider' => 'instagram_reels', 'key' => $key],
            ));
            $foreign = $this->commandForOrganization($video, Uuid::v7()->toRfc4122(), $key);
            $this->assertKind(fn () => $service->publish($foreign), DistributionProviderException::KIND_CONFIGURATION);
            self::assertCount(4, $transport->calls);
        } finally {
            @unlink($video);
            $db->executeStatement('DROP TEMPORARY TABLE IF EXISTS gf_external_publication_attempts');
        }
    }

    public function testInstagramLedgerRateLimitAndAmbiguousReplayNeverDuplicateSends(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $db->executeStatement('CREATE TEMPORARY TABLE gf_external_publication_attempts LIKE gf_external_publication_attempts');
        $now = new DateTimeImmutable('2026-10-19 18:00:00', new DateTimeZone('UTC'));
        $clock = static function () use (&$now): DateTimeImmutable {
            return $now;
        };
        $video = $this->videoFixture();
        $created = ['status' => 200, 'headers' => [], 'body' => '{"id":"123456","uri":"https://rupload.facebook.com/ig-api-upload/v26.0/123456"}'];
        $uploaded = ['status' => 200, 'headers' => [], 'body' => '{"success":true}'];
        $finished = ['status' => 200, 'headers' => [], 'body' => '{"status_code":"FINISHED"}'];
        $limited = ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '{}'];
        try {
            $transport = new FakeInstagramReelTransport([
                $limited,
                $created, $uploaded, $finished,
                ['status' => 200, 'headers' => [], 'body' => '{"id":"789012"}'],
            ]);
            $service = new InstagramReelPublicationService(
                $db, $this->provider($transport, true, null, null, $organization), $clock,
            );
            $command = $this->commandForOrganization($video, $organization, 'ig-rate-'.Uuid::v7()->toRfc4122());
            $early = $this->capture(fn () => $service->publish($command));
            self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $early->kind);
            self::assertSame(['create'], $transport->calls);
            $this->assertKind(fn () => $service->publish($command), DistributionProviderException::KIND_RATE_LIMIT);
            self::assertSame(['create'], $transport->calls);
            $now = $now->modify('+121 seconds');
            $outcome = $service->publish($command);
            self::assertSame('789012', $outcome->externalPublicationId);
            self::assertSame(['create', 'create', 'upload', 'status', 'publish'], $transport->calls);

            $uncertain = new FakeInstagramReelTransport([$created, $limited]);
            $uncertainService = new InstagramReelPublicationService(
                $db, $this->provider($uncertain, true, null, null, $organization), $clock,
            );
            $uncertainCommand = $this->commandForOrganization(
                $video, $organization, 'ig-ambiguous-'.Uuid::v7()->toRfc4122(),
            );
            $this->assertKind(
                fn () => $uncertainService->publish($uncertainCommand),
                DistributionProviderException::KIND_AMBIGUOUS,
            );
            $this->assertKind(
                fn () => $uncertainService->publish($uncertainCommand),
                DistributionProviderException::KIND_AMBIGUOUS,
            );
            self::assertSame(['create', 'upload'], $uncertain->calls);
            self::assertSame('ambiguous', (string) $db->fetchOne(
                'SELECT status FROM gf_external_publication_attempts WHERE organization_id = :organization AND provider = :provider AND idempotency_key = :key',
                ['organization' => $organization, 'provider' => 'instagram_reels', 'key' => $uncertainCommand->idempotencyKey],
            ));
        } finally {
            @unlink($video);
            $db->executeStatement('DROP TEMPORARY TABLE IF EXISTS gf_external_publication_attempts');
        }
    }

    private function organization(Connection $db): string
    {
        $id = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_organizations', [
            'id' => $id,
            'name' => 'Instagram fake '.substr($id, 0, 8),
            'slug' => 'instagram-fake-'.substr(str_replace('-', '', $id), 0, 16),
            'type' => 'independent',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function commandForOrganization(string $video, string $organization, string $key): DistributionCommand
    {
        $sha = hash_file('sha256', $video);
        self::assertIsString($sha);
        return new DistributionCommand($organization, $key, 'Reel de prueba', null, $video, 'video/mp4', $sha);
    }

    private function videoFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gf-ig-fake-');
        self::assertIsString($path);
        self::assertGreaterThan(0, file_put_contents(
            $path, "\x00\x00\x00\x18ftypisom\x00\x00\x00\x01isomiso2",
        ));
        return $path;
    }

    private function command(string $video): DistributionCommand
    {
        $sha = hash_file('sha256', $video);
        self::assertIsString($sha);
        return new DistributionCommand(
            self::ORGANIZATION, 'instagram-test-key', 'Reel de prueba',
            null, $video, 'video/mp4', $sha,
        );
    }

    private function provider(FakeInstagramReelTransport $transport, bool $enabled = true, ?string $vaultRoot = null, ?\Closure $sleeper = null, string $organizationId = self::ORGANIZATION): InstagramReelProvider
    {
        return new InstagramReelProvider(
            new InstagramReelConfiguration(
                $organizationId, '1234567890', 'fixture-ig-token',
                'v26.0', 'instagram_content_publish', $enabled, $vaultRoot ?? sys_get_temp_dir(),
            ),
            $transport,
            $sleeper,
        );
    }

    private function assertKind(callable $run, string $kind): void
    {
        self::assertSame($kind, $this->capture($run)->kind);
    }

    private function capture(callable $run): DistributionProviderException
    {
        try {
            $run();
        } catch (DistributionProviderException $e) {
            self::assertStringNotContainsString('fixture-ig-token', $e->getMessage());
            return $e;
        }
        self::fail('Expected a safe DistributionProviderException');
    }
}

final class FakeInstagramReelTransport implements InstagramReelTransport
{
    /** @var list<string> */
    public array $calls = [];
    public ?\Closure $onCreate = null;
    public ?string $uploadedSha = null;

    /** @param list<array{status:int,headers:array<string,string>,body:string}> $responses */
    public function __construct(private array $responses) {}

    public function createContainer(string $version, string $userId, string $token, string $caption): array
    {
        $this->calls[] = 'create';
        $response = $this->next();
        if ($this->onCreate !== null) {
            ($this->onCreate)();
        }
        return $response;
    }

    public function uploadVideo(string $uploadUri, string $token, string $verifiedBytes, string $mime, string $expectedSha256): array
    {
        $this->calls[] = 'upload';
        $this->uploadedSha = hash('sha256', $verifiedBytes);
        if (!hash_equals(strtolower($expectedSha256), $this->uploadedSha)) {
            throw new \RuntimeException('fake_instagram_upload_digest_mismatch');
        }
        return $this->next();
    }

    public function containerStatus(string $version, string $containerId, string $token): array
    {
        $this->calls[] = 'status';
        return $this->next();
    }

    public function publishContainer(string $version, string $userId, string $containerId, string $token): array
    {
        $this->calls[] = 'publish';
        return $this->next();
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function next(): array
    {
        $response = array_shift($this->responses);
        if (!is_array($response)) {
            throw new \RuntimeException('fake_instagram_response_exhausted');
        }
        return $response;
    }
}
