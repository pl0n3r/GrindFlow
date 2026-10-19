<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionOutcome;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\InstagramReelConfiguration;
use GrindFlow\Distribution\InstagramReelProvider;
use GrindFlow\Distribution\InstagramReelTransport;
use PHPUnit\Framework\TestCase;

final class InstagramReelProviderTest extends TestCase
{
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

    private function provider(FakeInstagramReelTransport $transport, bool $enabled = true, ?string $vaultRoot = null, ?\Closure $sleeper = null): InstagramReelProvider
    {
        return new InstagramReelProvider(
            new InstagramReelConfiguration(
                self::ORGANIZATION, '1234567890', 'fixture-ig-token',
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
