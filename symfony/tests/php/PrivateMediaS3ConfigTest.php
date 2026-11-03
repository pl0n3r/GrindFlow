<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\PrivateMediaS3Config;
use PHPUnit\Framework\TestCase;

final class PrivateMediaS3ConfigTest extends TestCase
{
    private string $directory;

    /** @var array<string,string> */
    private array $paths;

    protected function setUp(): void
    {
        $this->directory = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/gf-media-b2-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $entries = [
            'MEDIA_STORAGE_ENDPOINT_FILE' => 'https://s3.us-west-002.backblazeb2.com',
            'MEDIA_STORAGE_REGION_FILE' => 'us-west-002',
            'MEDIA_STORAGE_BUCKET_FILE' => 'private-media-001',
            'MEDIA_STORAGE_ACCESS_KEY_ID_FILE' => str_repeat('A', 16),
            'MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE' => str_repeat('S', 32),
        ];
        $this->paths = [];
        foreach ($entries as $name => $value) {
            $path = $this->directory.'/'.$name;
            file_put_contents($path, $value."\n");
            chmod($path, 0600);
            $this->paths[$name] = $path;
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testSymlinkedParentDirectoryFailsClosed(): void
    {
        // The final file is real and private, but an alias parent must never
        // allow an operator path to cross the configured private boundary.
        $alias = $this->directory.'-alias';
        if (!@symlink($this->directory, $alias)) {
            self::markTestSkipped('This filesystem does not permit symlinks.');
        }
        try {
            $paths = $this->paths;
            $paths['MEDIA_STORAGE_ENDPOINT_FILE'] = $alias.'/MEDIA_STORAGE_ENDPOINT_FILE';
            self::assertNull(PrivateMediaS3Config::fromPathVariables($paths));
        } finally {
            unlink($alias);
        }
    }

    public function testRejectsAttackerWritableAncestorWithPrivateChild(): void
    {
        // A 0700 immediate directory alone is insufficient when an ancestor
        // is renameable by other UIDs: symlink replacement can race fopen().
        $ancestor = $this->directory.'-mutable';
        $private = $ancestor.'/operator';
        $paths = [];
        try {
            self::assertTrue(mkdir($ancestor, 0700));
            self::assertTrue(mkdir($private, 0700));
            foreach ($this->paths as $key => $original) {
                $copy = $private.'/'.basename($original);
                self::assertTrue(copy($original, $copy));
                chmod($copy, 0600);
                $paths[$key] = $copy;
            }
            self::assertNotNull(PrivateMediaS3Config::fromPathVariables($paths));
            chmod($ancestor, 0777);
            self::assertNull(PrivateMediaS3Config::fromPathVariables($paths),
                'A writable ancestor must disable B2 before opening secrets.');
        } finally {
            if (is_dir($ancestor)) {
                chmod($ancestor, 0700);
            }
            if (is_dir($private)) {
                foreach (glob($private.'/*') ?: [] as $copy) {
                    if (is_file($copy)) {
                        unlink($copy);
                    }
                }
                rmdir($private);
            }
            if (is_dir($ancestor)) {
                rmdir($ancestor);
            }
        }
    }

    public function testSwappedAncestorSymlinkFailsClosed(): void
    {
        $outer = $this->directory.'-swap';
        $child = $outer.'/operator';
        $renamed = $outer.'/operator-original';
        $paths = [];
        try {
            self::assertTrue(mkdir($outer, 0700));
            self::assertTrue(mkdir($child, 0700));
            foreach ($this->paths as $key => $original) {
                $copy = $child.'/'.basename($original);
                self::assertTrue(copy($original, $copy));
                chmod($copy, 0600);
                $paths[$key] = $copy;
            }
            self::assertNotNull(PrivateMediaS3Config::fromPathVariables($paths));
            self::assertTrue(rename($child, $renamed));
            if (!@symlink($renamed, $child)) {
                self::markTestSkipped('Symlinks unavailable for adversarial ancestor test.');
            }
            self::assertNull(PrivateMediaS3Config::fromPathVariables($paths),
                'A replaced ancestor must fail closed, not follow the symlink.');
        } finally {
            if (is_link($child)) {
                unlink($child);
            }
            foreach ([$renamed, $child] as $directory) {
                if (is_dir($directory)) {
                    foreach (glob($directory.'/*') ?: [] as $copy) {
                        if (is_file($copy)) {
                            unlink($copy);
                        }
                    }
                    rmdir($directory);
                }
            }
            if (is_dir($outer)) {
                rmdir($outer);
            }
        }
    }

    public function testReadsOnlyPrivateB2Files(): void
    {
        $config = PrivateMediaS3Config::fromPathVariables($this->paths);
        self::assertNotNull($config);
        self::assertSame('us-west-002', $config->clientOptions()['region']);
        self::assertSame('private-media-001', $config->clientOptions()['bucket']);
    }

    public function testMissingFileFailsClosedWithoutAmbientProviderFallback(): void
    {
        $paths = $this->paths;
        unset($paths['MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE']);
        $paths['AWS_ACCESS_KEY_ID'] = 'fallback-is-forbidden';
        $paths['R2_SECRET_ACCESS_KEY'] = 'fallback-is-forbidden';
        self::assertNull(PrivateMediaS3Config::fromPathVariables($paths));
    }


    public function testRejectsGroupOrWorldAccessiblePrivateDirectory(): void
    {
        // Files remain mode 0600, but the enclosing secret directory must
        // also be private. Reject configuration even if its files are secure.
        chmod($this->directory, 0755);
        self::assertNull(PrivateMediaS3Config::fromPathVariables($this->paths));
        chmod($this->directory, 0770);
        self::assertNull(PrivateMediaS3Config::fromPathVariables($this->paths));
    }

    public function testWorldReadableSecretFailsClosed(): void
    {
        chmod($this->paths['MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE'], 0644);
        self::assertNull(PrivateMediaS3Config::fromPathVariables($this->paths));
    }

    public function testSymlinkedSecretFailsClosed(): void
    {
        $old = $this->paths['MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE'];
        $link = $this->directory.'/secret-link';
        self::assertTrue(symlink($old, $link));
        $paths = $this->paths;
        $paths['MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE'] = $link;
        self::assertNull(PrivateMediaS3Config::fromPathVariables($paths));
    }

    public function testEndpointSsrfAndRegionMismatchFailClosed(): void
    {
        foreach (['https://169.254.169.254', 'https://user:pw@s3.us-west-002.backblazeb2.com',
            'http://s3.us-west-002.backblazeb2.com', 'https://s3.eu-central-003.backblazeb2.com',
            'https://s3.us-west-002.backblazeb2.com:8443'] as $endpoint) {
            file_put_contents($this->paths['MEDIA_STORAGE_ENDPOINT_FILE'], $endpoint);
            self::assertNull(PrivateMediaS3Config::fromPathVariables($this->paths));
        }
    }

    public function testMultilineAndOversizedValueFailClosed(): void
    {
        $file = $this->paths['MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE'];
        file_put_contents($file, str_repeat('S', 32)."\nINJECTED");
        self::assertNull(PrivateMediaS3Config::fromPathVariables($this->paths));
        file_put_contents($file, str_repeat('S', 4098));
        self::assertNull(PrivateMediaS3Config::fromPathVariables($this->paths));
    }

    public function testDebugOutputDoesNotExposeSyntheticSecret(): void
    {
        $config = PrivateMediaS3Config::fromPathVariables($this->paths);
        self::assertNotNull($config);
        $dump = var_export($config->__debugInfo(), true);
        self::assertFalse(str_contains($dump, str_repeat('S', 32)));
        self::assertFalse(str_contains($dump, str_repeat('A', 16)));
    }

    public function testClonedAndDeserializedConfigurationDoesNotClaimReadiness(): void
    {
        $config = PrivateMediaS3Config::fromPathVariables($this->paths);
        self::assertNotNull($config);
        self::assertSame(true, $config->__debugInfo()['configured']);
        $clone = clone $config;
        $restored = unserialize(serialize($config), ['allowed_classes' => [PrivateMediaS3Config::class]]);
        self::assertSame(false, $clone->__debugInfo()['configured']);
        self::assertSame(false, $restored->__debugInfo()['configured']);
    }

    public function testObjectDumpAndSerializationCannotExposeSecrets(): void
    {
        $config = PrivateMediaS3Config::fromPathVariables($this->paths);
        self::assertNotNull($config);
        $secret = str_repeat('S', 32);
        $accessKey = str_repeat('A', 16);
        foreach ([var_export($config, true), serialize($config), print_r($config, true)] as $dump) {
            self::assertFalse(str_contains($dump, $secret));
            self::assertFalse(str_contains($dump, $accessKey));
            self::assertFalse(str_contains($dump, 'private-media-001'));
            self::assertFalse(str_contains($dump, 'backblazeb2.com'));
        }
        self::assertSame($secret, $config->clientOptions()['secretAccessKey']);
        $cloned = clone $config;
        try {
            $cloned->clientOptions();
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('Private media storage configuration is unavailable.', $error->getMessage());
        }
        $restored = unserialize(serialize($config), ['allowed_classes' => [PrivateMediaS3Config::class]]);
        self::assertTrue($restored instanceof PrivateMediaS3Config);
        try {
            $restored->clientOptions();
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('Private media storage configuration is unavailable.', $error->getMessage());
        }
    }

    public function testRejectsInvalidBucketAndCredentialFormat(): void
    {
        $bucketFile = $this->paths['MEDIA_STORAGE_BUCKET_FILE'];
        file_put_contents($bucketFile, 'https://private-media-001');
        self::assertNull(PrivateMediaS3Config::fromPathVariables($this->paths));
        file_put_contents($bucketFile, 'private-media-001');
        file_put_contents($this->paths['MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE'], 'bad secret with spaces');
        self::assertNull(PrivateMediaS3Config::fromPathVariables($this->paths));
    }

    public function testRelativeOrTraversalPathFailsClosed(): void
    {
        foreach (['relative/file', $this->directory.'/../escape', $this->directory.'/./secret',
            $this->directory.'//secret'] as $unsafe) {
            $paths = $this->paths;
            $paths['MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE'] = $unsafe;
            self::assertNull(PrivateMediaS3Config::fromPathVariables($paths));
        }
    }
}
