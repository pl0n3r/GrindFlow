<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Kernel;
use GrindFlow\Observability\DiagnosticReceipt;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ensures a real Symfony request ID can be carried through typed offline
 * diagnostic receipts. This does not instrument or invoke a live job/provider.
 */
final class RequestToJobPublicationDiagnosticE2eTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testHttpRequestCorrelatesSyntheticJobAndPublicationReceipts(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();

        $requestId = $client->getResponse()->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/D', $requestId);
        self::assertSame($requestId, $client->getRequest()->attributes->get('_grindflow_request_id'));

        $jobId = 'job_'.str_repeat('a', 24);
        $publicationId = 'pub_'.str_repeat('b', 24);
        $job = DiagnosticReceipt::fromTrustedMetadata([
            'event_id' => 'evt_'.str_repeat('c', 24),
            'kind' => 'JOB_FAILURE',
            'state' => 'FAILED',
            'request_id' => $requestId,
            'job_id' => $jobId,
        ]);
        $publication = DiagnosticReceipt::fromTrustedMetadata([
            'event_id' => 'evt_'.str_repeat('d', 24),
            'kind' => 'PUBLICATION_FAILURE',
            'state' => 'FAILED',
            'request_id' => $requestId,
            'job_id' => $jobId,
            'publication_id' => $publicationId,
        ]);
        self::assertSame(['request_id' => $requestId, 'job_id' => $jobId], $job['correlation']);
        self::assertSame([
            'request_id' => $requestId, 'job_id' => $jobId, 'publication_id' => $publicationId,
        ], $publication['correlation']);
        foreach ([$job, $publication] as $receipt) {
            self::assertSame('INVESTIGATE', $receipt['diagnostic_status']);
            self::assertFalse($receipt['operational_ready']);
            self::assertSame(1, $receipt['schema_version']);
        }
    }

    public function testSpoofedHeadersAndUnsafeStatesFailClosed(): void
    {
        $client = static::createClient();
        $spoofed = 'req_'.str_repeat('f', 24);
        $client->request('GET', '/health', server: [
            'HTTP_X_REQUEST_ID' => $spoofed,
            'HTTP_AUTHORIZATION' => 'Bearer synthetic-private-test-value',
        ]);
        self::assertResponseIsSuccessful();
        $generated = $client->getResponse()->headers->get('X-Request-Id');
        self::assertIsString($generated);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/D', $generated);
        self::assertNotSame($spoofed, $generated);
        self::assertSame($generated, $client->getRequest()->attributes->get('_grindflow_request_id'));

        foreach (['UNKNOWN', 'STALE'] as $state) {
            $receipt = DiagnosticReceipt::fromTrustedMetadata([
                'event_id' => 'evt_'.str_repeat('e', 24),
                'kind' => 'JOB_FAILURE',
                'state' => $state,
                'request_id' => $generated,
                'job_id' => 'job_'.str_repeat('a', 24),
            ]);
            self::assertSame('BLOCKED', $receipt['diagnostic_status']);
            self::assertFalse($receipt['operational_ready']);
            self::assertSame($generated, $receipt['correlation']['request_id']);
            self::assertStringNotContainsString('synthetic-private-test-value', json_encode($receipt, JSON_THROW_ON_ERROR));
        }

        $base = [
            'event_id' => 'evt_'.str_repeat('c', 24),
            'kind' => 'PUBLICATION_FAILURE',
            'state' => 'FAILED',
            'request_id' => $generated,
            'publication_id' => 'pub_'.str_repeat('b', 24),
        ];
        foreach ([
            ['authorization' => 'Bearer synthetic-private-test-value'],
            ['email' => 'fixture-person@example.test'],
            ['publication_id' => 'pub_not-opaque'],
            ['request_id' => $spoofed.'-injection'],
            ['state' => 'HEALTHY'],
        ] as $invalid) {
            try {
                DiagnosticReceipt::fromTrustedMetadata(array_replace($base, $invalid));
                self::fail('Unsafe diagnostic metadata must be rejected.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('INVALID_DIAGNOSTIC_METADATA', $e->getMessage());
                self::assertStringNotContainsString('synthetic-private-test-value', $e->getMessage());
                self::assertStringNotContainsString('@example.test', $e->getMessage());
            }
        }
    }
}
