<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Anonymous admin API requests must fail with JSON before any tenant or database work.
 * Runs against the real Symfony kernel and route/controller wiring.
 */
final class AdminUnauthenticatedResponseTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAnonymousAdminRequestsReturnPrivateAuthenticationRequiredJson(): void
    {
        $client = static::createClient();

        foreach ([
            '/api/admin/context',
            '/api/admin/vault',
            '/api/admin/rules/weekly/preview',
            '/api/admin/vault?page%5B%5D=invalid',
        ] as $path) {
            $client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(401, $path);

            $response = $client->getResponse();
            self::assertStringContainsString(
                'application/json',
                (string) $response->headers->get('Content-Type'),
                $path,
            );

            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload, $path);
            self::assertSame(['error'], array_keys($payload), $path);
            self::assertSame('authentication_required', $payload['error']['code'] ?? null, $path);

            $cache = (string) $response->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cache, $path);
            self::assertStringContainsString('no-store', $cache, $path);
            self::assertStringNotContainsString('<html', strtolower((string) $response->getContent()), $path);
        }
    }
}
