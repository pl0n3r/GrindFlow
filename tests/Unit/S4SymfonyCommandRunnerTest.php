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

    public function test_command_is_shell_free_and_secret_stays_in_child_environment(): void
    {
        $root = $this->fakeSymfonyRoot(
            <<<'PHP'
<?php
file_put_contents(dirname(__DIR__).'/argv.json', json_encode($argv, JSON_THROW_ON_ERROR));
file_put_contents(dirname(__DIR__).'/secret.txt', (string) getenv('RUNNER_TEST_SECRET'));
echo json_encode(['status' => 'ok', 'code' => 'ready'], JSON_THROW_ON_ERROR), PHP_EOL;
PHP,
        );
        $secret = 'runner-secret-'.Str::random(24);

        $result = (new S4SymfonyCommandRunner($root, PHP_BINARY))->run(
            'grindflow:s4:test-command',
            ['RUNNER_TEST_SECRET' => $secret],
            [$secret],
        );

        self::assertSame(
            ['ok' => true, 'successful' => true, 'status' => 'ok', 'code' => 'ready'],
            $result,
        );
        $argv = json_decode(
            (string) file_get_contents($root.'/argv.json'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            [
                $root.'/bin/console',
                'grindflow:s4:test-command',
                '--env=prod',
                '--no-interaction',
                '--no-ansi',
            ],
            $argv,
        );
        self::assertSame($secret, file_get_contents($root.'/secret.txt'));
        self::assertNotContains($secret, $argv, true);
    }

    public function test_valid_error_payload_remains_structured_for_the_caller(): void
    {
        $root = $this->fakeSymfonyRoot(
            <<<'PHP'
<?php
echo json_encode(['status' => 'error', 'code' => 'known_failure'], JSON_THROW_ON_ERROR), PHP_EOL;
exit(1);
PHP,
        );

        $result = (new S4SymfonyCommandRunner($root, PHP_BINARY))
            ->run('grindflow:s4:test-command');

        self::assertSame(
            [
                'ok' => true,
                'successful' => false,
                'status' => 'error',
                'code' => 'known_failure',
            ],
            $result,
        );
    }

    public function test_secret_reflection_and_missing_runtime_fail_closed(): void
    {
        $root = $this->fakeSymfonyRoot(
            <<<'PHP'
<?php
fwrite(STDERR, (string) getenv('RUNNER_TEST_SECRET'));
echo json_encode(['status' => 'ok', 'code' => 'ready'], JSON_THROW_ON_ERROR), PHP_EOL;
PHP,
        );
        $secret = 'runner-private-'.Str::random(24);

        $reflected = (new S4SymfonyCommandRunner($root, PHP_BINARY))->run(
            'grindflow:s4:test-command',
            ['RUNNER_TEST_SECRET' => $secret],
            [$secret],
        );
        self::assertSame(['ok' => false, 'code' => 'output_invalid'], $reflected);

        $missing = (new S4SymfonyCommandRunner($root, $root.'/missing-php'))
            ->run('grindflow:s4:test-command');
        self::assertSame(['ok' => false, 'code' => 'runtime_unavailable'], $missing);
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
