<?php

namespace Tests\Unit;

use App\Support\Deployment\S4SmokeIdentityProvisioner;
use PHPUnit\Framework\TestCase;

class S4SmokeIdentityProvisionerTest extends TestCase
{
    private string $root;
    private string $console;
    private string $capture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/grindflow-s4-provisioner-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root.'/bin', 0700, true));
        $this->console = $this->root.'/bin/console';
        $this->capture = $this->root.'/capture.json';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_secret_is_available_only_through_child_environment_and_not_argv(): void
    {
        $capture = var_export($this->capture, true);
        $this->writeConsole(<<<PHP
<?php
$secret = getenv('GRINDFLOW_S4_SMOKE_PASSWORD') ?: '';
file_put_contents({$capture}, json_encode([
    'argv' => $argv,
    'secret_matches' => hash_equals('unit-ephemeral-secret', $secret),
], JSON_THROW_ON_ERROR));
echo json_encode(['status' => 'ok', 'code' => 'rotated'], JSON_THROW_ON_ERROR);
PHP);

        $result = $this->provisioner()->reconcile('unit-ephemeral-secret');

        self::assertSame(['ok' => true, 'code' => 'rotated'], $result);
        $capturePayload = json_decode(
            (string) file_get_contents($this->capture),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertTrue($capturePayload['secret_matches']);
        self::assertNotContains('unit-ephemeral-secret', $capturePayload['argv']);
        self::assertSame($this->console, $capturePayload['argv'][0] ?? null);
        self::assertContains('grindflow:s4:provision-smoke-identity', $capturePayload['argv']);
    }

    public function test_command_failures_are_reduced_to_allowlisted_codes(): void
    {
        $this->writeConsole(<<<'PHP'
<?php
echo json_encode(['status' => 'error', 'code' => 'identity_conflict'], JSON_THROW_ON_ERROR);
exit(1);
PHP);

        self::assertSame(
            ['ok' => false, 'code' => 's4-identity-conflict'],
            $this->provisioner()->reconcile('unit-ephemeral-secret'),
        );

        $this->writeConsole(<<<'PHP'
<?php
echo json_encode(['status' => 'error', 'code' => 'private-detail'], JSON_THROW_ON_ERROR);
exit(1);
PHP);

        self::assertSame(
            ['ok' => false, 'code' => 's4-output-invalid'],
            $this->provisioner()->reconcile('unit-ephemeral-secret'),
        );
    }

    public function test_child_output_that_reflects_secret_is_rejected_without_reflection(): void
    {
        $this->writeConsole(<<<'PHP'
<?php
echo getenv('GRINDFLOW_S4_SMOKE_PASSWORD');
PHP);

        $result = $this->provisioner()->reconcile('unit-ephemeral-secret');

        self::assertSame(['ok' => false, 'code' => 's4-output-invalid'], $result);
        self::assertStringNotContainsString(
            'unit-ephemeral-secret',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function test_missing_runtime_and_invalid_password_fail_closed(): void
    {
        self::assertSame(
            ['ok' => false, 'code' => 's4-runtime-unavailable'],
            $this->provisioner()->reconcile('unit-ephemeral-secret'),
        );

        self::assertSame(
            ['ok' => false, 'code' => 's4-password-invalid'],
            $this->provisioner()->reconcile("invalid\nsecret"),
        );
    }

    private function provisioner(): S4SmokeIdentityProvisioner
    {
        return new S4SmokeIdentityProvisioner($this->root, PHP_BINARY);
    }

    private function writeConsole(string $contents): void
    {
        file_put_contents($this->console, $contents);
        chmod($this->console, 0700);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path.'/'.$item;
            if (is_dir($target)) {
                $this->removeTree($target);
            } else {
                @unlink($target);
            }
        }

        @rmdir($path);
    }
}
