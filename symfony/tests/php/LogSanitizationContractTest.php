<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Kernel;
use GrindFlow\Observability\DiagnosticReceipt;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Strictly offline security contract for *structured diagnostic receipts*.
 * A local TestHandler is not proof that deployed Symfony/host logs are scrubbed.
 */
final class LogSanitizationContractTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testRealKernel500ResponseAndSyntheticDiagnosticLogContainNoSensitiveFixture(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);

        $token = 'synthetic-token-only-not-a-credential';
        $email = 'fictional-person@example.test';
        $marker = 'synthetic-exception-detail-must-not-escape';
        $spoofed = 'req_'.str_repeat('f', 24);
        $raised = false;

        self::assertSame('test', static::$kernel?->getEnvironment());
        self::assertFalse(static::$kernel?->isDebug());

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(
            KernelEvents::CONTROLLER,
            static function (ControllerEvent $event) use (&$raised, $token, $email, $marker): void {
                if (!$event->isMainRequest() || $event->getRequest()->getPathInfo() !== '/health') {
                    return;
                }
                $raised = true;
                throw new \RuntimeException($marker.' Authorization: Bearer '.$token.' '.$email);
            },
            1024,
        );

        $client->request('GET', '/health', server: [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_REQUEST_ID' => $spoofed,
        ]);
        self::assertTrue($raised, 'The kernel must actually process a thrown exception.');
        self::assertResponseStatusCodeSame(500);

        $response = $client->getResponse();
        $requestId = $response->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/D', $requestId);
        self::assertNotSame($spoofed, $requestId);
        self::assertSame($requestId, $client->getRequest()->attributes->get('_grindflow_request_id'));

        $receipt = DiagnosticReceipt::fromTrustedMetadata([
            'event_id' => 'evt_'.str_repeat('a', 24),
            'kind' => 'HTTP_500',
            'state' => 'FAILED',
            'request_id' => $requestId,
        ]);
        $log = new TestHandler();
        $logger = new Logger('offline-diagnostic', [$log]);
        $logger->error('HTTP_500', ['receipt' => $receipt]);
        $records = $log->getRecords();
        self::assertCount(1, $records);
        self::assertSame(['receipt' => $receipt], $records[0]->context);
        self::assertSame(['request_id' => $requestId], $receipt['correlation']);

        $visible = (string) $response->getContent()
            .json_encode($response->headers->all(), JSON_THROW_ON_ERROR)
            .json_encode($records[0]->context, JSON_THROW_ON_ERROR)
            .json_encode($receipt, JSON_THROW_ON_ERROR);
        $this->assertSanitized($visible, [$token, $email, $marker, $spoofed]);
        $this->assertReceiptKeys($receipt);
    }

    public function testSyntheticJobFailureOnlyEmitsAllowlistedReceiptFields(): void
    {
        $token = 'fake-job-secret-never-send';
        $email = 'job-fixture@example.test';
        $trace = 'private-synthetic-stack-frame';
        $source = [
            'event_id' => 'evt_'.str_repeat('b', 24),
            'kind' => 'JOB_FAILURE',
            'state' => 'FAILED',
            'request_id' => 'req_'.str_repeat('c', 24),
            'job_id' => 'job_'.str_repeat('d', 24),
        ];
        $handler = new TestHandler();
        $logger = new Logger('offline-job-diagnostic', [$handler]);

        // The actual failure may contain sensitive fields; trusted metadata must not.
        $unsafeFailure = [
            'authorization' => 'Bearer '.$token,
            'email' => $email,
            'stack_trace' => $trace,
            'exception_message' => 'job failed with '.$token,
        ];
        foreach ($unsafeFailure as $key => $value) {
            try {
                DiagnosticReceipt::fromTrustedMetadata($source + [$key => $value]);
                self::fail('Unsafe job metadata must be rejected before log emission: '.$key);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('INVALID_DIAGNOSTIC_METADATA', $exception->getMessage());
                $this->assertSanitized($exception->getMessage(), [$token, $email, $trace]);
            }
        }
        self::assertCount(0, $handler->getRecords(), 'Invalid metadata must never be logged.');

        $receipt = DiagnosticReceipt::fromTrustedMetadata($source);
        $logger->error('JOB_FAILURE', ['receipt' => $receipt]);
        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('JOB_FAILURE', $records[0]->message);
        self::assertSame(['receipt' => $receipt], $records[0]->context);
        self::assertSame('INVESTIGATE', $receipt['diagnostic_status']);
        self::assertFalse($receipt['operational_ready']);
        self::assertSame(
            ['request_id' => $source['request_id'], 'job_id' => $source['job_id']],
            $receipt['correlation'],
        );
        $this->assertReceiptKeys($receipt);
        $this->assertSanitized(json_encode($records[0]->context, JSON_THROW_ON_ERROR), [$token, $email, $trace]);
    }

    public function testInvalidIdentifiersAndStatesFailClosedWithoutEchoingPayload(): void
    {
        $source = [
            'event_id' => 'evt_'.str_repeat('a', 24),
            'kind' => 'JOB_FAILURE',
            'state' => 'FAILED',
            'job_id' => 'job_'.str_repeat('b', 24),
        ];
        foreach ([
            ['job_id' => 'job_'.str_repeat('a', 24).'@example.test'],
            ['event_id' => 'evt_'.str_repeat('b', 24).' Bearer synthetic-token'],
            ['state' => 'HEALTHY'],
            ['kind' => 'unknown-batch'],
            ['request_id' => 'req_'.str_repeat('c', 24).'/private'],
            ['job_id' => null],
        ] as $overrides) {
            try {
                DiagnosticReceipt::fromTrustedMetadata(array_replace($source, $overrides));
                self::fail('Unsafe source must fail closed.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('INVALID_DIAGNOSTIC_METADATA', $exception->getMessage());
                self::assertStringNotContainsString('Bearer', $exception->getMessage());
                self::assertStringNotContainsString('@example.test', $exception->getMessage());
            }
        }
        foreach (['UNKNOWN', 'STALE'] as $state) {
            $receipt = DiagnosticReceipt::fromTrustedMetadata(array_replace($source, ['state' => $state]));
            $this->assertReceiptKeys($receipt);
            self::assertSame('BLOCKED', $receipt['diagnostic_status']);
            self::assertFalse($receipt['operational_ready']);
        }
    }

    /** @param array<string, mixed> $receipt */
    private function assertReceiptKeys(array $receipt): void
    {
        self::assertEqualsCanonicalizing(
            ['schema_version', 'receipt_id', 'kind', 'state', 'diagnostic_status', 'operational_ready', 'correlation'],
            array_keys($receipt),
        );
        self::assertSame(
            [],
            array_diff(array_keys($receipt['correlation']), ['request_id', 'job_id', 'publication_id']),
            'Correlation must contain only opaque references from the allowlist.',
        );
        self::assertSame(1, $receipt['schema_version']);
    }

    /** @param list<string> $fixtures */
    private function assertSanitized(string $visible, array $fixtures): void
    {
        foreach ([...$fixtures, 'Authorization: Bearer', 'Stack trace', 'Traceback'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $visible);
        }
    }
}
