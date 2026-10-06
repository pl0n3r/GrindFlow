<?php

namespace Tests\Unit;

use App\Support\Deployment\S4SmokeIdentityProvisioner;
use Illuminate\Support\Str;
use Tests\TestCase;

class S4SmokeIdentityProvisionerTest extends TestCase
{
    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if ($this->sandbox !== null) {
            $this->removeDirectory($this->sandbox);
        }

        parent::tearDown();
    }

    public function test_secret_travels_only_in_child_environment_and_public_result_is_allowlisted(): void
    {
        $root = $this->fakeSymfonyRoot(
            <<<'PHP'
<?php
file_put_contents(dirname(__DIR__).'/argv.json', json_encode($argv, JSON_THROW_ON_ERROR));
file_put_contents(dirname(__DIR__).'/secret.txt', (string) getenv('GRINDFLOW_S4_SMOKE_PASSWORD'));
echo json_encode(['status' => 'ok', 'code' => 'rotated'], JSON_THROW_ON_ERROR), PHP_EOL;
PHP,
        );
        $secret = 's4-ephemeral-secret-'.Str::random(24);

        $result = (new S4SmokeIdentityProvisioner($root, PHP_BINARY))
            ->reconcile($secret);

        self::assertSame(['ok' => true, 'code' => 'rotated'], $result);

        $argv = json_decode(
            (string) file_get_contents($root.'/argv.json'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($argv);
        self::assertNotContains($secret, $argv, true);
        self::assertContains('grindflow:s4:provision-smoke-identity', $argv, true);
        self::assertContains('--env=prod', $argv, true);
        self::assertContains('--no-interaction', $argv, true);
        self::assertSame($secret, file_get_contents($root.'/secret.txt'));
        self::assertStringNotContainsString($secret, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_untrusted_child_output_fails_closed_without_reflecting_secret(): void
    {
        $root = $this->fakeSymfonyRoot(
            <<<'PHP'
<?php
echo (string) getenv('GRINDFLOW_S4_SMOKE_PASSWORD');
PHP,
        );
        $secret = 's4-private-secret-'.Str::random(24);

        $result = (new S4SmokeIdentityProvisioner($root, PHP_BINARY))
            ->reconcile($secret);

        self::assertSame(['ok' => false, 'code' => 's4-output-invalid'], $result);
        self::assertStringNotContainsString($secret, json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function fakeSymfonyRoot(string $console): string
    {
        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grindflow-s4-'.Str::uuid();
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
