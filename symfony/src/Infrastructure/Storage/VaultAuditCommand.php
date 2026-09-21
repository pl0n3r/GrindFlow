<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Operator-only read-only audit. This does NOT create a backup, migrate data,
 * or prove a live backup is consistent while uploads/writes are running.
 */
#[AsCommand(name: 'grindflow:vault:audit', description: 'Comprueba originales privados de una organización sin modificar datos')]
final class VaultAuditCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly VaultBlobVerifier $verifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'UUID de la organización')
            ->addOption('expect', null, InputOption::VALUE_REQUIRED, 'Huella del manifiesto de un inventario anterior');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $input->getOption('organization');
        $expect = $input->getOption('expect');
        if (!is_string($organization)
            || preg_match('/\\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\\z/D', $organization) !== 1
            || ($expect !== null && (!is_string($expect)
                || preg_match('/\\A[0-9a-fA-F]{64}\\z/D', $expect) !== 1))) {
            return $this->emit($output, ['status' => 'error', 'code' => 'invalid_audit_options'], 2);
        }

        try {
            // Read one explicit tenant only. Never select an organization on
            // behalf of an operator and never print filenames, IDs or hashes.
            if ($this->db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization',
                ['organization' => $organization],
            ) === false) {
                return $this->emit($output, ['status' => 'error', 'code' => 'organization_not_found'], 2);
            }
            $assets = $this->db->fetchAllAssociative(
                <<<'SQL'
                    SELECT id, uploaded_by, original_name, mime_type, size_bytes,
                           sha256, storage_key, created_at, deleted_at, deleted_by
                    FROM gf_vault_assets
                    WHERE organization_id = :organization
                    ORDER BY id ASC
                    SQL,
                ['organization' => $organization],
            );

            $counts = ['verified' => 0, 'missing' => 0, 'mismatch' => 0, 'unavailable' => 0];
            $active = 0;
            $trash = 0;
            $manifest = hash_init('sha256');
            foreach ($assets as $asset) {
                // Stable serialization of *catalog metadata*, not a digest of
                // only the count. Restoration can compare entire inventories.
                $canonical = [
                    'id' => (string) $asset['id'],
                    'uploaded_by' => (string) $asset['uploaded_by'],
                    'original_name' => (string) $asset['original_name'],
                    'mime_type' => (string) $asset['mime_type'],
                    'size_bytes' => (int) $asset['size_bytes'],
                    'sha256' => strtolower((string) $asset['sha256']),
                    'storage_key' => (string) $asset['storage_key'],
                    'created_at' => (string) $asset['created_at'],
                    'deleted_at' => $asset['deleted_at'] === null ? null : (string) $asset['deleted_at'],
                    'deleted_by' => $asset['deleted_by'] === null ? null : (string) $asset['deleted_by'],
                ];
                hash_update($manifest, json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                $asset['deleted_at'] === null ? ++$active : ++$trash;
                ++$counts[$this->verifier->status($asset)];
            }
            $digest = hash_final($manifest);
            $matches = $expect === null ? null : hash_equals(strtolower($expect), $digest);
            // An empty catalog is a dangerous false-positive for restore checks.
            $healthy = count($assets) !== 0 && $counts['verified'] === count($assets) && $matches !== false;

            return $this->emit($output, [
                'schema' => 'grindflow-vault-audit-v1',
                'status' => $healthy ? 'verified' : 'incomplete',
                'assets' => count($assets),
                'active' => $active,
                'trash' => $trash,
                'checks' => $counts,
                'manifest_sha256' => $digest,
                'expected_manifest_matches' => $matches,
            ], $healthy ? 0 : 2);
        } catch (\Throwable) {
            // Never emit SQL exceptions, filesystem paths or connection details.
            return $this->emit($output, ['status' => 'error', 'code' => 'audit_unavailable'], 3);
        }
    }

    /** @param array<string,mixed> $payload */
    private function emit(OutputInterface $output, array $payload, int $exitCode): int
    {
        $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $exitCode;
    }
}
