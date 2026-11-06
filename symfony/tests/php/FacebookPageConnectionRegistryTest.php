<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\DriverManager;
use GrindFlow\Distribution\DestinationHealthService;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageConnectionRegistry;
use GrindFlow\Distribution\FacebookPageTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** All account credentials and provider responses in this suite are synthetic. */
final class FacebookPageConnectionRegistryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/gf-pages-'.bin2hex(random_bytes(8)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testTwoPagesStayTenantScopedAndUseTheirOwnProviderIdentity(): void
    {
        $org = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        file_put_contents($this->file, json_encode([
            ['organization_id' => $org, 'page_id' => '101', 'access_token' => 'fake-page-101', 'graph_version' => 'v26.0'],
            ['organization_id' => $org, 'page_id' => '102', 'access_token' => 'fake-page-102', 'graph_version' => 'v26.0'],
            ['organization_id' => $other, 'page_id' => '201', 'access_token' => 'fake-page-201', 'graph_version' => 'v26.0'],
        ], JSON_THROW_ON_ERROR));
        chmod($this->file, 0600);

        $transport = new RecordingFacebookPageTransport();
        $seen = [];
        $probe = static function (FacebookPageConfiguration $config) use (&$seen): bool {
            $seen[] = $config->pageId();
            return in_array($config->pageId(), ['101', '102'], true);
        };
        $registry = new FacebookPageConnectionRegistry(
            new FacebookPageConfiguration('', '', '', ''), $transport, $this->file, $probe,
        );
        $health = new DestinationHealthService(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        self::assertSame(['101', '102'], $registry->pageIdsFor($org));
        self::assertSame(['201'], $registry->pageIdsFor($other));
        self::assertFalse($registry->owns($org, '201'));
        self::assertNull($registry->verifiedProviderFor($org, '201', $health));
        self::assertNull($registry->verifiedProviderFor($other, '201', $health));

        foreach (['101', '102'] as $id) {
            $provider = $registry->verifiedProviderFor($org, $id, $health);
            self::assertNotNull($provider);
            self::assertSame($id, $provider->destinationPageId());
            $receipt = $provider->publish(new DistributionCommand($org, 'fanout-'.$id.'-001', 'Synthetic caption'));
            self::assertSame('receipt-'.$id, $receipt->externalPublicationId);
        }
        self::assertSame(['201', '101', '102'], $seen);
        self::assertSame([
            ['101', 'fake-page-101'],
            ['102', 'fake-page-102'],
        ], $transport->sent);
    }

    public function testPublicOrInvalidCatalogIsNotAConnectionAndNeverFallsBack(): void
    {
        $org = Uuid::v7()->toRfc4122();
        $transport = new RecordingFacebookPageTransport();
        $legacy = new FacebookPageConfiguration($org, '100', 'fake-legacy', 'v26.0');
        $registry = new FacebookPageConnectionRegistry($legacy, $transport, $this->file);

        self::assertSame([], $registry->pageIdsFor($org));
        file_put_contents($this->file, '[{"organization_id":"malformed"}]');
        chmod($this->file, 0644);
        self::assertSame([], $registry->pageIdsFor($org));
        chmod($this->file, 0600);
        self::assertSame([], $registry->pageIdsFor($org));

        $legacyRegistry = new FacebookPageConnectionRegistry(
            $legacy, $transport, '', static fn (): bool => false,
        );
        self::assertSame(['100'], $legacyRegistry->pageIdsFor($org));
        $health = new DestinationHealthService(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        self::assertNull($legacyRegistry->verifiedProviderFor($org, '100', $health));
        self::assertSame([], $transport->sent);
    }
}

final class RecordingFacebookPageTransport implements FacebookPageTransport
{
    /** @var list<array{string,string}> */
    public array $sent = [];

    public function postFeed(
        string $graphVersion, string $pageId, string $accessToken, array $payload
    ): array {
        $this->sent[] = [$pageId, $accessToken];
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['id' => 'receipt-'.$pageId], JSON_THROW_ON_ERROR)];
    }

    public function postPhoto(
        string $graphVersion, string $pageId, string $accessToken,
        string $caption, string $filePath, string $mimeType
    ): array {
        throw new \LogicException('Photo upload not part of synthetic fan-out test.');
    }
}
