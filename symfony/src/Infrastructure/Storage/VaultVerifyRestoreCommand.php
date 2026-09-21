<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Read-only comparison of a verified staged Vault with the CURRENT isolated
 * database and Vault root. The operator must separately restore a complete
 * database and original blobs before invoking it.
 */
#[AsCommand(name: 'grindflow:vault:verify-restore', description: 'Contrasta la copia privada con el catálogo y originales de un entorno restaurado')]
final class VaultVerifyRestoreCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly VaultBlobVerifier $verifier,
        private readonly VaultVerifyStageCommand $stageVerifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('organization', null, InputOption::VALUE_REQUIRED, 'UUID de organización a contrastar')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Directorio privado de stage')
            ->addOption('expect', null, InputOption::VALUE_REQUIRED, 'Huella guardada antes del backup')
            ->addOption('confirm-writes-stopped', null, InputOption::VALUE_NONE, 'Confirmar que el entorno restaurado no está recibiendo escrituras');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $org = $input->getOption('organization');
        $directory = $input->getOption('directory');
        $expected = $input->getOption('expect');
        if (!is_string($org)
            || preg_match('/\\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\\z/D', $org) !== 1
            || !is_string($directory) || !is_string($expected)
            || preg_match('/\\A[0-9a-fA-F]{64}\\z/D', $expected) !== 1
            || !$input->getOption('confirm-writes-stopped')) {
            return $this->emit($output, ['status' => 'error', 'code' => 'invalid_restore_options'], 2);
        }

        try {
            // Reuse ALL offline stage validation: private directory, closed
            // manifest schema, exact files, sizes and SHA-256. Never trust
            // an unverified manifest or use a path coming from its contents.
            $stage = new CommandTester($this->stageVerifier);
            $args = ['--directory' => $directory, '--expect' => $expected];
            if ($stage->execute($args) !== 0) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'stage_integrity_failed'], 2);
            }
            $stageResult = json_decode($stage->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($stageResult) || $stageResult['status'] !== 'verified'
                || !hash_equals(strtolower($expected), (string) $stageResult['manifest_sha256'])) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'stage_integrity_failed'], 2);
            }

            // Offline stage verification has already checked this file's
            // structure and size. Re-check its identity before looking up SQL.
            $path = rtrim($directory, '/').'/manifest.json';
            if (is_link($path) || !is_file($path)) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'stage_integrity_failed'], 2);
            }
            $manifest = json_decode((string) @file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || !isset($manifest['organization_id'], $manifest['manifest_sha256'], $manifest['assets'])
                || !is_string($manifest['organization_id']) || !is_string($manifest['manifest_sha256'])
                || !is_array($manifest['assets']) || $manifest['organization_id'] !== $org
                || !hash_equals(strtolower($expected), strtolower($manifest['manifest_sha256']))
                || !hash_equals(strtolower($expected), VaultManifest::digest($manifest['assets']))) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'stage_catalog_mismatch'], 2);
            }

            if ($this->db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :org',
                ['org' => $org],
            ) === false) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'organization_not_restored'], 2);
            }

            $sql = <<<'SQL'
                SELECT id, uploaded_by, original_name, mime_type, size_bytes, sha256,
                       storage_key, created_at, deleted_at, deleted_by, private_note, usage_scope
                FROM gf_vault_assets WHERE organization_id = :org ORDER BY id ASC
                SQL;
            $assets = $this->db->fetchAllAssociative($sql, ['org' => $org]);
            if ($assets === [] || count($assets) !== count($manifest['assets'])
                || !hash_equals(strtolower($expected), VaultManifest::digest($assets))) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'restored_catalog_mismatch'], 2);
            }
            foreach ($assets as $asset) {
                if ($this->verifier->status($asset) !== 'verified') {
                    return $this->emit($output, ['status' => 'incomplete', 'code' => 'restored_blob_integrity_failed'], 2);
                }
            }

            // Detect concurrent metadata changes even if the operator falsely
            // confirmed write freeze. This is not a DB snapshot guarantee.
            $after = $this->db->fetchAllAssociative($sql, ['org' => $org]);
            if (!hash_equals(strtolower($expected), VaultManifest::digest($after))
                || $stage->execute($args) !== 0) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'restore_changed_during_audit'], 2);
            }

            return $this->emit($output, [
                'schema' => 'grindflow-vault-restore-check-v1',
                'status' => 'verified',
                'assets' => count($assets),
                'manifest_sha256' => strtolower($expected),
                'stage_matches_database_and_originals' => true,
            ], 0);
        } catch (\Throwable) {
            // No internal path, SQL error, asset name, key or credential in JSON.
            return $this->emit($output, ['status' => 'error', 'code' => 'restore_check_unavailable'], 3);
        }
    }

    /** @param array<string,mixed> $data */
    private function emit(OutputInterface $output, array $data, int $code): int
    {
        $output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $code;
    }
}
