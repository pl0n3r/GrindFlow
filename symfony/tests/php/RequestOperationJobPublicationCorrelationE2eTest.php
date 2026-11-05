<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPagePublicationService;
use GrindFlow\Distribution\FacebookPageTransport;
use GrindFlow\Infrastructure\Scheduler\JobRunEvidence;
use GrindFlow\Kernel;
use GrindFlow\Observability\RequestOperationJobPublicationCorrelation;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Real Symfony request + publication service/ledger, synthetic in-memory transport. */
final class RequestOperationJobPublicationCorrelationE2eTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testRequestJobHandlerAndPublicationFailureShareServerIds(): void
    {
        $client = static::createClient();
        $spoofed = 'req_'.str_repeat('f', 24);
        $client->request('GET', '/health', server: [
            'HTTP_X_REQUEST_ID' => $spoofed,
            'HTTP_AUTHORIZATION' => 'Bearer synthetic-secret-sentinel',
        ]);
        self::assertResponseIsSuccessful();
        $requestId = $client->getResponse()->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/\Areq_[a-f0-9]{24}\z/D', $requestId);
        self::assertNotSame($spoofed, $requestId);

        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = self::organization($db);
        $key = 'gf909-'.Uuid::v7()->toRfc4122();
        $command = new DistributionCommand($organization, $key, 'synthetic-payload-sentinel');
        $transport = new CorrelationMemoryTransport(503);
        $handler = self::publisher($db, $organization, $transport);
        $jobs = new JobRunEvidence();
        $context = RequestOperationJobPublicationCorrelation::fromTrustedRequest(
            $client->getRequest(), $organization, $command,
        );

        $result = $context->execute($handler, $jobs);

        self::assertSame('failed', $result['status']);
        self::assertSame($requestId, $result['request_id']);
        self::assertMatchesRegularExpression('/\Aop_[a-f0-9]{24}\z/D', $result['operation_id']);
        self::assertMatchesRegularExpression('/\Ajob_[a-f0-9]{24}\z/D', $result['job_id']);
        self::assertMatchesRegularExpression('/\Apub_[a-f0-9]{24}\z/D', $result['publication_id']);
        self::assertFalse($result['operational_ready']);
        self::assertSame(['JOB_FAILURE', 'PUBLICATION_FAILURE'], array_column($result['receipts'], 'kind'));
        self::assertSame(
            ['request_id' => $requestId, 'job_id' => $result['job_id']],
            $result['receipts'][0]['correlation'],
        );
        self::assertSame(
            ['request_id' => $requestId, 'job_id' => $result['job_id'], 'publication_id' => $result['publication_id']],
            $result['receipts'][1]['correlation'],
        );
        self::assertSame(1, $transport->calls);
        self::assertSame('ambiguous', $db->fetchOne(
            'SELECT status FROM gf_external_publication_attempts WHERE organization_id = :org AND idempotency_key = :key',
            ['org' => $organization, 'key' => $key],
        ));
        self::assertSame('failed', $jobs->records()[0]['result']);
        self::assertCount(1, $jobs->records());
        $safe = json_encode([$result, $jobs->records()], JSON_THROW_ON_ERROR);
        foreach (['synthetic-secret-sentinel', 'synthetic-payload-sentinel', $organization, $key] as $private) {
            self::assertStringNotContainsString($private, $safe);
        }

        self::assertInvalid(fn () => $context->execute($handler, $jobs));
        self::assertSame(1, $transport->calls);
    }

    public function testCrossTenantAndMalformedTrustedAttributesFailBeforeHandler(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health', server: [
            'HTTP_X_REQUEST_ID' => 'req_'.str_repeat('e', 24),
        ]);
        self::assertResponseIsSuccessful();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = self::organization($db);
        $foreign = self::organization($db);
        $transport = new CorrelationMemoryTransport(200);
        $handler = self::publisher($db, $organization, $transport);

        self::assertInvalid(fn () => RequestOperationJobPublicationCorrelation::fromTrustedRequest(
            $client->getRequest(), $organization,
            new DistributionCommand($foreign, 'foreign-'.Uuid::v7()->toRfc4122(), 'fixture'),
        ));

        $client->getRequest()->attributes->set('_grindflow_request_id', 'req_forged-header');
        self::assertInvalid(fn () => RequestOperationJobPublicationCorrelation::fromTrustedRequest(
            $client->getRequest(), $organization,
            new DistributionCommand($organization, 'malformed-'.Uuid::v7()->toRfc4122(), 'fixture'),
        ));
        self::assertSame(0, $transport->calls);
        self::assertCount(0, (new JobRunEvidence())->records());
    }

    public function testSuccessfulSyntheticConsumptionStillDoesNotClaimLiveReadiness(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = self::organization($db);
        $transport = new CorrelationMemoryTransport(200);
        $jobs = new JobRunEvidence();
        $context = RequestOperationJobPublicationCorrelation::fromTrustedRequest(
            $client->getRequest(), $organization,
            new DistributionCommand($organization, 'successful-'.Uuid::v7()->toRfc4122(), 'fixture'),
        );
        $result = $context->execute(self::publisher($db, $organization, $transport), $jobs);
        self::assertSame('succeeded', $result['status']);
        self::assertSame([], $result['receipts']);
        self::assertSame('succeeded', $jobs->records()[0]['result']);
        self::assertSame(1, $transport->calls);
        self::assertFalse($result['operational_ready']);
    }

    private static function publisher(Connection $db, string $organization, CorrelationMemoryTransport $transport): FacebookPagePublicationService
    {
        return new FacebookPagePublicationService($db, new FacebookPageProvider(
            new FacebookPageConfiguration($organization, '1234567890', 'synthetic-secret-sentinel', 'v26.0'),
            $transport,
        ));
    }

    private static function organization(Connection $db): string
    {
        $id = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_organizations', [
            'id' => $id,
            'name' => 'GF909 synthetic',
            'slug' => 'gf909-'.substr(str_replace('-', '', $id), 0, 16),
            'type' => 'independent',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private static function assertInvalid(callable $action): void
    {
        try {
            $action();
            self::fail('Untrusted correlation must fail closed.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('INVALID_REQUEST_JOB_PUBLICATION_CORRELATION', $error->getMessage());
        }
    }
}

/** @internal Test-only transport; it has no network implementation. */
final class CorrelationMemoryTransport implements FacebookPageTransport
{
    public int $calls = 0;

    public function __construct(private readonly int $status) {}

    public function postFeed(string $graphVersion, string $pageId, string $accessToken, array $payload): array
    {
        ++$this->calls;
        return ['status' => $this->status, 'headers' => [], 'body' => $this->status === 200
            ? '{"id":"synthetic_123456"}' : '{"error":"synthetic-secret-sentinel"}'];
    }

    public function postPhoto(string $graphVersion, string $pageId, string $accessToken, string $caption, string $filePath, string $mimeType): array
    {
        throw new \LogicException('Synthetic photo transport is intentionally unavailable.');
    }
}
