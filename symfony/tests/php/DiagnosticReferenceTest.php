<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use DateTimeImmutable;
use GrindFlow\Infrastructure\Scheduler\JobRunEvidence;
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
 * Issue #1047: one offline acceptance boundary for HTTP 500, diagnostic logs,
 * request-to-job/publication correlation, and fail-closed health states.
 * The TestHandler is NOT evidence about production or host-level logging.
 */
final class DiagnosticReferenceTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testRealKernel500ExposesOnlyStableServerReferenceAndSafeDiagnosticLog(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);

        self::assertSame('test', static::$kernel?->getEnvironment());
        self::assertFalse(static::$kernel?->isDebug());

        $token = 'synthetic-1047-token-not-real';
        $email = 'fictional-1047@example.test';
        $stack = '/private/path/synthetic-1047-exception.php';
        $spoofed = 'req_'.str_repeat('f', 24);
        $raised = false;
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(KernelEvents::CONTROLLER, static function (ControllerEvent $event) use (&$raised, $token, $email, $stack): void {
            if (!$event->isMainRequest() || $event->getRequest()->getPathInfo() !== '/health') {
                return;
            }
            $raised = true;
            throw new \RuntimeException('Authorization: Bearer '.$token.' '.$email.' '.$stack);
        }, 1024);

        $client->request('GET', '/health', server: [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_REQUEST_ID' => $spoofed,
        ]);
        self::assertTrue($raised, 'The controller exception must actually be raised.');
        self::assertResponseStatusCodeSame(500);

        $response = $client->getResponse();
        $requestId = $response->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/\Areq_[a-f0-9]{24}\z/D', $requestId);
        self::assertNotSame($spoofed, $requestId);
        self::assertSame($requestId, $client->getRequest()->attributes->get('_grindflow_request_id'));

        $receipt = DiagnosticReceipt::fromTrustedMetadata([
            'event_id' => 'evt_'.str_repeat('a', 24),
            'kind' => 'HTTP_500',
            'state' => 'FAILED',
            'request_id' => $requestId,
        ]);
        $handler = new TestHandler();
        (new Logger('synthetic-diagnostic-1047', [$handler]))->error('HTTP_500', ['receipt' => $receipt]);
        self::assertCount(1, $handler->getRecords());
        self::assertSame(['receipt' => $receipt], $handler->getRecords()[0]->context);
        self::assertSame(['request_id' => $requestId], $receipt['correlation']);
        self::assertSame('INVESTIGATE', $receipt['diagnostic_status']);
        self::assertFalse($receipt['operational_ready']);

        $observable = (string) $response->getContent()
            .json_encode($response->headers->all(), JSON_THROW_ON_ERROR)
            .json_encode($handler->getRecords()[0]->context, JSON_THROW_ON_ERROR);
        $this->assertNoSyntheticSecrets($observable, [$token, $email, $stack, $spoofed]);
    }

    public function testJobAndPublicationKeepTrustedRequestCorrelationWithoutLeakingPayload(): void
    {
        $client = static::createClient();
        $spoofed = 'req_'.str_repeat('e', 24);
        $token = 'synthetic-job-1047-token';
        $email = 'fixture-job-1047@example.test';
        $client->request('GET', '/health', server: [
            'HTTP_X_REQUEST_ID' => $spoofed,
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertResponseIsSuccessful();

        $requestId = $client->getResponse()->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/\Areq_[a-f0-9]{24}\z/D', $requestId);
        self::assertNotSame($spoofed, $requestId);
        self::assertSame($requestId, $client->getRequest()->attributes->get('_grindflow_request_id'));

        $jobId = 'job_'.str_repeat('b', 24);
        $publicationId = 'pub_'.str_repeat('c', 24);
        $job = DiagnosticReceipt::fromTrustedMetadata([
            'event_id' => 'evt_'.str_repeat('d', 24),
            'kind' => 'JOB_FAILURE',
            'state' => 'FAILED',
            'request_id' => $requestId,
            'job_id' => $jobId,
        ]);
        $publication = DiagnosticReceipt::fromTrustedMetadata([
            'event_id' => 'evt_'.str_repeat('e', 24),
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

        $jobs = new JobRunEvidence();
        $evidenceKey = bin2hex(random_bytes(16));
        $now = new DateTimeImmutable('now');
        self::assertTrue($jobs->begin('publication', $evidenceKey, 1, $now));
        $jobs->finish($evidenceKey, 1, 'failed', $now);
        self::assertCount(1, $jobs->records());
        self::assertSame('failed', $jobs->records()[0]['result']);

        $handler = new TestHandler();
        $logger = new Logger('synthetic-job-publication-1047', [$handler]);
        $logger->error('JOB_FAILURE', ['receipt' => $job]);
        $logger->error('PUBLICATION_FAILURE', ['receipt' => $publication]);
        self::assertCount(2, $handler->getRecords());
        self::assertSame(['receipt' => $job], $handler->getRecords()[0]->context);
        self::assertSame(['receipt' => $publication], $handler->getRecords()[1]->context);

        $observable = json_encode([$job, $publication, $jobs->records(),
            $handler->getRecords()[0]->context, $handler->getRecords()[1]->context], JSON_THROW_ON_ERROR);
        $this->assertNoSyntheticSecrets($observable, [$token, $email, $spoofed, $evidenceKey]);
    }

    public function testUnknownAndStaleRemainBlockedAndUnsafeMetadataNeverLogs(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('synthetic-unsafe-1047', [$handler]);
        $source = [
            'event_id' => 'evt_'.str_repeat('1', 24),
            'kind' => 'JOB_FAILURE',
            'state' => 'FAILED',
            'request_id' => 'req_'.str_repeat('2', 24),
            'job_id' => 'job_'.str_repeat('3', 24),
        ];
        foreach (['UNKNOWN', 'STALE'] as $state) {
            $receipt = DiagnosticReceipt::fromTrustedMetadata(array_replace($source, ['state' => $state]));
            self::assertSame('BLOCKED', $receipt['diagnostic_status']);
            self::assertFalse($receipt['operational_ready']);
            $logger->warning('DIAGNOSTIC_BLOCKED', ['receipt' => $receipt]);
        }
        foreach ([
            ['authorization' => 'Bearer synthetic-unsafe-token'],
            ['email' => 'fictional-leak@example.test'],
            ['state' => 'OK'],
            ['request_id' => 'req_spoofed'],
        ] as $unsafe) {
            try {
                DiagnosticReceipt::fromTrustedMetadata(array_replace($source, $unsafe));
                self::fail('Unsafe metadata must be rejected before logging.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('INVALID_DIAGNOSTIC_METADATA', $exception->getMessage());
            }
        }
        self::assertCount(2, $handler->getRecords());
        $observable = json_encode(array_map(
            static fn ($record): array => ['message' => $record->message, 'context' => $record->context],
            $handler->getRecords(),
        ), JSON_THROW_ON_ERROR);
        $this->assertNoSyntheticSecrets($observable, ['synthetic-unsafe-token', 'fictional-leak@example.test']);
    }

    /** @param list<string> $secrets */
    private function assertNoSyntheticSecrets(string $visible, array $secrets): void
    {
        foreach ([...$secrets, 'Authorization: Bearer', 'Stack trace', 'Traceback'] as $private) {
            self::assertStringNotContainsString($private, $visible);
        }
    }
}
