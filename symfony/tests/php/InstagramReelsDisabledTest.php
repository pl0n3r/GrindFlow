<?php

declare(strict_types=1);

namespace Instagramreelsdisabledtest;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\InstagramReelConfiguration;
use GrindFlow\Distribution\InstagramReelProvider;
use GrindFlow\Distribution\InstagramReelPublicationService;
use GrindFlow\Distribution\InstagramReelTransport;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Regression for GF #798: fail closed before ledger writes and Meta I/O. */
final class Tests extends TestCase
{
    private const ORGANIZATION = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function test_criterio_principal(): void
    {
        $db = $this->createMock(Connection::class);
        // The disabled gate must reject before construction/use of the ledger.
        $db->expects(self::never())->method('executeStatement');
        $db->expects(self::never())->method('executeQuery');
        $db->expects(self::never())->method('fetchAssociative');
        $db->expects(self::never())->method('fetchOne');

        $transport = new class implements InstagramReelTransport {
            public int $calls = 0;

            public function createContainer(string $version, string $userId, string $token, string $caption): array
            {
                ++$this->calls;
                throw new LogicException('Disabled Instagram Reels must not contact Meta');
            }

            public function uploadVideo(string $uploadUri, string $token, string $verifiedBytes, string $mime, string $expectedSha256): array
            {
                ++$this->calls;
                throw new LogicException('Disabled Instagram Reels must not contact Meta');
            }

            public function containerStatus(string $version, string $containerId, string $token): array
            {
                ++$this->calls;
                throw new LogicException('Disabled Instagram Reels must not contact Meta');
            }

            public function publishContainer(string $version, string $userId, string $containerId, string $token): array
            {
                ++$this->calls;
                throw new LogicException('Disabled Instagram Reels must not contact Meta');
            }
        };

        // Other fields are deliberately valid: only the disabled gate must reject.
        $configuration = new InstagramReelConfiguration(
            self::ORGANIZATION, '1234567890', 'test-token-never-sent', 'v26.0',
            'instagram_content_publish', false, sys_get_temp_dir(),
        );
        $service = new InstagramReelPublicationService(
            $db, new InstagramReelProvider($configuration, $transport),
        );

        try {
            $service->publish(new DistributionCommand(
                self::ORGANIZATION, 'gf798-disabled-reel', 'Reel no autorizado',
            ));
            self::fail('Instagram Reels disabled gate must refuse publication');
        } catch (DistributionProviderException $e) {
            self::assertSame(DistributionProviderException::KIND_CONFIGURATION, $e->kind);
            self::assertFalse($e->automaticRetryAllowed);
        }

        self::assertSame(0, $transport->calls);
    }

    public function test_default_disabled_and_service_wiring(): void
    {
        $configuration = new InstagramReelConfiguration(
            self::ORGANIZATION, '1234567890', 'test-token-never-sent', 'v26.0',
            'instagram_content_publish',
        );
        try {
            $configuration->assertAvailableFor(self::ORGANIZATION);
            self::fail('Default Instagram Reels configuration must be disabled');
        } catch (DistributionProviderException $e) {
            self::assertSame(DistributionProviderException::KIND_CONFIGURATION, $e->kind);
        }

        $yaml = file_get_contents(dirname(__DIR__, 2).'/config/services.yaml');
        self::assertIsString($yaml);
        self::assertStringContainsString("grindflow_instagram_enabled_default: 'false'", $yaml);
        self::assertStringContainsString('GRINDFLOW_INSTAGRAM_REELS_ENABLED', $yaml);
        self::assertStringContainsString('InstagramReelPublicationService:', $yaml);
    }
}
