<?php

namespace Tests\Feature;

use App\Support\Deployment\CheckoutIdentity;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthIdentityTest extends TestCase
{
    public function test_checkout_identity_reads_loose_branch_reference(): void
    {
        $root = $this->temporaryRepository();
        $sha = str_repeat('a', 40);
        mkdir($root.'/.git/refs/heads', 0777, true);
        file_put_contents($root.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($root.'/.git/refs/heads/main', $sha."\n");

        self::assertSame($sha, (new CheckoutIdentity())->commit($root));

        $this->removeDirectory($root);
    }

    public function test_checkout_identity_reads_detached_and_packed_references(): void
    {
        $detached = $this->temporaryRepository();
        $detachedSha = str_repeat('b', 40);
        mkdir($detached.'/.git', 0777, true);
        file_put_contents($detached.'/.git/HEAD', $detachedSha."\n");
        self::assertSame($detachedSha, (new CheckoutIdentity())->commit($detached));
        $this->removeDirectory($detached);

        $packed = $this->temporaryRepository();
        $packedSha = str_repeat('c', 40);
        mkdir($packed.'/.git', 0777, true);
        file_put_contents($packed.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($packed.'/.git/packed-refs', $packedSha." refs/heads/main\n");
        self::assertSame($packedSha, (new CheckoutIdentity())->commit($packed));
        $this->removeDirectory($packed);
    }

    public function test_health_returns_exact_version_and_commit_without_database_access(): void
    {
        $sha = str_repeat('d', 40);
        $identity = $this->mock(CheckoutIdentity::class);
        $identity->shouldReceive('commit')->once()->andReturn($sha);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->get('/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('version', (string) config('version.number'))
            ->assertJsonPath('commit', $sha)
            ->assertJsonPath('exact', true);

        self::assertSame(0, $queries);
    }

    public function test_health_fails_closed_when_checkout_sha_cannot_be_proven(): void
    {
        $identity = $this->mock(CheckoutIdentity::class);
        $identity->shouldReceive('commit')->once()->andReturn(null);

        $this->get('/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'degraded',
                'version' => (string) config('version.number'),
                'commit' => null,
                'exact' => false,
            ]);
    }

    private function temporaryRepository(): string
    {
        $root = sys_get_temp_dir().'/grindflow-health-'.bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
