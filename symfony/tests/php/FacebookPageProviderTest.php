<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionProvider;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPagePublicationService;
use GrindFlow\Distribution\FacebookPageTransport;
use GrindFlow\Kernel;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class FacebookPageProviderTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testProviderContractIsFrameworkIndependent(): void
    {
        self::assertTrue(is_a(FacebookPageProvider::class, DistributionProvider::class, true));

        $command = new DistributionCommand(
            Uuid::v7()->toRfc4122(),
            'provider-contract',
            'Mensaje de prueba',
            'https://www.grindflow.com.co/piloto',
        );

        self::assertSame('provider-contract', $command->idempotencyKey);
        self::assertSame('Mensaje de prueba', $command->message);
    }

    public function testProviderBuildsSafeOfficialPageFeedRequest(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $token = 'test-facebook-page-token-do-not-log';
        $transport = new FakeFacebookPageTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"123456_987654"}'],
        ]);
        $provider = $this->provider($organization, $token, $transport);

        $outcome = $provider->publish(new DistributionCommand(
            $organization,
            'facebook-success',
            'Publicación de piloto',
            'https://www.grindflow.com.co/piloto',
        ));

        self::assertSame('123456_987654', $outcome->externalPublicationId);
        self::assertSame(1, $transport->calls);
        self::assertSame('v26.0', $transport->requests[0]['graph_version']);
        self::assertSame('1234567890', $transport->requests[0]['page_id']);
        self::assertSame([
            'message' => 'Publicación de piloto',
            'link' => 'https://www.grindflow.com.co/piloto',
        ], $transport->requests[0]['payload']);
        self::assertStringNotContainsString($token, $outcome->externalPublicationId);
    }

    public function testProviderUsesExplicitPhotoTransportForPrivateMedia(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $transport = new FakeFacebookPageTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"page_photo_456"}'],
        ]);
        $provider = $this->provider(
            $organization,
            'test-facebook-page-token-do-not-log',
            $transport,
        );
        $path = tempnam(sys_get_temp_dir(), 'grindflow-photo-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, 'private-photo'));
        $command = new DistributionCommand(
            $organization,
            'photo-'.Uuid::v7()->toRfc4122(),
            'Caption de foto',
            null,
            $path,
            'image/png',
            hash('sha256', 'private-photo'),
        );

        try {
            $outcome = $provider->publish($command);
        } finally {
            @unlink($path);
        }

        self::assertSame('page_photo_456', $outcome->externalPublicationId);
        self::assertSame(1, $transport->calls);
        self::assertCount(1, $transport->requests);
        self::assertSame('Caption de foto', $transport->requests[0]['payload']['message']);
        self::assertStringContainsString(':image/png', $transport->requests[0]['payload']['link']);
    }

    public function testProviderFailsClosedBeforeIo(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $transport = new FakeFacebookPageTransport();

        $unconfigured = new FacebookPageProvider(
            new FacebookPageConfiguration('', '', '', 'v26.0'),
            $transport,
        );
        $this->assertProviderExceptionKind(
            fn () => $unconfigured->publish(new DistributionCommand(
                $organization,
                'missing-config',
                'No debe salir',
            )),
            DistributionProviderException::KIND_CONFIGURATION,
        );

        $provider = $this->provider(
            $organization,
            'test-facebook-page-token-do-not-log',
            $transport,
        );
        $this->assertProviderExceptionKind(
            fn () => $provider->publish(new DistributionCommand(
                $other,
                'wrong-tenant',
                'No debe salir',
            )),
            DistributionProviderException::KIND_CONFIGURATION,
        );

        $invalidPage = new FacebookPageProvider(
            new FacebookPageConfiguration(
                $organization,
                'not-a-page',
                'test-facebook-page-token-do-not-log',
                'v26.0',
            ),
            $transport,
        );
        $this->assertProviderExceptionKind(
            fn () => $invalidPage->publish(new DistributionCommand(
                $organization,
                'bad-page',
                'No debe salir',
            )),
            DistributionProviderException::KIND_CONFIGURATION,
        );

        $invalidVersion = new FacebookPageProvider(
            new FacebookPageConfiguration(
                $organization,
                '1234567890',
                'test-facebook-page-token-do-not-log',
                'latest',
            ),
            $transport,
        );
        $this->assertProviderExceptionKind(
            fn () => $invalidVersion->publish(new DistributionCommand(
                $organization,
                'bad-version',
                'No debe salir',
            )),
            DistributionProviderException::KIND_CONFIGURATION,
        );

        self::assertSame(0, $transport->calls);
    }

    public function testProviderClassifiesFailuresWithoutLeakingSecrets(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $token = 'test-facebook-page-token-do-not-log';

        foreach ([401, 403] as $status) {
            $transport = new FakeFacebookPageTransport([
                ['status' => $status, 'headers' => [], 'body' => '{"error":{"message":"'.$token.'"}}'],
            ]);
            $exception = $this->captureProviderException(
                fn () => $this->provider($organization, $token, $transport)->publish(
                    new DistributionCommand($organization, 'auth-'.$status, 'Mensaje'),
                ),
            );
            self::assertSame(DistributionProviderException::KIND_AUTHENTICATION, $exception->kind);
            self::assertFalse($exception->automaticRetryAllowed);
            self::assertStringNotContainsString($token, $exception->getMessage());
        }

        $rateTransport = new FakeFacebookPageTransport([
            ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '{"error":"rate"}'],
        ]);
        $rate = $this->captureProviderException(
            fn () => $this->provider($organization, $token, $rateTransport)->publish(
                new DistributionCommand($organization, 'rate-limit', 'Mensaje'),
            ),
        );
        self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $rate->kind);
        self::assertTrue($rate->automaticRetryAllowed);
        self::assertSame(120, $rate->retryAfterSeconds);

        $rejectedTransport = new FakeFacebookPageTransport([
            ['status' => 422, 'headers' => [], 'body' => '{"error":"invalid"}'],
        ]);
        $rejected = $this->captureProviderException(
            fn () => $this->provider($organization, $token, $rejectedTransport)->publish(
                new DistributionCommand($organization, 'rejected', 'Mensaje'),
            ),
        );
        self::assertSame(DistributionProviderException::KIND_REJECTED, $rejected->kind);
        self::assertFalse($rejected->automaticRetryAllowed);

        $serverTransport = new FakeFacebookPageTransport([
            ['status' => 503, 'headers' => [], 'body' => '{"error":"unknown"}'],
        ]);
        $ambiguous = $this->captureProviderException(
            fn () => $this->provider($organization, $token, $serverTransport)->publish(
                new DistributionCommand($organization, 'server-error', 'Mensaje'),
            ),
        );
        self::assertSame(DistributionProviderException::KIND_AMBIGUOUS, $ambiguous->kind);
        self::assertFalse($ambiguous->automaticRetryAllowed);

        $networkTransport = new FakeFacebookPageTransport([], new RuntimeException($token));
        $network = $this->captureProviderException(
            fn () => $this->provider($organization, $token, $networkTransport)->publish(
                new DistributionCommand($organization, 'network-error', 'Mensaje'),
            ),
        );
        self::assertSame(DistributionProviderException::KIND_AMBIGUOUS, $network->kind);
        self::assertStringNotContainsString($token, $network->getMessage());
    }

    public function testPublicationRejectsExternalTransactionBeforeProviderIo(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $transport = new FakeFacebookPageTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"should_not_publish"}'],
        ]);
        $service = new FacebookPagePublicationService(
            $db,
            $this->provider(
                $organization,
                'test-facebook-page-token-do-not-log',
                $transport,
            ),
        );
        $command = new DistributionCommand(
            $organization,
            'outer-transaction-'.Uuid::v7()->toRfc4122(),
            'No publicar dentro de una transacción externa',
        );

        $db->beginTransaction();

        try {
            $failure = $this->captureProviderException(
                fn () => $service->publish($command),
            );

            self::assertSame(DistributionProviderException::KIND_CONFIGURATION, $failure->kind);
            self::assertSame(0, $transport->calls);
            self::assertSame(1, $db->getTransactionNestingLevel());
        } finally {
            if ($db->getTransactionNestingLevel() > 0) {
                $db->rollBack();
            }
        }

        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
            ['organization' => $organization, 'key' => $command->idempotencyKey],
        ));
    }

    public function testIdempotencyLedgerPreventsDuplicateProviderCalls(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $other = Uuid::v7()->toRfc4122();
        $token = 'test-facebook-page-token-do-not-log';

        $transport = new FakeFacebookPageTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"123456_111"}'],
        ]);
        $service = new FacebookPagePublicationService(
            $db,
            $this->provider($organization, $token, $transport),
        );
        $command = new DistributionCommand(
            $organization,
            'dedupe-'.Uuid::v7()->toRfc4122(),
            'Una sola publicación',
        );

        $first = $service->publish($command);
        $second = $service->publish($command);

        self::assertSame('123456_111', $first->externalPublicationId);
        self::assertSame($first->externalPublicationId, $second->externalPublicationId);
        self::assertSame(1, $transport->calls);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
            ['organization' => $organization, 'key' => $command->idempotencyKey],
        ));

        $stored = $db->fetchAssociative(
            'SELECT provider, idempotency_key, request_fingerprint, status, external_publication_id FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
            ['organization' => $organization, 'key' => $command->idempotencyKey],
        );
        self::assertIsArray($stored);
        self::assertStringNotContainsString($token, json_encode($stored, JSON_THROW_ON_ERROR));

        $ambiguousTransport = new FakeFacebookPageTransport(
            [],
            new RuntimeException('transport lost after send'),
        );
        $ambiguousService = new FacebookPagePublicationService(
            $db,
            $this->provider($organization, $token, $ambiguousTransport),
        );
        $ambiguousCommand = new DistributionCommand(
            $organization,
            'ambiguous-'.Uuid::v7()->toRfc4122(),
            'No reintentar a ciegas',
        );

        $firstFailure = $this->captureProviderException(
            fn () => $ambiguousService->publish($ambiguousCommand),
        );
        self::assertSame(DistributionProviderException::KIND_AMBIGUOUS, $firstFailure->kind);

        $secondFailure = $this->captureProviderException(
            fn () => $ambiguousService->publish($ambiguousCommand),
        );
        self::assertSame(DistributionProviderException::KIND_AMBIGUOUS, $secondFailure->kind);
        self::assertSame(1, $ambiguousTransport->calls);

        $inFlightTransport = new FakeFacebookPageTransport();
        $inFlightService = new FacebookPagePublicationService(
            $db,
            $this->provider($organization, $token, $inFlightTransport),
        );
        $inFlightCommand = new DistributionCommand(
            $organization,
            'in-flight-'.Uuid::v7()->toRfc4122(),
            'No duplicar claim incierto',
        );
        $now = gmdate('Y-m-d H:i:s');

        $db->insert('gf_external_publication_attempts', [
            'id' => Uuid::v7()->toRfc4122(),
            'organization_id' => $organization,
            'provider' => 'facebook_page',
            'idempotency_key' => $inFlightCommand->idempotencyKey,
            'request_fingerprint' => $this->fingerprint($inFlightCommand),
            'status' => 'in_flight',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $inFlightFailure = $this->captureProviderException(
            fn () => $inFlightService->publish($inFlightCommand),
        );
        self::assertSame(DistributionProviderException::KIND_AMBIGUOUS, $inFlightFailure->kind);
        self::assertSame(0, $inFlightTransport->calls);

        $this->assertProviderExceptionKind(
            fn () => $service->publish(new DistributionCommand(
                $other,
                'foreign-'.Uuid::v7()->toRfc4122(),
                'Tenant ajeno',
            )),
            DistributionProviderException::KIND_CONFIGURATION,
        );
        self::assertSame(1, $transport->calls);
    }

    public function testIdempotencyKeyRejectsDifferentFingerprintWithoutSecondCall(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $transport = new FakeFacebookPageTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"123456_222"}'],
        ]);
        $service = new FacebookPagePublicationService(
            $db,
            $this->provider(
                $organization,
                'test-facebook-page-token-do-not-log',
                $transport,
            ),
        );
        $key = 'fingerprint-'.Uuid::v7()->toRfc4122();

        $service->publish(new DistributionCommand(
            $organization,
            $key,
            'Mensaje original',
            'https://www.grindflow.com.co/original',
        ));

        $failure = $this->captureProviderException(
            fn () => $service->publish(new DistributionCommand(
                $organization,
                $key,
                'Mensaje cambiado',
                'https://www.grindflow.com.co/cambiado',
            )),
        );

        self::assertSame(DistributionProviderException::KIND_REJECTED, $failure->kind);
        self::assertSame(1, $transport->calls);
        self::assertSame('published', (string) $db->fetchOne(
            'SELECT status FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
            ['organization' => $organization, 'key' => $key],
        ));
    }

    public function testPageChangeRejectsPublishedAndRateLimitedReplayWithoutSecondCall(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $token = 'test-facebook-page-token-do-not-log';

        $publishedTransport = new FakeFacebookPageTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"page_a_publication"}'],
        ]);
        $publishedCommand = new DistributionCommand(
            $organization,
            'page-change-published-'.Uuid::v7()->toRfc4122(),
            'Destino A',
        );
        $pageAService = new FacebookPagePublicationService(
            $db,
            $this->provider($organization, $token, $publishedTransport, '1111111111'),
        );

        $pageAService->publish($publishedCommand);
        self::assertSame(1, $publishedTransport->calls);

        $pageBPublishedTransport = new FakeFacebookPageTransport();
        $pageBPublishedService = new FacebookPagePublicationService(
            $db,
            $this->provider($organization, $token, $pageBPublishedTransport, '2222222222'),
        );
        $publishedFailure = $this->captureProviderException(
            fn () => $pageBPublishedService->publish($publishedCommand),
        );

        self::assertSame(DistributionProviderException::KIND_REJECTED, $publishedFailure->kind);
        self::assertSame(0, $pageBPublishedTransport->calls);

        $now = new DateTimeImmutable('2026-10-03 19:00:00', new DateTimeZone('UTC'));
        $clock = static function () use (&$now): DateTimeImmutable {
            return $now;
        };
        $rateTransport = new FakeFacebookPageTransport([
            ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '{"error":"rate"}'],
        ]);
        $rateCommand = new DistributionCommand(
            $organization,
            'page-change-rate-'.Uuid::v7()->toRfc4122(),
            'Rate limited en A',
        );
        $ratePageAService = new FacebookPagePublicationService(
            $db,
            $this->provider($organization, $token, $rateTransport, '3333333333'),
            $clock,
        );

        $rateFailure = $this->captureProviderException(
            fn () => $ratePageAService->publish($rateCommand),
        );
        self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $rateFailure->kind);
        self::assertSame(1, $rateTransport->calls);

        $now = $now->modify('+121 seconds');
        $pageBRateTransport = new FakeFacebookPageTransport();
        $ratePageBService = new FacebookPagePublicationService(
            $db,
            $this->provider($organization, $token, $pageBRateTransport, '4444444444'),
            $clock,
        );
        $pageChangedFailure = $this->captureProviderException(
            fn () => $ratePageBService->publish($rateCommand),
        );

        self::assertSame(DistributionProviderException::KIND_REJECTED, $pageChangedFailure->kind);
        self::assertSame(0, $pageBRateTransport->calls);
    }

    public function testRateLimitLedgerBlocksEarlyRetryAndAllowsOneRetryAfterWindow(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $now = new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC'));
        $clock = static function () use (&$now): DateTimeImmutable {
            return $now;
        };
        $transport = new FakeFacebookPageTransport([
            ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '{"error":"rate"}'],
            ['status' => 200, 'headers' => [], 'body' => '{"id":"123456_333"}'],
        ]);
        $service = new FacebookPagePublicationService(
            $db,
            $this->provider(
                $organization,
                'test-facebook-page-token-do-not-log',
                $transport,
            ),
            $clock,
        );
        $command = new DistributionCommand(
            $organization,
            'rate-ledger-'.Uuid::v7()->toRfc4122(),
            'Respeta el límite',
        );

        $first = $this->captureProviderException(fn () => $service->publish($command));
        self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $first->kind);
        self::assertSame(120, $first->retryAfterSeconds);
        self::assertSame(1, $transport->calls);

        $row = $db->fetchAssociative(
            'SELECT status, retry_after_seconds, retry_not_before FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
            ['organization' => $organization, 'key' => $command->idempotencyKey],
        );
        self::assertIsArray($row);
        self::assertSame('rate_limited', $row['status']);
        self::assertSame(120, (int) $row['retry_after_seconds']);
        self::assertSame('2026-10-03 18:02:00', (string) $row['retry_not_before']);

        $early = $this->captureProviderException(fn () => $service->publish($command));
        self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $early->kind);
        self::assertSame(120, $early->retryAfterSeconds);
        self::assertSame(1, $transport->calls);

        $now = $now->modify('+121 seconds');
        $outcome = $service->publish($command);

        self::assertSame('123456_333', $outcome->externalPublicationId);
        self::assertSame(2, $transport->calls);

        $published = $db->fetchAssociative(
            'SELECT status, external_publication_id, retry_after_seconds, retry_not_before FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
            ['organization' => $organization, 'key' => $command->idempotencyKey],
        );
        self::assertIsArray($published);
        self::assertSame('published', $published['status']);
        self::assertSame('123456_333', $published['external_publication_id']);
        self::assertNull($published['retry_after_seconds']);
        self::assertNull($published['retry_not_before']);
    }

    public function testActiveExternalTransactionIsRejectedBeforeProviderIo(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $transport = new FakeFacebookPageTransport();
        $service = new FacebookPagePublicationService(
            $db,
            $this->provider(
                $organization,
                'test-facebook-page-token-do-not-log',
                $transport,
            ),
        );
        $command = new DistributionCommand(
            $organization,
            'external-transaction-'.Uuid::v7()->toRfc4122(),
            'No publicar dentro de una transacción externa',
        );

        $db->beginTransaction();
        try {
            $failure = $this->captureProviderException(
                fn () => $service->publish($command),
            );

            self::assertSame(
                DistributionProviderException::KIND_CONFIGURATION,
                $failure->kind,
            );
            self::assertSame(0, $transport->calls);
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
                ['organization' => $organization, 'key' => $command->idempotencyKey],
            ));
        } finally {
            if ($db->getTransactionNestingLevel() > 0) {
                $db->rollBack();
            }
        }
    }

    public function testDestinationPageChangeRejectsPublishedReplayWithoutProviderIo(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $key = 'page-published-'.Uuid::v7()->toRfc4122();
        $command = new DistributionCommand($organization, $key, 'Destino inmutable');

        $firstTransport = new FakeFacebookPageTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"111111_222222"}'],
        ]);
        $firstService = new FacebookPagePublicationService(
            $db,
            $this->provider(
                $organization,
                'test-facebook-page-token-do-not-log',
                $firstTransport,
                '111111',
            ),
        );
        $firstService->publish($command);
        self::assertSame(1, $firstTransport->calls);

        $secondTransport = new FakeFacebookPageTransport();
        $secondService = new FacebookPagePublicationService(
            $db,
            $this->provider(
                $organization,
                'test-facebook-page-token-do-not-log',
                $secondTransport,
                '222222',
            ),
        );

        $failure = $this->captureProviderException(
            fn () => $secondService->publish($command),
        );

        self::assertSame(DistributionProviderException::KIND_REJECTED, $failure->kind);
        self::assertSame(0, $secondTransport->calls);
        self::assertSame('published', (string) $db->fetchOne(
            'SELECT status FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
            ['organization' => $organization, 'key' => $key],
        ));
    }

    public function testDestinationPageChangeRejectsRateLimitedReplayWithoutProviderIo(): void
    {
        static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = $this->organization($db);
        $now = new DateTimeImmutable('2026-10-03 19:00:00', new DateTimeZone('UTC'));
        $clock = static function () use (&$now): DateTimeImmutable {
            return $now;
        };
        $key = 'page-rate-limited-'.Uuid::v7()->toRfc4122();
        $command = new DistributionCommand(
            $organization,
            $key,
            'Destino rate limited inmutable',
        );

        $firstTransport = new FakeFacebookPageTransport([
            ['status' => 429, 'headers' => ['retry-after' => '60'], 'body' => '{"error":"rate"}'],
        ]);
        $firstService = new FacebookPagePublicationService(
            $db,
            $this->provider(
                $organization,
                'test-facebook-page-token-do-not-log',
                $firstTransport,
                '111111',
            ),
            $clock,
        );

        $initial = $this->captureProviderException(
            fn () => $firstService->publish($command),
        );
        self::assertSame(DistributionProviderException::KIND_RATE_LIMIT, $initial->kind);
        self::assertSame(1, $firstTransport->calls);

        $now = $now->modify('+61 seconds');

        $secondTransport = new FakeFacebookPageTransport([
            ['status' => 200, 'headers' => [], 'body' => '{"id":"222222_333333"}'],
        ]);
        $secondService = new FacebookPagePublicationService(
            $db,
            $this->provider(
                $organization,
                'test-facebook-page-token-do-not-log',
                $secondTransport,
                '222222',
            ),
            $clock,
        );

        $failure = $this->captureProviderException(
            fn () => $secondService->publish($command),
        );

        self::assertSame(DistributionProviderException::KIND_REJECTED, $failure->kind);
        self::assertSame(0, $secondTransport->calls);
        self::assertSame('rate_limited', (string) $db->fetchOne(
            'SELECT status FROM gf_external_publication_attempts WHERE organization_id = :organization AND idempotency_key = :key',
            ['organization' => $organization, 'key' => $key],
        ));
    }

    private function provider(
        string $organization,
        string $token,
        FacebookPageTransport $transport,
        string $pageId = '1234567890',
    ): FacebookPageProvider {
        return new FacebookPageProvider(
            new FacebookPageConfiguration(
                $organization,
                $pageId,
                $token,
                'v26.0',
            ),
            $transport,
        );
    }

    private function organization(Connection $db): string
    {
        $id = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');

        $db->insert('gf_identity_organizations', [
            'id' => $id,
            'name' => 'Facebook pilot '.substr($id, 0, 8),
            'slug' => 'facebook-pilot-'.substr(str_replace('-', '', $id), 0, 16),
            'type' => 'independent',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function fingerprint(
        DistributionCommand $command,
        string $pageId = '1234567890',
    ): string {
        return hash('sha256', json_encode([
            'provider' => 'facebook_page',
            'organization_id' => strtolower($command->organizationId),
            'page_id' => $pageId,
            'message' => $command->message,
            'link' => $command->link,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertProviderExceptionKind(callable $callback, string $kind): void
    {
        self::assertSame($kind, $this->captureProviderException($callback)->kind);
    }

    private function captureProviderException(callable $callback): DistributionProviderException
    {
        try {
            $callback();
        } catch (DistributionProviderException $exception) {
            return $exception;
        }

        self::fail('Expected DistributionProviderException.');
    }
}

/** @internal test-only transport; it never performs network I/O. */
final class FakeFacebookPageTransport implements FacebookPageTransport
{
    public int $calls = 0;

    /** @var list<array{graph_version:string,page_id:string,access_token:string,payload:array{message:string,link?:string}}> */
    public array $requests = [];

    /**
     * @param list<array{status:int,headers:array<string,string>,body:string}> $responses
     */
    public function __construct(
        private array $responses = [],
        private ?RuntimeException $failure = null,
    ) {}

    public function postFeed(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        array $payload,
    ): array {
        ++$this->calls;
        $this->requests[] = [
            'graph_version' => $graphVersion,
            'page_id' => $pageId,
            'access_token' => $accessToken,
            'payload' => $payload,
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $response = array_shift($this->responses);
        if (!is_array($response)) {
            return [
                'status' => 200,
                'headers' => [],
                'body' => '{"id":"default_test_publication"}',
            ];
        }

        return $response;
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
        $this->requests[] = [
            'graph_version' => $graphVersion,
            'page_id' => $pageId,
            'access_token' => $accessToken,
            'payload' => [
                'message' => $caption,
                'link' => 'private-media:'.basename($filePath).':'.$mimeType,
            ],
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $response = array_shift($this->responses);
        if (!is_array($response)) {
            return [
                'status' => 200,
                'headers' => [],
                'body' => '{"id":"default_test_photo_publication"}',
            ];
        }

        return $response;
    }
}
