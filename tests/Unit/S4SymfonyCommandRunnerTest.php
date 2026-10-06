<?php

namespace Tests\Unit;

use App\Support\Deployment\S4SymfonyCommandRunner;
use Illuminate\Support\Str;
use Tests\TestCase;

class S4SymfonyCommandRunnerTest extends TestCase
{
    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if ($this->sandbox !== null) {
            $this->removeDirectory($this->sandbox);
        }

        parent::tearDown();
    }

    public function test_runner_uses_governed_cli_without_shell_and_returns_strict_json_envelope(): void
    {
        $root = $this->fakeSymfonyRoot(
            <<<'PHP'
<?php
file_put_contents(dirname(__DIR__).'/argv.json', json_encode($argv, JSON_THROW_ON_ERROR));
echo json_encode(['status' => 'ok', 'code' => 'ready'], JSON_THROW_ON_ERROR), PHP_EOL;
PHP,
        );
        config(['grindflow.s4_smoke.php_cli_binary' => PHP_BINARY]);

        $result = (new S4SymfonyCommandRunner($root))
            ->run('grindflow:s4:test-runner');

        self::assertSame([
            'ok' => true,
            'successful' => true,
            'status' => 'ok',
            'code' => 'ready',
        ], $result);

        $argv = json_decode(
            (string) file_get_contents($root.'/argv.json'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame([
            $root.'/bin/console',
            'grindflow:s4:test-runner',
            '--env=prod',
            '--no-interaction',
            '--no-ansi',
        ], $argv);
    }

    public function test_runner_fails_closed_on_missing_runtime_invalid_output_or_secret_echo(): void
    {
        $root = $this->fakeSymfonyRoot("<?php echo 'not-json';");
        $runner = new S4SymfonyCommandRunner($root, $root.'/missing-php');
        self::assertSame(
            ['ok' => false, 'code' => 'runtime_unavailable'],
            $runner->run('grindflow:s4:test-runner'),
        );

        $invalid = new S4SymfonyCommandRunner($root, PHP_BINARY);
        self::assertSame(
            ['ok' => false, 'code' => 'output_invalid'],
            $invalid->run('grindflow:s4:test-runner'),
        );

        file_put_contents(
            $root.'/bin/console',
            "<?php fwrite(STDERR, (string) getenv('RUNNER_SECRET')); echo json_encode(['status'=>'ok','code'=>'ready']);",
        );
        $secret = 'runner-secret-'.Str::random(24);
        self::assertSame(
            ['ok' => false, 'code' => 'output_invalid'],
            $invalid->run(
                'grindflow:s4:test-runner',
                ['RUNNER_SECRET' => $secret],
                [$secret],
            ),
        );
    }

    private function fakeSymfonyRoot(string $console): string
    {
        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grindflow-s4-runner-'.Str::uuid();
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
