<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Http\Controller\HealthController;
use GrindFlow\Infrastructure\Runtime\RuntimeReadiness;
use GrindFlow\Shared\Version\ProductVersion;
use PHPUnit\Framework\TestCase;

final class ReleaseShaHealthTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/grindflow-release-sha-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/config', 0700, true);
        file_put_contents($this->root.'/config/version.php', "<?php return ['number' => '9.8.7'];\n");
    }

    protected function tearDown(): void
    {
        $marker = $this->root.'/.release-sha';
        if (is_link($marker) || is_file($marker)) {
            unlink($marker);
        } elseif (is_dir($marker)) {
            rmdir($marker);
        }
        unlink($this->root.'/config/version.php');
        rmdir($this->root.'/config');
        rmdir($this->root);
    }

    public function test_criterio_principal(): void
    {
        $version = new ProductVersion($this->root);
        $controller = new HealthController();
        $runtime = new RuntimeReadiness();

        $missing = $controller($version, $runtime);
        $payload = json_decode((string) $missing->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('release_sha', $payload);
        self::assertNull($payload['release_sha']);
        self::assertSame('9.8.7', $payload['version']);
        self::assertSame($runtime->publicSummary()['compatible'] ? 200 : 503, $missing->getStatusCode());
        self::assertSame('nosniff', $missing->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $missing->headers->get('Cache-Control'));

        file_put_contents($this->root.'/.release-sha', str_repeat('AB12', 10)."\r\n");
        $present = $controller($version, $runtime);
        $payload = json_decode((string) $present->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('ab12', 10), $payload['release_sha']);
        self::assertSame('9.8.7', $payload['version']);
        self::assertSame(str_repeat('AB12', 10)."\r\n", file_get_contents($this->root.'/.release-sha'));
    }

    public function testRejectsInvalidMarkers(): void
    {
        $version = new ProductVersion($this->root);
        foreach ([
            '', str_repeat('a', 39), str_repeat('a', 41), str_repeat('a', 40).'x',
            str_repeat('a', 40)."\r", ' '.str_repeat('a', 40),
            "\xEF\xBB\xBF".str_repeat('a', 40), "\xE2\x80\x8B".str_repeat('a', 40),
            str_repeat('a', 40)."\n\n", str_repeat('a', 40)."\0",
            str_repeat('a', 1000),
        ] as $invalid) {
            file_put_contents($this->root.'/.release-sha', $invalid);
            self::assertNull($version->releaseSha(), bin2hex(substr($invalid, 0, 10)));
        }
    }

    public function testSymlinksDirectoriesAndNonexistentMarkerFailClosed(): void
    {
        $marker = $this->root.'/.release-sha';
        $version = new ProductVersion($this->root);
        self::assertNull($version->releaseSha());
        mkdir($marker);
        self::assertNull($version->releaseSha());
        rmdir($marker);
        file_put_contents($this->root.'/target', str_repeat('a', 40));
        if (function_exists('symlink')) {
            symlink($this->root.'/target', $marker);
            self::assertNull($version->releaseSha());
            unlink($marker);
        }
        unlink($this->root.'/target');
    }

    public function testAtomicReplacementDoesNotCacheOrRewriteMarker(): void
    {
        $marker = $this->root.'/.release-sha';
        $version = new ProductVersion($this->root);
        $controller = new HealthController();
        $runtime = new RuntimeReadiness();

        file_put_contents($marker, str_repeat('a', 40));
        $first = json_decode((string) $controller($version, $runtime)->getContent(), true);
        self::assertSame(str_repeat('a', 40), $first['release_sha']);

        file_put_contents($this->root.'/next', str_repeat('B', 40)."\n");
        rename($this->root.'/next', $marker);
        $second = json_decode((string) $controller($version, $runtime)->getContent(), true);
        self::assertSame(str_repeat('b', 40), $second['release_sha']);
        self::assertSame(str_repeat('B', 40)."\n", file_get_contents($marker));

        file_put_contents($marker, 'invalid');
        $third = json_decode((string) $controller($version, $runtime)->getContent(), true);
        self::assertNull($third['release_sha']);
        unlink($marker);
        self::assertNull($version->releaseSha());
    }
}
