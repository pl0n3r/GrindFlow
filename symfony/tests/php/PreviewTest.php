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
        self::assertArrayNotHasKey('release_sha', $data);
        self::assertNotEmpty($client->getResponse()->headers->get('X-Request-Id'));
        self::assertNotEmpty($client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testPublicHomeAndPreviewRenderActualSymfonyTemplates(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Una carga.');
        $client->request('GET', '/preview');
        self::assertResponseIsSuccessful();
        $release = (new ProductVersion(dirname(__DIR__, 3)))->human();
        self::assertSelectorExists('#grindflow-preview[data-version="'.$release.'"]');
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
        self::assertSelectorExists('input[name="_csrf_token"]');
        $client->request('GET', '/organizations');
        self::assertResponseRedirects('/login');
        $client->request('GET', '/inexistente');
        self::assertResponseStatusCodeSame(404);
    }
}
