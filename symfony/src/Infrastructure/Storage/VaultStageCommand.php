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
 * Explicit, operator-triggered staging of one tenant's original Vault blobs.
 * Not a MariaDB backup or a live-consistency guarantee. Never runs on deploy.
 */
#[AsCommand(name: 'grindflow:vault:stage', description: 'Prepara una copia privada de originales y manifiesto, sin alterar el Vault')]
final class VaultStageCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly PrivateVaultDirectory $storage,
        private readonly VaultBlobVerifier $verifier,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'UUID explícito de organización')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Directorio nuevo bajo un padre privado fuera del release')
            ->addOption('confirm-writes-stopped', null, InputOption::VALUE_NONE, 'Confirmar que no hay cambios al catálogo ni a los originales');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $org = $input->getOption('organization');
        $target = $input->getOption('target');
        if (!is_string($org)
            || preg_match('/\\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\\z/D', $org) !== 1
            || !is_string($target) || !$input->getOption('confirm-writes-stopped')) {
            return $this->emit($output, ['status' => 'error', 'code' => 'invalid_stage_options'], 2);
        }

        // An existing path must NEVER be overwritten, including an existing
        // symlink or an empty directory left by an incomplete earlier attempt.
        if ($target === '' || !str_starts_with($target, '/') || str_contains($target, "\0")
            || str_contains($target, '\\') || preg_match('#(?:^|/)\\.{1,2}(?:/|$)#D', $target) === 1
            || str_ends_with($target, '/') || file_exists($target) || is_link($target)) {
            return $this->emit($output, ['status' => 'error', 'code' => 'invalid_stage_target'], 2);
        }

        try {
            $parent = realpath(dirname($target));
            $release = realpath(dirname($this->projectDir));
            $root = realpath($this->storage->root());
            if ($parent === false || $release === false || $root === false
                || !is_dir($parent) || !is_writable($parent)
                || $parent === $release || str_starts_with($parent, $release.'/')
                || $parent === $root || str_starts_with($parent, $root.'/')
                || (($mode = @fileperms($parent)) === false) || ($mode & 0077) !== 0) {
                return $this->emit($output, ['status' => 'error', 'code' => 'invalid_stage_target'], 2);
            }
            $resolvedTarget = rtrim($parent, '/').'/'.basename($target);
            if (file_exists($resolvedTarget) || is_link($resolvedTarget)) {
                return $this->emit($output, ['status' => 'error', 'code' => 'invalid_stage_target'], 2);
            }
            if ($this->db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :org', ['org' => $org],
            ) === false) {
                return $this->emit($output, ['status' => 'error', 'code' => 'organization_not_found'], 2);
            }

            $sql = <<<'SQL'
                SELECT id, uploaded_by, original_name, mime_type, size_bytes, sha256,
                       storage_key, created_at, deleted_at, deleted_by, private_note, filing_state
                FROM gf_vault_assets WHERE organization_id = :org ORDER BY id ASC
                SQL;
            $assets = $this->db->fetchAllAssociative($sql, ['org' => $org]);
            if ($assets === []) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'empty_catalog'], 2);
            }
            foreach ($assets as $asset) {
                if ($this->verifier->status($asset) !== 'verified') {
                    return $this->emit($output, ['status' => 'incomplete', 'code' => 'source_integrity_failed'], 2);
                }
            }
            $digest = VaultManifest::digest($assets);
            if (!@mkdir($resolvedTarget, 0700) || !@mkdir($resolvedTarget.'/blobs', 0700)) {
                throw new \RuntimeException('Cannot create private stage.');
            }

            $bytesCopied = 0;
            foreach ($assets as $asset) {
                $key = (string) $asset['storage_key'];
                // Already validated by VaultBlobVerifier; validate again when
                // constructing filesystem paths. Never honor manifest-supplied paths.
                if (preg_match('/\\A[0-9a-fA-F-]{36}\\z/D', $key) !== 1) {
                    throw new \RuntimeException('Invalid blob key.');
                }
                $from = $root.'/'.$key.'.blob';
                $to = $resolvedTarget.'/blobs/'.$key.'.blob';
                if (is_link($from) || !is_file($from)) {
                    throw new \RuntimeException('Source changed during staging.');
                }
                $in = @fopen($from, 'rb');
                if ($in === false) {
                    throw new \RuntimeException('Source unavailable.');
                }
                try {
                    $dest = @fopen($to, 'xb');
                    if ($dest === false) {
                        throw new \RuntimeException('Cannot create staged blob.');
                    }
                    try {
                        if (!@chmod($to, 0600)
                            || stream_copy_to_stream($in, $dest) !== (int) $asset['size_bytes']) {
                            throw new \RuntimeException('Incomplete staged blob.');
                        }
                    } finally {
                        fclose($dest);
                    }
                } finally {
                    fclose($in);
                }
                clearstatcache(true, $to);
                $stagedHash = @hash_file('sha256', $to);
                if (@filesize($to) !== (int) $asset['size_bytes']
                    || $stagedHash === false
                    || !hash_equals(strtolower((string) $asset['sha256']), $stagedHash)) {
                    throw new \RuntimeException('Staged original did not match catalog.');
                }
                $bytesCopied += (int) $asset['size_bytes'];
            }

            // Fail closed if catalog changes while copying, even with the
            // operator acknowledgement. Writes still need to be stopped.
            $after = $this->db->fetchAllAssociative($sql, ['org' => $org]);
            if (!hash_equals($digest, VaultManifest::digest($after))) {
                throw new \RuntimeException('Catalog changed during staging.');
            }
            $manifest = [
                'schema' => 'grindflow-vault-stage-v1',
                'organization_id' => $org,
                'manifest_sha256' => $digest,
                'assets' => array_map(VaultManifest::canonical(...), $assets),
            ];
            $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $manifestPath = $resolvedTarget.'/manifest.json';
            if (@file_put_contents($manifestPath, $encoded."\n", LOCK_EX) !== strlen($encoded) + 1
                || !@chmod($manifestPath, 0600)) {
                throw new \RuntimeException('Cannot complete private manifest.');
            }

            return $this->emit($output, [
                'schema' => 'grindflow-vault-stage-v1',
                'status' => 'staged',
                'assets' => count($assets),
                'bytes' => $bytesCopied,
                'manifest_sha256' => $digest,
            ], 0);
        } catch (\Throwable) {
            // Do not erase or silently reuse an incomplete staging directory.
            // No filenames, paths, SQL or underlying exceptions in CLI output.
            return $this->emit($output, ['status' => 'error', 'code' => 'stage_unavailable'], 3);
        }
    }

    /** @param array<string,mixed> $data */
    private function emit(OutputInterface $output, array $data, int $code): int
    {
        $output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $code;
    }
}
