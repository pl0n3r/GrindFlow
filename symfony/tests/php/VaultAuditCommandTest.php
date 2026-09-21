<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Infrastructure\Storage\PrivateVaultDirectory;
use GrindFlow\Infrastructure\Storage\VaultAuditCommand;
use GrindFlow\Infrastructure\Storage\VaultStageCommand;
use GrindFlow\Infrastructure\Storage\VaultVerifyStageCommand;
use GrindFlow\Infrastructure\Storage\VaultVerifyRestoreCommand;
use GrindFlow\Infrastructure\Storage\VaultBlobVerifier;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/** Synthetic catalog and private bytes on the disposable Symfony MariaDB only. */
final class VaultAuditCommandTest extends KernelTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAuditReportsTenantScopedIntegrityAndRejectsIncompleteRestore(): void
    {
        self::bootKernel();
        // The operator must be able to find the command in the real Symfony container.
        self::assertSame('grindflow:vault:audit', (new Application(static::$kernel))->find('grindflow:vault:audit')->getName());
        self::assertSame('grindflow:vault:verify-restore', (new Application(static::$kernel))->find('grindflow:vault:verify-restore')->getName());
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $base = sys_get_temp_dir().'/gf-recovery-'.bin2hex(random_bytes(6));
        $project = $base.'/checkout/symfony';
        self::assertTrue(mkdir($project, 0700, true));
        $storage = new PrivateVaultDirectory($project, $base.'/vault');
        $root = $storage->ensureWritable();
        $tester = new CommandTester(new VaultAuditCommand($db, new VaultBlobVerifier($storage)));

        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $empty = Uuid::v7()->toRfc4122();
        $first = Uuid::v7()->toRfc4122();
        $second = Uuid::v7()->toRfc4122();
        $other = Uuid::v7()->toRfc4122();
        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);
        $at = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Synthetic recovery operator',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('disposable-only', PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        try {
            foreach ([$mine, $foreign, $empty] as $organization) {
                $db->insert('gf_identity_organizations', [
                    'id' => $organization, 'name' => 'Synthetic org',
                    'slug' => 'recovery-'.substr($organization, 0, 30),
                    'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
                ]);
            }
            foreach ([$first => $mine, $second => $mine, $other => $foreign] as $id => $org) {
                $db->insert('gf_vault_assets', [
                    'id' => $id, 'organization_id' => $org,
                    'uploaded_by' => $user, 'original_name' => 'private-'.$id.'.png',
                    'mime_type' => 'image/png', 'size_bytes' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes), 'storage_key' => $id,
                    'created_at' => $at,
                    'private_note' => $id === $first ? 'Referencia privada inicial' : null,
                    'deleted_at' => $id === $second ? $at : null,
                    'deleted_by' => $id === $second ? $user : null,
                ]);
            }
            file_put_contents($root.'/'.$first.'.blob', $bytes);
            file_put_contents($root.'/'.$second.'.blob', $bytes);
            $run = static function (string $org, ?string $expect = null) use ($tester): array {
                $options = ['--organization' => $org];
                if ($expect !== null) {
                    $options['--expect'] = $expect;
                }
                $exit = $tester->execute($options);
                $result = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

                return [$exit, $result, $tester->getDisplay()];
            };

            [$exit, $good, $display] = $run($mine);
            self::assertSame(0, $exit);
            self::assertSame('verified', $good['status']);
            self::assertSame(['verified' => 2, 'missing' => 0, 'mismatch' => 0, 'unavailable' => 0], $good['checks']);
            self::assertSame(1, $good['active']);
            self::assertSame(1, $good['trash']);
            self::assertSame(2, $good['assets']);
            self::assertStringNotContainsString('Referencia privada inicial', $display);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $good['manifest_sha256']);
            foreach ([$first, $second, $other, $root, hash('sha256', $bytes)] as $private) {
                self::assertStringNotContainsString($private, $display);
            }

            [$exit, $matching] = $run($mine, $good['manifest_sha256']);
            self::assertSame(0, $exit);
            self::assertTrue($matching['expected_manifest_matches']);

            // A staging copy is explicit, never automatic, and refuses an
            // existing directory or a target under the release.
            $stagePath = $base.'/stage';
            $stage = new CommandTester(new VaultStageCommand($db, $storage, new VaultBlobVerifier($storage), $project));
            $verify = new CommandTester(new VaultVerifyStageCommand($project));
            self::assertSame(2, $stage->execute([
                '--organization' => $mine, '--target' => $stagePath,
            ]), 'Operator acknowledgement of frozen writes is required.');
            self::assertSame(2, $stage->execute([
                '--organization' => $mine, '--target' => $project.'/unsafe-copy',
                '--confirm-writes-stopped' => true,
            ]));
            self::assertSame(2, $stage->execute([
                '--organization' => $mine, '--target' => $root.'/unsafe-copy',
                '--confirm-writes-stopped' => true,
            ]));
            self::assertSame(0, $stage->execute([
                '--organization' => $mine, '--target' => $stagePath,
                '--confirm-writes-stopped' => true,
            ]));
            $stageResult = json_decode($stage->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('staged', $stageResult['status']);
            self::assertSame(2, $stageResult['assets']);
            self::assertSame(strlen($bytes) * 2, $stageResult['bytes']);
            self::assertSame($good['manifest_sha256'], $stageResult['manifest_sha256']);
            self::assertSame(0700, fileperms($stagePath) & 0777);
            self::assertSame(0700, fileperms($stagePath.'/blobs') & 0777);
            self::assertSame(0600, fileperms($stagePath.'/manifest.json') & 0777);
            $stagedManifest = json_decode(
                (string) file_get_contents($stagePath.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR,
            );
            self::assertSame($mine, $stagedManifest['organization_id']);
            self::assertSame(2, count($stagedManifest['assets']));
            self::assertSame('Referencia privada inicial', $stagedManifest['assets'][0]['private_note']);
            self::assertNull($stagedManifest['assets'][1]['private_note']);
            self::assertSame($good['manifest_sha256'], $stagedManifest['manifest_sha256']);
            self::assertStringNotContainsString($foreign, $stage->getDisplay());
            self::assertStringNotContainsString($root, $stage->getDisplay());

            $check = static function (?string $expected = null) use ($verify, $stagePath): array {
                $opts = ['--directory' => $stagePath];
                if ($expected !== null) {
                    $opts['--expect'] = $expected;
                }
                $code = $verify->execute($opts);
                return [$code, json_decode($verify->getDisplay(), true, 512, JSON_THROW_ON_ERROR)];
            };
            [$exit, $verifiedStage] = $check($good['manifest_sha256']);
            self::assertSame(0, $exit);
            self::assertSame('verified', $verifiedStage['status']);
            self::assertTrue($verifiedStage['expected_manifest_matches']);
            self::assertSame(2, $verifiedStage['assets']);
            [$exit, $wrongManifest] = $check(str_repeat('0', 64));
            self::assertSame(2, $exit);
            self::assertSame('manifest_mismatch', $wrongManifest['code']);
            self::assertSame(2, $stage->execute([
                '--organization' => $mine, '--target' => $stagePath,
                '--confirm-writes-stopped' => true,
            ]), 'Existing stage must never be overwritten.');

            $stagedFirst = $stagePath.'/blobs/'.$first.'.blob';
            file_put_contents($stagedFirst, substr_replace($bytes, 'X', 0, 1));
            [$exit, $damagedStage] = $check($good['manifest_sha256']);
            self::assertSame(2, $exit);
            self::assertSame('blob_integrity_failed', $damagedStage['code']);
            file_put_contents($stagedFirst, $bytes);
            file_put_contents($stagePath.'/blobs/unexpected.txt', 'unreferenced');
            [$exit, $unexpected] = $check();
            self::assertSame(2, $exit);
            self::assertSame('blob_count_mismatch', $unexpected['code']);
            unlink($stagePath.'/blobs/unexpected.txt');
            [$exit, $recoveredStage] = $check($good['manifest_sha256']);
            self::assertSame(0, $exit);
            self::assertSame('verified', $recoveredStage['status']);

            // The new recovery gate checks a preverified offline stage
            // AGAINST restored SQL and restored Vault bytes, not only itself.
            $restore = new CommandTester(new VaultVerifyRestoreCommand(
                $db, new VaultBlobVerifier($storage), new VaultVerifyStageCommand($project),
            ));
            $restoreArgs = [
                '--organization' => $mine, '--directory' => $stagePath,
                '--expect' => $good['manifest_sha256'],
                '--confirm-writes-stopped' => true,
            ];
            $restoredCheck = static function (array $args) use ($restore): array {
                $exitCode = $restore->execute($args);
                return [$exitCode, json_decode($restore->getDisplay(), true, 512, JSON_THROW_ON_ERROR)];
            };
            [$exit, $recovered] = $restoredCheck($restoreArgs);
            self::assertSame(0, $exit);
            self::assertSame('verified', $recovered['status']);
            self::assertSame(2, $recovered['assets']);
            self::assertTrue($recovered['stage_matches_database_and_originals']);
            [$exit, $frozenMissing] = $restoredCheck(array_diff_key($restoreArgs, ['--confirm-writes-stopped' => true]));
            self::assertSame(2, $exit);
            self::assertSame('invalid_restore_options', $frozenMissing['code']);
            [$exit, $wrongExpected] = $restoredCheck(array_replace($restoreArgs, ['--expect' => str_repeat('0', 64)]));
            self::assertSame(2, $exit);
            self::assertSame('stage_integrity_failed', $wrongExpected['code']);
            [$exit, $foreignRestore] = $restoredCheck(array_replace($restoreArgs, ['--organization' => $foreign]));
            self::assertSame(2, $exit);
            self::assertSame('stage_catalog_mismatch', $foreignRestore['code']);

            file_put_contents($stagedFirst, substr_replace($bytes, 'X', 0, 1));
            [$exit, $damagedStaging] = $restoredCheck($restoreArgs);
            self::assertSame(2, $exit);
            self::assertSame('stage_integrity_failed', $damagedStaging['code']);
            file_put_contents($stagedFirst, $bytes);

            // A note is recoverable catalog metadata: losing or altering it
            // after restoration must invalidate the manifest, even if all
            // originals match byte for byte.
            $db->update('gf_vault_assets', ['private_note' => 'Nota restaurada distinta'], ['id' => $first]);
            [$exit, $missingNote] = $restoredCheck($restoreArgs);
            self::assertSame(2, $exit);
            self::assertSame('restored_catalog_mismatch', $missingNote['code']);
            $db->update('gf_vault_assets', ['private_note' => 'Referencia privada inicial'], ['id' => $first]);

            $db->update('gf_vault_assets', ['original_name' => 'renamed-after-stage.png'], ['id' => $first]);
            [$exit, $changedCatalog] = $restoredCheck($restoreArgs);
            self::assertSame(2, $exit);
            self::assertSame('restored_catalog_mismatch', $changedCatalog['code']);
            $db->update('gf_vault_assets', ['original_name' => 'private-'.$first.'.png'], ['id' => $first]);

            file_put_contents($root.'/'.$first.'.blob', substr_replace($bytes, 'X', 0, 1));
            [$exit, $wrongBytes] = $restoredCheck($restoreArgs);
            self::assertSame(2, $exit);
            self::assertSame('restored_blob_integrity_failed', $wrongBytes['code']);
            file_put_contents($root.'/'.$first.'.blob', $bytes);
            [$exit, $restoredAgain, $safeOutput] = (static function () use ($restore, $restoreArgs): array {
                $exitCode = $restore->execute($restoreArgs);
                return [$exitCode, json_decode($restore->getDisplay(), true, 512, JSON_THROW_ON_ERROR), $restore->getDisplay()];
            })();
            self::assertSame(0, $exit);
            self::assertSame('verified', $restoredAgain['status']);
            foreach ([$first, $second, $foreign, $root, $stagePath] as $private) {
                self::assertStringNotContainsString($private, $safeOutput);
            }

            file_put_contents($root.'/'.$first.'.blob', substr_replace($bytes, 'X', 0, 1));
            [$exit, $tampered] = $run($mine, $good['manifest_sha256']);
            self::assertSame(2, $exit);
            self::assertSame('incomplete', $tampered['status']);
            self::assertSame(1, $tampered['checks']['mismatch']);
            self::assertTrue($tampered['expected_manifest_matches'], 'Matching catalog alone does not make damaged bytes healthy.');

            @unlink($root.'/'.$second.'.blob');
            [$exit, $missing] = $run($mine);
            self::assertSame(2, $exit);
            self::assertSame(1, $missing['checks']['missing']);
            self::assertSame(1, $missing['checks']['mismatch']);
            self::assertSame(2, $stage->execute([
                '--organization' => $mine, '--target' => $base.'/invalid-stage',
                '--confirm-writes-stopped' => true,
            ]));
            self::assertFileDoesNotExist($base.'/invalid-stage/manifest.json');

            file_put_contents($root.'/'.$first.'.blob', $bytes);
            file_put_contents($root.'/'.$second.'.blob', $bytes);
            [$exit, $changed] = $run($mine, str_repeat('0', 64));
            self::assertSame(2, $exit);
            self::assertSame(2, $changed['checks']['verified']);
            self::assertFalse($changed['expected_manifest_matches']);

            [$exit, $emptyResult] = $run($empty);
            self::assertSame(2, $exit);
            self::assertSame(0, $emptyResult['assets']);
            self::assertSame('incomplete', $emptyResult['status']);

            [$exit, $invalid] = $run('not-a-uuid');
            self::assertSame(2, $exit);
            self::assertSame('invalid_audit_options', $invalid['code']);
            [$exit, $notFound] = $run(Uuid::v7()->toRfc4122());
            self::assertSame(2, $exit);
            self::assertSame('organization_not_found', $notFound['code']);
        } finally {
            $db->delete('gf_vault_assets', ['organization_id' => $mine]);
            $db->delete('gf_vault_assets', ['organization_id' => $foreign]);
            foreach ([$mine, $foreign, $empty] as $organization) {
                $db->delete('gf_identity_organizations', ['id' => $organization]);
            }
            $db->delete('gf_identity_users', ['id' => $user]);
            foreach (glob($base.'/stage/blobs/*') ?: [] as $stagedFile) {
                @unlink($stagedFile);
            }
            @unlink($base.'/stage/manifest.json');
            @rmdir($base.'/stage/blobs');
            @rmdir($base.'/stage');
            @unlink($root.'/'.$first.'.blob');
            @unlink($root.'/'.$second.'.blob');
            @rmdir($root);
            @rmdir($project);
            @rmdir($base.'/checkout');
            @rmdir($base);
        }
    }
}
