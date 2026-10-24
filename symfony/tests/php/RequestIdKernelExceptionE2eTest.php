<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * KernelBrowser exercises the real exception pipeline, not a handcrafted 500.
 * The listener and sensitive-looking values exist exclusively in APP_ENV=test.
 */
final class RequestIdKernelExceptionE2eTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testKernel500ReturnsCorrelatedServerGeneratedRequestId(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);

        $raised = false;
        $this->throwSyntheticControllerException('synthetic kernel failure', $raised);

        $untrusted = 'req_'.str_repeat('f', 24);
        $client->request('GET', '/health', server: [
            'HTTP_X_REQUEST_ID' => $untrusted,
            'HTTP_ACCEPT' => 'text/html',
        ]);

        self::assertTrue($raised, 'An actual controller event must throw.');
        self::assertResponseStatusCodeSame(500);

        $requestId = $client->getResponse()->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/D', $requestId);
        self::assertNotSame($untrusted, $requestId);
        self::assertSame(
            $requestId,
            $client->getRequest()->attributes->get('_grindflow_request_id'),
            'The request and the exception response must share the server ID.',
        );
    }

    public function testKernel500DoesNotExposeExceptionOrSyntheticSecrets(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);

        $syntheticToken = 'synthetic-not-a-real-token-500-guard';
        $syntheticEmail = 'fixture-person@example.test';
        $exceptionMarker = 'synthetic-private-exception-marker';
        $raised = false;
        $this->throwSyntheticControllerException(
            $exceptionMarker.' Authorization: Bearer '.$syntheticToken.' '.$syntheticEmail,
            $raised,
        );

        $client->request('GET', '/health', server: [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_AUTHORIZATION' => 'Bearer '.$syntheticToken,
            'HTTP_X_REQUEST_ID' => 'req_'.str_repeat('e', 24),
        ]);

        self::assertTrue($raised, 'The failure must originate in kernel.controller.');
        self::assertResponseStatusCodeSame(500);

        $response = $client->getResponse();
        $requestId = $response->headers->get('X-Request-Id');
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/D', $requestId);
        self::assertSame($requestId, $client->getRequest()->attributes->get('_grindflow_request_id'));
        self::assertNotSame('req_'.str_repeat('e', 24), $requestId);

        $visible = (string) $response->getContent()
            .json_encode($response->headers->all(), JSON_THROW_ON_ERROR);
        foreach ([$syntheticToken, $syntheticEmail, $exceptionMarker, 'Authorization: Bearer', 'Stack trace', 'Traceback'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $visible);
        }
    }

    private function throwSyntheticControllerException(string $message, bool &$raised): void
    {
        self::assertSame('test', static::$kernel?->getEnvironment());
        self::assertFalse(static::$kernel?->isDebug(), 'Debug error pages must be disabled for a redaction assertion.');

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(KernelEvents::CONTROLLER, static function (ControllerEvent $event) use ($message, &$raised): void {
            if (!$event->isMainRequest() || $event->getRequest()->getPathInfo() !== '/health') {
                return;
            }

            $raised = true;
            throw new \RuntimeException($message);
        }, 1024);
    }
}
