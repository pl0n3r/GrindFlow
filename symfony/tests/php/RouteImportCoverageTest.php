<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use FilesystemIterator;
use GrindFlow\Kernel;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards against attributed controllers silently falling outside route imports.
 */
final class RouteImportCoverageTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testEveryAttributedControllerIsCoveredByAnAttributeImport(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Yaml::parseFile($root.'/config/routes.yaml');
        self::assertIsArray($config);

        $importedDirectories = [];
        foreach ($config as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? null) !== 'attribute') {
                continue;
            }
            $relativePath = $entry['resource']['path'] ?? null;
            if (!is_string($relativePath)) {
                continue;
            }
            $directory = realpath($root.'/config/'.$relativePath);
            self::assertNotFalse($directory, 'Attribute route import must resolve: '.$relativePath);
            self::assertDirectoryExists($directory);
            $importedDirectories[] = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        }
        self::assertNotEmpty($importedDirectories, 'At least one attribute route directory must be imported.');

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS),
        );
        $attributedControllers = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (preg_match('/#\[\s*Route\s*\(/', $source) !== 1) {
                continue;
            }
            ++$attributedControllers;
            $path = $file->getRealPath();
            self::assertIsString($path);
            $covered = false;
            foreach ($importedDirectories as $importedDirectory) {
                if (str_starts_with($path, $importedDirectory)) {
                    $covered = true;
                    break;
                }
            }
            self::assertTrue($covered, 'Route attributes outside configured imports: '.$path);
        }
        self::assertGreaterThan(0, $attributedControllers, 'No route attributes were inspected.');
    }

    public function testCrossModuleReportRouteExistsAndRejectsAnonymousRequests(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');
        $route = $router->getRouteCollection()->get('grindflow_cross_module_report');
        self::assertNotNull($route, 'Cross-module report must be imported into the router.');
        self::assertSame('/api/admin/reports/cross-module', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());

        $client->request('GET', '/api/admin/reports/cross-module');
        self::assertResponseStatusCodeSame(401);
        $response = $client->getResponse();
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('authentication_required', $payload['error']['code']);
    }
}
