<?php

namespace Tests\Unit;

use App\Support\Deployment\ProductionEnvironmentWriter;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionEnvironmentWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_it_backs_up_then_atomically_persists_the_smoke_password(): void
    {
        $path = $this->temporaryEnvironment("APP_ENV=production\nAPP_KEY=base64:test\n");

        (new ProductionEnvironmentWriter)->withSmokePassword(
            'secret-$-with-"quotes"',
            static fn (): null => null,
            $path,
        );

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('SMOKE_USER_PASSWORD="secret-\\$-with-\\"quotes\\""', $contents);
        self::assertStringContainsString('CACHE_STORE="file"', $contents);
        self::assertStringContainsString('SMOKE_USER_EMAIL="'+email+'"', $contents);

        $backups = Storage::disk('local')->allFiles('operations/environment-backups');
        self::assertCount(1, $backups);
        self::assertSame(
            "APP_ENV=production\nAPP_KEY=base64:test\n",
            Crypt::decryptString((string) Storage::disk('local')->get($backups[0])),
        );

        @unlink($path);
    }

    public function test_it_safely_replaces_existing_password_with_regex_metacharacters(): void
    {
        $path = $this->temporaryEnvironment("APP_ENV=production\nSMOKE_USER_PASSWORD=\"old\"\n");
        $password = 'a\\b$1"c\\';

        (new ProductionEnvironmentWriter)->withSmokePassword(
            $password,
            static fn (): null => null,
            $path,
        );

        $parsed = Dotenv::parse((string) file_get_contents($path));
        self::assertSame($password, $parsed['SMOKE_USER_PASSWORD']);

        @unlink($path);
    }

    public function test_it_preserves_an_existing_database_cache_store(): void
    {
        $original = "APP_ENV=production\nCACHE_STORE=\"database\"\nSMOKE_USER_PASSWORD=\"old\"\n";
        $path = $this->temporaryEnvironment($original);

        (new ProductionEnvironmentWriter)->withSmokePassword(
            'replacement',
            static fn (): null => null,
            $path,
        );

        $contents = (string) file_get_contents($path);
        $parsed = Dotenv::parse($contents);
        self::assertSame('database', $parsed['CACHE_STORE']);
        self::assertSame('replacement', $parsed['SMOKE_USER_PASSWORD']);
        self::assertSame(1, substr_count($contents, 'CACHE_STORE='));
        self::assertSame(
            $original,
            Crypt::decryptString((string) Storage::disk('local')->get(
                Storage::disk('local')->allFiles('operations/environment-backups')[0],
            )),
        );

        @unlink($path);
    }

    public function test_it_upgrades_array_cache_store_to_persistent_file_cache(): void
    {
        $path = $this->temporaryEnvironment("CACHE_STORE=\"array\"\nSMOKE_USER_PASSWORD=\"old\"\n");

        (new ProductionEnvironmentWriter)->withSmokePassword(
            'replacement',
            static fn (): null => null,
            $path,
        );

        $parsed = Dotenv::parse((string) file_get_contents($path));
        self::assertSame('file', $parsed['CACHE_STORE']);
        self::assertSame('replacement', $parsed['SMOKE_USER_PASSWORD']);

        @unlink($path);
    }

    public function test_it_migrates_only_the_legacy_smoke_email_with_private_backup_and_idempotency(): void
    {
        $original = "CACHE_STORE=\"file\"\nSMOKE_USER_PASSWORD=\"same\"\nSMOKE_USER_EMAIL=\"e2e-admin@grindflow.test\"\n";
        $path = $this->temporaryEnvironment($original);
        $writer = new ProductionEnvironmentWriter;

        $writer->withSmokePassword('same', static fn (): null => null, $path);
        $parsed = Dotenv::parse((string) file_get_contents($path));
        self::assertSame(ProductionEnvironmentWriter::DEDICATED_SMOKE_EMAIL, $parsed['SMOKE_USER_EMAIL']);
        self::assertSame('same', $parsed['SMOKE_USER_PASSWORD']);
        $backups = Storage::disk('local')->allFiles('operations/environment-backups');
        self::assertCount(1, $backups);
        self::assertSame($original, Crypt::decryptString((string) Storage::disk('local')->get($backups[0])));

        $writer->withSmokePassword('same', static fn (): null => null, $path);
        self::assertSame($backups, Storage::disk('local')->allFiles('operations/environment-backups'));

        @unlink($path);
    }

    public function test_it_rolls_environment_back_when_reconciliation_fails(): void
    {
        $original = "APP_ENV=production\nCACHE_STORE=\"array\"\nSMOKE_USER_PASSWORD=\"old\"\nSMOKE_USER_EMAIL=\"e2e-admin@grindflow.test\"\n";
        $path = $this->temporaryEnvironment($original);

        try {
            (new ProductionEnvironmentWriter)->withSmokePassword(
                'replacement',
                static function (): void {
                    throw new RuntimeException('synthetic failure');
                },
                $path,
            );
            self::fail('Expected reconciliation failure was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic failure', $exception->getMessage());
        }

        self::assertSame($original, file_get_contents($path));

        @unlink($path);
    }

    public function test_it_does_not_create_environment_backup_when_value_is_already_current(): void
    {
        $path = $this->temporaryEnvironment("CACHE_STORE=\"file\"\nSMOKE_USER_PASSWORD=\"same\"\nSMOKE_USER_EMAIL=\"e2e-oidc-smoke@grindflow.test\"\n");
        $called = false;

        (new ProductionEnvironmentWriter)->withSmokePassword(
            'same',
            static function () use (&$called): void {
                $called = true;
            },
            $path,
        );

        self::assertTrue($called);
        self::assertSame([], Storage::disk('local')->allFiles('operations/environment-backups'));

        @unlink($path);
    }

    #[DataProvider('invalidPasswords')]
    public function test_it_rejects_invalid_password_formats_without_side_effects(string $password): void
    {
        $original = "APP_ENV=production\nCACHE_STORE=\"file\"\n";
        $path = $this->temporaryEnvironment($original);
        $called = false;

        try {
            (new ProductionEnvironmentWriter)->withSmokePassword(
                $password,
                static function () use (&$called): void {
                    $called = true;
                },
                $path,
            );
            self::fail('Expected invalid password rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic smoke password format is invalid.', $exception->getMessage());
        }

        self::assertFalse($called);
        self::assertSame($original, file_get_contents($path));
        self::assertSame([], Storage::disk('local')->allFiles('operations/environment-backups'));

        @unlink($path);
    }

    public static function invalidPasswords(): array
    {
        return [
            'empty' => [''],
            'newline' => ["ok\nAPP_DEBUG=true"],
            'carriage return' => ["ok\rX=1"],
            'null byte' => ["ok\0"],
            'too long' => [str_repeat('a', 4097)],
        ];
    }

    private function temporaryEnvironment(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grindflow-env-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);
        chmod($path, 0600);

        return $path;
    }
}
