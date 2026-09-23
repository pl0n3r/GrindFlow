<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Kernel;
use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PreviewTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testHealthDoesNotInventDeploymentIdentity(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('s0-preview', $data['stage']);
        self::assertSame((new ProductVersion(dirname(__DIR__, 3)))->human(), $data['version']);
        self::assertSame(['compatible' => true, 'contract' => 'symfony-mariadb-v1'], $data['runtime']);
        self::assertArrayNotHasKey('release_sha', $data);
        self::assertArrayNotHasKey('php_version', $data);
        self::assertArrayNotHasKey('extensions', $data);
        self::assertArrayNotHasKey('sapi', $data);
        self::assertNotEmpty($client->getResponse()->headers->get('X-Request-Id'));
        self::assertNotEmpty($client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testPublicHomeAndPreviewRenderActualSymfonyTemplates(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Una carga.');
        self::assertSelectorExists('main#contenido[tabindex="-1"]');
        $client->request('GET', '/preview');
        self::assertResponseIsSuccessful();
        $release = (new ProductVersion(dirname(__DIR__, 3)))->human();
        self::assertSelectorExists('#grindflow-preview[data-version="'.$release.'"]');
        self::assertSelectorExists('main#contenido[tabindex="-1"]');
        self::assertSelectorExists('script[src^="/build/assets/preview-"]');
    }

    public function testAdminRequiresAuthenticationAndUnknownRoutesStayNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ingresa a tu espacio');
        self::assertSelectorExists('main#contenido[tabindex="-1"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
        $client->request('GET', '/organizations');
        self::assertResponseRedirects('/login');
        $client->request('GET', '/api/admin/context', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('authentication_required', $payload['error']['code']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $client->request('GET', '/inexistente');
        self::assertResponseStatusCodeSame(404);
    }

    public function testFramingIsDeniedOnPublicPrivateAndErrorResponses(): void
    {
        $client = static::createClient();
        foreach ([
            ['/', 200],
            ['/preview', 200],
            ['/login', 200],
            ['/admin', 302],
            ['/api/admin/context', 401],
            ['/missing-route', 404],
        ] as [$path, $status]) {
            $client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame($status);
            $headers = $client->getResponse()->headers;
            self::assertSame('DENY', $headers->get('X-Frame-Options'), $path);
            self::assertStringContainsString(
                "frame-ancestors 'none'",
                (string) $headers->get('Content-Security-Policy'),
                $path,
            );
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
        }
    }

    public function testHstsIsSentOnlyForSecureRequests(): void
    {
        $client = static::createClient();
        foreach ([
            ['/', 200],
            ['/admin', 302],
            ['/api/admin/context', 401],
            ['/missing-route', 404],
        ] as [$path, $status]) {
            $client->request('GET', $path, server: [
                'HTTPS' => 'on',
                'HTTP_ACCEPT' => 'application/json',
            ]);
            self::assertResponseStatusCodeSame($status);
            self::assertSame(
                'max-age=31536000',
                $client->getResponse()->headers->get('Strict-Transport-Security'),
                $path,
            );
        }

        $client->request('GET', '/', server: ['HTTPS' => 'off']);
        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Strict-Transport-Security'));

        // Reverse-proxy headers are untrusted by default; a client must not be
        // able to turn plain HTTP into a secure request by spoofing the proto.
        $client->request('GET', '/', server: [
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Strict-Transport-Security'));
    }

    public function testPrivateResponsesCannotBeCachedOnSuccessRedirectOrError(): void
    {
        $client = static::createClient();
        foreach ([
            ['/login', 200],
            ['/admin', 302],
            ['/organizations', 302],
            ['/api/admin/context', 401],
            ['/api/admin/missing', 404],
        ] as [$path, $status]) {
            $client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame($status);
            $cache = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('no-store', $cache, $path);
            self::assertStringContainsString('private', $cache, $path);
        }

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
