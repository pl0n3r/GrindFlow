<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verify a staged directory independently of the live database. Does not
 * restore records, alter sources or interpret a stage as a complete DB backup.
 */
#[AsCommand(name: 'grindflow:vault:verify-stage', description: 'Comprueba una copia privada preparada del Vault sin restaurar datos')]
final class VaultVerifyStageCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Directorio previamente preparado')
            ->addOption('expect', null, InputOption::VALUE_REQUIRED, 'SHA-256 del catálogo de origen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getOption('directory');
        $expect = $input->getOption('expect');
        if (!is_string($directory) || $directory === '' || !str_starts_with($directory, '/')
            || str_contains($directory, "\0") || str_contains($directory, '\\')
            || preg_match('#(?:^|/)\\.{1,2}(?:/|$)#D', $directory) === 1
            || ($expect !== null && (!is_string($expect)
                || preg_match('/\\A[0-9a-fA-F]{64}\\z/D', $expect) !== 1))) {
            return $this->emit($output, ['status' => 'error', 'code' => 'invalid_verify_options'], 2);
        }
        try {
            if (is_link($directory) || !is_dir($directory)) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'stage_not_found'], 2);
            }
            $root = realpath($directory);
            $release = realpath(dirname($this->projectDir));
            if ($root === false || $release === false || $root === $release
                || str_starts_with($root, $release.'/')
                || (($mode = @fileperms($root)) === false) || ($mode & 0077) !== 0) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'unsafe_stage'], 2);
            }
            $path = $root.'/manifest.json';
            if (is_link($path) || !is_file($path) || !is_readable($path)
                || (($size = @filesize($path)) === false) || $size < 1 || $size > 512 * 1024) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'manifest_unavailable'], 2);
            }
            $content = @file_get_contents($path);
            $manifest = is_string($content) ? json_decode($content, true) : null;
            if (!is_array($manifest)
                || array_keys($manifest) !== ['schema', 'organization_id', 'manifest_sha256', 'assets']
                || $manifest['schema'] !== 'grindflow-vault-stage-v1'
                || !is_string($manifest['organization_id'])
                || preg_match('/\\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\\z/D', $manifest['organization_id']) !== 1
                || !is_string($manifest['manifest_sha256'])
                || preg_match('/\\A[0-9a-fA-F]{64}\\z/D', $manifest['manifest_sha256']) !== 1
                || !is_array($manifest['assets']) || !array_is_list($manifest['assets'])
                || count($manifest['assets']) < 1 || count($manifest['assets']) > 100) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'invalid_manifest'], 2);
            }
            $assets = $manifest['assets'];
            $keys = [];
            $previousId = '';
            $total = 0;
            foreach ($assets as $asset) {
                if (!is_array($asset)
                    || array_keys($asset) !== [
                        'id', 'uploaded_by', 'original_name', 'mime_type', 'size_bytes',
                        'sha256', 'storage_key', 'created_at', 'deleted_at', 'deleted_by', 'private_note',
                    ]
                    || !is_string($asset['id']) || !is_string($asset['storage_key'])
                    || !is_string($asset['sha256']) || !is_int($asset['size_bytes'])
                    || !is_string($asset['uploaded_by']) || !is_string($asset['original_name'])
                    || !is_string($asset['mime_type']) || !is_string($asset['created_at'])
                    || ($asset['deleted_at'] !== null && !is_string($asset['deleted_at']))
                    || ($asset['deleted_by'] !== null && !is_string($asset['deleted_by']))
                    || ($asset['private_note'] !== null && !is_string($asset['private_note']))
                    || preg_match('/\\A[0-9a-fA-F-]{36}\\z/D', $asset['storage_key']) !== 1
                    || preg_match('/\\A[0-9a-fA-F]{64}\\z/D', $asset['sha256']) !== 1
                    || $asset['id'] <= $previousId
                    || isset($keys[$asset['storage_key']])) {
                    return $this->emit($output, ['status' => 'incomplete', 'code' => 'invalid_manifest'], 2);
                }
                $keys[$asset['storage_key']] = true;
                $previousId = $asset['id'];
                $total += $asset['size_bytes'];
            }

            $digest = VaultManifest::digest($assets);
            if (!hash_equals(strtolower($manifest['manifest_sha256']), $digest)
                || ($expect !== null && !hash_equals(strtolower($expect), $digest))) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'manifest_mismatch'], 2);
            }
            $blobRoot = $root.'/blobs';
            if (is_link($blobRoot) || !is_dir($blobRoot)
                || (($mode = @fileperms($blobRoot)) === false) || ($mode & 0077) !== 0) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'blobs_unavailable'], 2);
            }
            $entries = @scandir($blobRoot);
            if ($entries === false || count($entries) !== count($assets) + 2) {
                return $this->emit($output, ['status' => 'incomplete', 'code' => 'blob_count_mismatch'], 2);
            }
            $verifier = new VaultBlobVerifier(new PrivateVaultDirectory($this->projectDir, $blobRoot));
            foreach ($assets as $asset) {
                if ($verifier->status($asset) !== 'verified') {
                    return $this->emit($output, ['status' => 'incomplete', 'code' => 'blob_integrity_failed'], 2);
                }
            }

            return $this->emit($output, [
                'schema' => 'grindflow-vault-stage-v1',
                'status' => 'verified',
                'assets' => count($assets),
                'bytes' => $total,
                'manifest_sha256' => $digest,
                'expected_manifest_matches' => $expect === null ? null : true,
            ], 0);
        } catch (\Throwable) {
            return $this->emit($output, ['status' => 'error', 'code' => 'verify_unavailable'], 3);
        }
    }

    /** @param array<string,mixed> $data */
    private function emit(OutputInterface $output, array $data, int $code): int
    {
        $output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $code;
    }
}
