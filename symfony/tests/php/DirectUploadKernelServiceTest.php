<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\BackblazeB2DirectUploadStorage;
use GrindFlow\Infrastructure\Storage\DirectUploadStorage;
use GrindFlow\Infrastructure\Storage\UnavailableDirectUploadStorage;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real Symfony container service-ID regression, with no provider I/O.
 * Each case installs synthetic *_FILE pointers before kernel boot and restores
 * process variables after shutdown, preventing shared-container contamination.
 */
final class DirectUploadKernelServiceTest extends KernelTestCase
{
    /** @var list<string> */
    private const POINTERS = [
        'MEDIA_STORAGE_ENDPOINT_FILE',
        'MEDIA_STORAGE_REGION_FILE',
        'MEDIA_STORAGE_BUCKET_FILE',
        'MEDIA_STORAGE_ACCESS_KEY_ID_FILE',
        'MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE',
    ];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testExactStorageServiceIdFailsClosedWithoutFiveFiles(): void
    {
        $paths = [];
        foreach (self::POINTERS as $pointer) {
            $paths[$pointer] = '/nonexistent-gf839-'.$pointer;
        }
        $this->withBootIsolatedPointers($paths, static function (DirectUploadStorage $storage): void {
            self::assertInstanceOf(UnavailableDirectUploadStorage::class, $storage);
            self::assertFalse($storage->available());
        });
    }

    public function testExactStorageServiceIdUsesB2WithFivePrivateFiles(): void
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
            self::markTestSkipped('AWS S3 SDK is not installed in this test environment.');
        }

        $directory = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/gf839-kernel-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $fixture = [
            'MEDIA_STORAGE_ENDPOINT_FILE' => 'https://s3.us-west-002.backblazeb2.com',
            'MEDIA_STORAGE_REGION_FILE' => 'us-west-002',
            'MEDIA_STORAGE_BUCKET_FILE' => 'private-media-001',
            'MEDIA_STORAGE_ACCESS_KEY_ID_FILE' => str_repeat('A', 16),
            'MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE' => str_repeat('S', 32),
        ];
        $paths = [];
        try {
            foreach ($fixture as $name => $value) {
                $path = $directory.'/'.$name;
                self::assertNotFalse(file_put_contents($path, $value."\n"));
                chmod($path, 0600);
                $paths[$name] = $path;
            }
            $this->withBootIsolatedPointers($paths, static function (DirectUploadStorage $storage): void {
                self::assertInstanceOf(BackblazeB2DirectUploadStorage::class, $storage);
                self::assertTrue($storage->available(), 'A configured SDK adapter must be available offline.');
            });
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
            rmdir($directory);
        }
    }

    /**
     * @param array<string,string> $paths
     * @param callable(DirectUploadStorage):void $assertStorage
     */
    private function withBootIsolatedPointers(array $paths, callable $assertStorage): void
    {
        $original = [];
        foreach (self::POINTERS as $name) {
            $original[$name] = [
                getenv($name),
                array_key_exists($name, $_ENV), $_ENV[$name] ?? null,
                array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null,
            ];
            $value = $paths[$name];
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        try {
            static::bootKernel(['environment' => 'test', 'debug' => false]);
            $container = static::getContainer();
            self::assertTrue($container->has(DirectUploadStorage::class));
            $storage = $container->get(DirectUploadStorage::class);
            self::assertInstanceOf(DirectUploadStorage::class, $storage);
            $assertStorage($storage);
        } finally {
            static::ensureKernelShutdown();
            foreach ($original as $name => [$environment, $hadEnv, $env, $hadServer, $server]) {
                $environment === false ? putenv($name) : putenv($name.'='.$environment);
                if ($hadEnv) {
                    $_ENV[$name] = $env;
                } else {
                    unset($_ENV[$name]);
                }
                if ($hadServer) {
                    $_SERVER[$name] = $server;
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }
}
