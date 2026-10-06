<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\VaultPhotoSafetyMaterializer;
use PHPUnit\Framework\TestCase;

final class VaultPhotoSafetyMaterializerTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR4nGP8z8Dwn4GBgYGJAQoAHxcCAk+Uzr4AAAAASUVORK5CYII=';

    public function testValidPrivatePngIsDecoderReencodedWithoutChangingOriginal(): void
    {
        $materializer = new VaultPhotoSafetyMaterializer();
        $this->requireDecoder($materializer);

        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);
        $source = $this->sourceFile($bytes);
        $originalHash = hash_file('sha256', $source);
        $safe = null;

        try {
            $safe = $materializer->materialize(
                $source,
                'image/png',
                strlen($bytes),
                hash('sha256', $bytes),
            );

            self::assertNotSame($source, $safe);
            self::assertFileExists($safe);
            self::assertSame($originalHash, hash_file('sha256', $source));
            $dimensions = getimagesize($safe);
            self::assertIsArray($dimensions);
            self::assertSame('image/png', $dimensions['mime']);
            self::assertSame(2, $dimensions[0]);
            self::assertSame(2, $dimensions[1]);
            $mode = fileperms($safe);
            self::assertIsInt($mode);
            self::assertSame(0, $mode & 0077);
        } finally {
            $materializer->cleanup($safe);
            @unlink($source);
        }

        self::assertFalse(is_string($safe) && is_file($safe));
    }

    public function testTrailingPayloadAndCorruptContentFailWithoutLeakingTemporaryCopy(): void
    {
        $materializer = new VaultPhotoSafetyMaterializer();
        $this->requireDecoder($materializer);

        $png = base64_decode(self::PNG, true);
        self::assertIsString($png);
        foreach ([$png.'trailing-payload', 'not-a-real-png'] as $bytes) {
            $source = $this->sourceFile($bytes);
            $before = $this->temporaryCopies();
            try {
                try {
                    $materializer->materialize(
                        $source,
                        'image/png',
                        strlen($bytes),
                        hash('sha256', $bytes),
                    );
                    self::fail('Untrusted image content must fail closed.');
                } catch (\RuntimeException) {
                    self::assertSame($before, $this->temporaryCopies());
                }
            } finally {
                @unlink($source);
            }
        }
    }

    public function testSameSizeContentDriftFailsAgainstTheDecodedBufferHash(): void
    {
        $materializer = new VaultPhotoSafetyMaterializer();
        $this->requireDecoder($materializer);

        $original = base64_decode(self::PNG, true);
        self::assertIsString($original);
        $source = $this->sourceFile($original);
        $expectedHash = hash('sha256', $original);

        $drifted = $original;
        $offset = max(8, intdiv(strlen($drifted), 2));
        $drifted[$offset] = chr(ord($drifted[$offset]) ^ 0x01);
        self::assertSame(strlen($original), strlen($drifted));
        self::assertSame(strlen($drifted), file_put_contents($source, $drifted));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Private image integrity changed.');
            $materializer->materialize(
                $source,
                'image/png',
                strlen($original),
                $expectedHash,
            );
        } finally {
            @unlink($source);
        }
    }

    public function testDeclaredMimeMismatchFailsClosed(): void
    {
        $materializer = new VaultPhotoSafetyMaterializer();
        $this->requireDecoder($materializer);
        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);
        $source = $this->sourceFile($bytes);
        try {
            $this->expectException(\RuntimeException::class);
            $materializer->materialize(
                $source,
                'image/jpeg',
                strlen($bytes),
                hash('sha256', $bytes),
            );
        } finally {
            @unlink($source);
        }
    }

    public function testPrivateVaultReadinessClassifiesPrivateFilesystemWithoutExposingPaths(): void
    {
        $materializer = new VaultPhotoSafetyMaterializer();
        self::assertSame('root_unavailable', $materializer->privateVaultReadinessState(''));

        $base = sys_get_temp_dir().'/gf-vault-readiness-'.bin2hex(random_bytes(6));
        $missing = $base.'/missing';
        self::assertTrue(mkdir($base, 0700));
        try {
            self::assertSame('missing', $materializer->privateVaultReadinessState($missing));

            $ready = $base.'/ready';
            self::assertTrue(mkdir($ready, 0700));
            self::assertSame('ready', $materializer->privateVaultReadinessState($ready));

            self::assertTrue(chmod($ready, 0755));
            self::assertSame(
                'permissions_not_private',
                $materializer->privateVaultReadinessState($ready),
            );
            self::assertFalse($materializer->runtimeReadiness($ready)['private_vault']);

            self::assertTrue(chmod($ready, 0700));
            self::assertTrue($materializer->runtimeReadiness($ready)['private_vault']);
        } finally {
            @chmod($base.'/ready', 0700);
            @rmdir($base.'/ready');
            @rmdir($base);
        }
    }

    private function requireDecoder(VaultPhotoSafetyMaterializer $materializer): void
    {
        if ($materializer->decoderAvailable()) {
            return;
        }
        if (getenv('CI') === 'true') {
            self::fail('GD decoder is required in CI.');
        }
        self::markTestSkipped('GD decoder is not available locally.');
    }

    private function sourceFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gf-source-');
        self::assertIsString($path);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));

        return $path;
    }

    /** @return list<string> */
    private function temporaryCopies(): array
    {
        $paths = glob(sys_get_temp_dir().'/gf-photo-*');
        if ($paths === false) {
            return [];
        }
        sort($paths);

        return array_values($paths);
    }
}
