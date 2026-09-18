<?php

namespace Tests\Unit;

use App\Support\Deployment\ReleaseCacheGuard;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class ReleaseCacheGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/grindflow-release-'.Str::uuid();

        mkdir($this->root.'/bootstrap/cache', 0775, true);
        mkdir($this->root.'/config', 0775, true);
        mkdir($this->root.'/routes', 0775, true);
        mkdir($this->root.'/storage/framework/views', 0775, true);

        file_put_contents($this->root.'/bootstrap/app.php', '<?php return "bootstrap";');
        file_put_contents($this->root.'/composer.lock', '{}');
        file_put_contents($this->root.'/routes/console.php', '<?php');
        file_put_contents($this->root.'/routes/web.php', '<?php // v1');
        file_put_contents($this->root.'/config/app.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_it_clears_stale_laravel_caches_once_per_source_fingerprint(): void
    {
        file_put_contents($this->root.'/bootstrap/cache/routes-v7.php', '<?php // stale route cache');
        file_put_contents($this->root.'/bootstrap/cache/config.php', '<?php // stale config cache');
        file_put_contents($this->root.'/storage/framework/views/compiled.php', '<?php // stale view');

        $guard = new ReleaseCacheGuard(
            $this->root,
            $this->root.'/storage',
        );

        $this->assertTrue($guard->refreshIfNeeded());
        $this->assertFileDoesNotExist($this->root.'/bootstrap/cache/routes-v7.php');
        $this->assertFileDoesNotExist($this->root.'/bootstrap/cache/config.php');
        $this->assertFileDoesNotExist($this->root.'/storage/framework/views/compiled.php');
        $this->assertFileExists($this->root.'/storage/framework/grindflow-release.sha256');

        $this->assertFalse($guard->refreshIfNeeded());

        file_put_contents($this->root.'/bootstrap/cache/routes-v7.php', '<?php // stale again');
        file_put_contents($this->root.'/routes/web.php', '<?php // v2');

        $this->assertTrue($guard->refreshIfNeeded());
        $this->assertFileDoesNotExist($this->root.'/bootstrap/cache/routes-v7.php');
    }

    public function test_dashboard_defensively_checks_incremental_routes_before_linking(): void
    {
        $dashboard = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/dashboard.blade.php',
        );

        $this->assertIsString($dashboard);
        $this->assertStringContainsString(
            "Route::has('organizations.vault.index')",
            $dashboard,
        );
        $this->assertStringContainsString(
            "Route::has('admin.system')",
            $dashboard,
        );
        $this->assertStringContainsString(
            "Route::has('admin.diagnostics')",
            $dashboard,
        );
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
