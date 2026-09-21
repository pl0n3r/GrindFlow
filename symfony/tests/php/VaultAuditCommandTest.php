<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Infrastructure\Storage\PrivateVaultDirectory;
use GrindFlow\Infrastructure\Storage\VaultAuditCommand;
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
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $good['manifest_sha256']);
            foreach ([$first, $second, $other, $root, hash('sha256', $bytes)] as $private) {
                self::assertStringNotContainsString($private, $display);
            }

            [$exit, $matching] = $run($mine, $good['manifest_sha256']);
            self::assertSame(0, $exit);
            self::assertTrue($matching['expected_manifest_matches']);

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
            @unlink($root.'/'.$first.'.blob');
            @unlink($root.'/'.$second.'.blob');
            @rmdir($root);
            @rmdir($project);
            @rmdir($base.'/checkout');
            @rmdir($base);
        }
    }
}
