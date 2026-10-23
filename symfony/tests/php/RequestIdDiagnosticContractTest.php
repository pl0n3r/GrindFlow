<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Http\RequestIdSubscriber;
use GrindFlow\Observability\DiagnosticReceipt;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestIdDiagnosticContractTest extends TestCase
{
    public function testMainResponseIdAcceptedByDiagnosticReceipt(): void
    {
        $request = Request::create('/health');
        $response = new Response('ok');
        $this->applySubscriber($request, $response);

        $headerId = $response->headers->get('X-Request-Id');
        self::assertIsString($headerId);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/D', $headerId);
        self::assertSame($request->attributes->get('_grindflow_request_id'), $headerId);

        $receipt = DiagnosticReceipt::fromTrustedMetadata([
            'event_id' => 'evt_'.str_repeat('a', 24),
            'kind' => 'HTTP_500',
            'state' => 'FAILED',
            'request_id' => $headerId,
        ]);
        self::assertSame($headerId, $receipt['correlation']['request_id']);
        self::assertFalse($receipt['operational_ready']);
    }

    public function testUntrustedHeaderCannotOverrideGeneratedCorrelation(): void
    {
        $untrusted = 'req_'.str_repeat('f', 24);
        $request = Request::create('/', 'GET', server: ['HTTP_X_REQUEST_ID' => $untrusted]);
        $response = new Response('ok');
        $this->applySubscriber($request, $response);

        $generated = $response->headers->get('X-Request-Id');
        self::assertIsString($generated);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/D', $generated);
        self::assertNotSame($untrusted, $generated);
        self::assertSame($generated, $request->attributes->get('_grindflow_request_id'));

        // A subrequest may not re-mint or propagate its own correlation header.
        $subrequest = Request::create('/fragment', 'GET', server: ['HTTP_X_REQUEST_ID' => $untrusted]);
        $subresponse = new Response('fragment');
        $this->applySubscriber($subrequest, $subresponse, HttpKernelInterface::SUB_REQUEST);
        self::assertNull($subrequest->attributes->get('_grindflow_request_id'));
        self::assertFalse($subresponse->headers->has('X-Request-Id'));
    }

    public function testSynthetic500KeepsCorrelationAndOmitsSensitiveDiagnostics(): void
    {
        $syntheticSecret = 'synthetic-never-leak-token';
        $request = Request::create('/simulated-500', 'GET', server: [
            'HTTP_X_REQUEST_ID' => 'req_'.str_repeat('e', 24),
            'HTTP_AUTHORIZATION' => 'Bearer '.$syntheticSecret,
        ]);
        $response = new Response('Error interno', Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->applySubscriber($request, $response);

        $headerId = $response->headers->get('X-Request-Id');
        self::assertSame(500, $response->getStatusCode());
        self::assertIsString($headerId);
        self::assertSame($headerId, $request->attributes->get('_grindflow_request_id'));
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/D', $headerId);

        foreach (['UNKNOWN', 'STALE'] as $state) {
            $receipt = DiagnosticReceipt::fromTrustedMetadata([
                'event_id' => 'evt_'.str_repeat('b', 24),
                'kind' => 'HTTP_500',
                'state' => $state,
                'request_id' => $headerId,
            ]);
            self::assertSame($headerId, $receipt['correlation']['request_id']);
            self::assertSame('BLOCKED', $receipt['diagnostic_status']);
            self::assertFalse($receipt['operational_ready']);
            $visible = $response->getContent().json_encode($receipt, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($syntheticSecret, $visible);
            self::assertStringNotContainsString('Traceback', $visible);
            self::assertStringNotContainsString('Authorization', $visible);
            self::assertStringNotContainsString('@example.test', $visible);
        }
    }

    private function applySubscriber(Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber = new RequestIdSubscriber();
        $subscriber->begin(new RequestEvent($kernel, $request, $type));
        $subscriber->finish(new ResponseEvent($kernel, $request, $type, $response));
    }
}
