<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Shared\Version\ProductVersion;
use PHPUnit\Framework\TestCase;

final class ReleaseShaHealthTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gf-release-sha-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    public function testReturnsNullWithoutReleaseFile(): void
    {
        self::assertNull((new ProductVersion($this->dir))->releaseSha());
    }

    public function testReturnsValidSha(): void
    {
        $sha = str_repeat('ab12', 10);
        file_put_contents($this->dir.'/.release-sha', $sha."\n");

        self::assertSame($sha, (new ProductVersion($this->dir))->releaseSha());
    }

    public function testRejectsInvalidSha(): void
    {
        file_put_contents($this->dir.'/.release-sha', 'not-a-sha');

        self::assertNull((new ProductVersion($this->dir))->releaseSha());
    }

    public function testRejectsSymlink(): void
    {
        $target = $this->dir.'/real';
        file_put_contents($target, str_repeat('ab12', 10));
        symlink($target, $this->dir.'/.release-sha');

        self::assertNull((new ProductVersion($this->dir))->releaseSha());
    }
}
