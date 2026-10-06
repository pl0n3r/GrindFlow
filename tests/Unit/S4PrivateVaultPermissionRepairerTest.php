<?php

namespace Tests\Unit;

use App\Support\Deployment\S4PrivateVaultPermissionRepairer;
use Illuminate\Support\Str;
use Tests\TestCase;

class S4PrivateVaultPermissionRepairerTest extends TestCase
{
    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if ($this->sandbox !== null) {
            $this->removeDirectory($this->sandbox);
        }

        parent::tearDown();
    }

    public function test_child_process_is_shell_free_and_returns_only_allowlisted_code(): void
    {
        $root = $this->fakeSymfonyRoot(
            <<<'PHP'
<?php
file_put_contents(dirname(__DIR__).'/argv.json', json_encode($argv, JSON_THROW_ON_ERROR));
echo json_encode(['status' => 'ok', 'code' => 'tightened'], JSON_THROW_ON_ERROR), PHP_EOL;
PHP,
        );

        $result = (new S4PrivateVaultPermissionRepairer($root, PHP_BINARY))->repair();

        self::assertSame(['ok' => true, 'code' => 'tightened'], $result);
        $argv = json_decode(
            (string) file_get_contents($root.'/argv.json'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($argv);
        self::assertContains('grindflow:s4:repair-private-vault-permissions', $argv, true);
        self::assertContains('--env=prod', $argv, true);
        self::assertContains('--no-interaction', $argv, true);
        self::assertCount(5, $argv);
        self::assertSame(
            [
                $root.'/bin/console',
                'grindflow:s4:repair-private-vault-permissions',
                '--env=prod',
                '--no-interaction',
                '--no-ansi',
            ],
            $argv,
        );
        self::assertStringNotContainsString('/vault', json_encode($argv, JSON_THROW_ON_ERROR));
    }

    public function test_failure_and_untrusted_output_fail_closed(): void
    {
        $root = $this->fakeSymfonyRoot(
            <<<'PHP'
<?php
fwrite(STDERR, '/private/path mode=0777');
echo json_encode(['status' => 'error', 'code' => 'permissions_repair_failed'], JSON_THROW_ON_ERROR), PHP_EOL;
exit(1);
PHP,
        );

        $result = (new S4PrivateVaultPermissionRepairer($root, PHP_BINARY))->repair();
        self::assertSame(
            ['ok' => false, 'code' => 'permissions_repair_failed'],
            $result,
        );
        self::assertStringNotContainsString('/private/path', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('0777', json_encode($result, JSON_THROW_ON_ERROR));

        file_put_contents(
            $root.'/bin/console',
            "<?php echo json_encode(['status'=>'ok','code'=>'not-allowlisted']);",
        );
        $invalid = (new S4PrivateVaultPermissionRepairer($root, PHP_BINARY))->repair();
        self::assertSame(['ok' => false, 'code' => 'output_invalid'], $invalid);
    }

    private function fakeSymfonyRoot(string $console): string
    {
        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grindflow-vault-repair-'.Str::uuid();
        $bin = $this->sandbox.DIRECTORY_SEPARATOR.'bin';
        self::assertTrue(mkdir($bin, 0700, true));
        self::assertNotFalse(file_put_contents($bin.DIRECTORY_SEPARATOR.'console', $console));

        return $this->sandbox;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $target = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($target) ? $this->removeDirectory($target) : @unlink($target);
        }

        @rmdir($path);
    }
}
