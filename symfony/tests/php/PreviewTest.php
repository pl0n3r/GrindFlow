<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Kernel;
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
        self::assertSame('0.1.24', $data['version']);
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
        self::assertSelectorExists('#grindflow-preview[data-version="0.1.24"]');
        self::assertSelectorExists('script[src^="/build/assets/preview-"]');
    }

    public function testAdminIsNotPublicWithoutIdentitySlice(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/inexistente');
        self::assertResponseStatusCodeSame(404);
    }
}
