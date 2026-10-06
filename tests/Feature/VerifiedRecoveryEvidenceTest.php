<?php

namespace Tests\Feature;

use App\Support\Operations\VerifiedRecoveryEvidence;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class VerifiedRecoveryEvidenceTest extends TestCase
{
    public function test_receipt_binds_ciphertext_database_vault_and_release(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $ciphertext = 'operations/recovery-backups/recovery.gfrec';
        $disk->put($ciphertext, 'authenticated-ciphertext');
        chmod($disk->path($ciphertext), 0600);

        $migration = str_repeat('a', 64);
        $vault = str_repeat('b', 64);
        $releaseSha = str_repeat('c', 40);
        $evidence = app(VerifiedRecoveryEvidence::class);

        $receipt = $evidence->record(
            $ciphertext,
            $migration,
            $vault,
            '0.1.215',
            $releaseSha,
            2,
            5,
        );

        $receiptPath = $disk->path("operations/recovery-backups/{$receipt}.receipt.json");
        clearstatcache(true, $receiptPath);
        $this->assertSame(0600, fileperms($receiptPath) & 0777);

        $payload = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('grindflow-recovery-v1', $payload['format']);
        $this->assertMatchesRegularExpression('/\\A[a-f0-9]{32}\\z/', $payload['nonce']);
        $this->assertSame(hash('sha256', 'authenticated-ciphertext'), $payload['ciphertext_sha256']);
        $this->assertSame($migration, $payload['migration_fingerprint']);
        $this->assertSame($vault, $payload['vault_index_sha256']);
        $this->assertSame('0.1.215', $payload['release_version']);
        $this->assertSame($releaseSha, $payload['release_sha']);
        $this->assertSame(2, $payload['organization_count']);
        $this->assertSame(5, $payload['asset_count']);

        $evidence->assertValid($receipt, $migration, $vault, '0.1.215', $releaseSha);
        $this->assertTrue(true);
    }

    public function test_receipt_fails_closed_for_tampering_mismatch_and_open_permissions(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $ciphertext = 'operations/recovery-backups/tamper.gfrec';
        $disk->put($ciphertext, 'ciphertext');
        chmod($disk->path($ciphertext), 0600);

        $migration = str_repeat('d', 64);
        $vault = str_repeat('e', 64);
        $releaseSha = str_repeat('f', 40);
        $evidence = app(VerifiedRecoveryEvidence::class);
        $receipt = $evidence->record(
            $ciphertext,
            $migration,
            $vault,
            '0.1.215',
            $releaseSha,
            1,
            1,
        );

        $this->assertFailure(
            fn () => $evidence->assertValid(
                $receipt,
                str_repeat('1', 64),
                $vault,
                '0.1.215',
                $releaseSha,
            ),
            'different recovery evidence',
        );

        $disk->put($ciphertext, 'tampered-ciphertext');
        chmod($disk->path($ciphertext), 0600);
        $this->assertFailure(
            fn () => $evidence->assertValid(
                $receipt,
                $migration,
                $vault,
                '0.1.215',
                $releaseSha,
            ),
            'checksum does not match',
        );

        $disk->put($ciphertext, 'ciphertext');
        chmod($disk->path($ciphertext), 0600);
        $receiptPath = "operations/recovery-backups/{$receipt}.receipt.json";
        $receiptPayload = json_decode((string) $disk->get($receiptPath), true, 512, JSON_THROW_ON_ERROR);
        $receiptPayload['asset_count'] = 99;
        $disk->put($receiptPath, json_encode($receiptPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($disk->path($receiptPath), 0600);
        $this->assertFailure(
            fn () => $evidence->assertValid(
                $receipt,
                $migration,
                $vault,
                '0.1.215',
                $releaseSha,
            ),
            'identity does not match payload',
        );

        $disk->put($receiptPath, json_encode([
            ...$receiptPayload,
            'asset_count' => 1,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($disk->path($receiptPath), 0600);

        $disk->put($ciphertext, 'ciphertext');
        chmod($disk->path($ciphertext), 0644);
        clearstatcache(true, $disk->path($ciphertext));
        $this->assertFailure(
            fn () => $evidence->assertValid(
                $receipt,
                $migration,
                $vault,
                '0.1.215',
                $releaseSha,
            ),
            'ciphertext is unavailable or unsafe',
        );
    }

    public function test_receipt_never_contains_keys_paths_or_vault_catalog(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $ciphertext = 'operations/recovery-backups/safe.gfrec';
        $disk->put($ciphertext, 'ciphertext');
        chmod($disk->path($ciphertext), 0600);

        $receipt = app(VerifiedRecoveryEvidence::class)->record(
            $ciphertext,
            str_repeat('1', 64),
            str_repeat('2', 64),
            '0.1.215',
            str_repeat('3', 40),
            1,
            3,
        );
        $raw = (string) $disk->get("operations/recovery-backups/{$receipt}.receipt.json");

        foreach ([
            'GF_RECOVERY_KEY_B64',
            'original_name',
            'private_note',
            'storage_key',
            '/home/',
            '/var/',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    private function assertFailure(callable $operation, string $expected): void
    {
        try {
            $operation();
            $this->fail("Expected RuntimeException containing {$expected}");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
        }
    }
}
