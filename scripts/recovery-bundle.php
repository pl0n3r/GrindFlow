<?php

declare(strict_types=1);

function fail(string $code): never
{
    fwrite(STDERR, "ERROR: {$code}\n");
    exit(2);
}

function canonicalJson(array $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

function assertHash(string $value, string $code): string
{
    if (preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
        fail($code);
    }

    return $value;
}

function buildVaultIndex(string $root): array
{
    if ($root === '' || ! str_starts_with($root, '/') || is_link($root) || ! is_dir($root)) {
        fail('invalid_vault_stage_root');
    }
    $entries = scandir($root);
    if ($entries === false) {
        fail('vault_stage_unavailable');
    }
    $organizations = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', $entry) !== 1) {
            fail('invalid_vault_stage_entry');
        }
        $directory = $root.'/'.$entry;
        $manifestPath = $directory.'/manifest.json';
        if (
            is_link($directory)
            || ! is_dir($directory)
            || is_link($manifestPath)
            || ! is_file($manifestPath)
            || ! is_readable($manifestPath)
        ) {
            fail('vault_manifest_unavailable');
        }
        $raw = file_get_contents($manifestPath);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (
            ! is_array($manifest)
            || ($manifest['schema'] ?? null) !== 'grindflow-vault-stage-v1'
            || ($manifest['organization_id'] ?? null) !== $entry
            || ! is_string($manifest['manifest_sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $manifest['manifest_sha256']) !== 1
            || ! is_array($manifest['assets'] ?? null)
            || ! array_is_list($manifest['assets'])
        ) {
            fail('invalid_vault_manifest');
        }
        $organizations[] = [
            'organization_id' => strtolower($entry),
            'manifest_sha256' => strtolower($manifest['manifest_sha256']),
            'assets' => count($manifest['assets']),
        ];
    }
    usort(
        $organizations,
        static fn (array $a, array $b): int => $a['organization_id'] <=> $b['organization_id'],
    );
    $payload = [
        'schema' => 'grindflow-recovery-vault-index-v1',
        'organizations' => $organizations,
    ];
    $payload['vault_index_sha256'] = hash('sha256', canonicalJson($payload)."\n");

    return $payload;
}

function buildMetadata(array $args): array
{
    if (count($args) !== 7) {
        fail('invalid_metadata_arguments');
    }
    [$dbFingerprint, $vaultIndexSha, $releaseVersion, $releaseSha, $timestamp, $organizations, $assets] = $args;
    assertHash($dbFingerprint, 'invalid_database_fingerprint');
    assertHash($vaultIndexSha, 'invalid_vault_index_sha');
    if (preg_match('/\A0\.[0-9]+\.[0-9]+\z/D', $releaseVersion) !== 1) {
        fail('invalid_release_version');
    }
    if (preg_match('/\A[0-9a-f]{40}\z/D', $releaseSha) !== 1) {
        fail('invalid_release_sha');
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new DateTimeZone('UTC'));
    if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
        fail('invalid_timestamp');
    }
    foreach ([$organizations, $assets] as $count) {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', $count) !== 1) {
            fail('invalid_count');
        }
    }

    return [
        'schema' => 'grindflow-recovery-bundle-v1',
        'database_file' => 'database.sql.gz',
        'database_fingerprint' => $dbFingerprint,
        'vault_index_file' => 'vault-index.json',
        'vault_index_sha256' => $vaultIndexSha,
        'release_version' => $releaseVersion,
        'release_sha' => $releaseSha,
        'created_at' => $timestamp,
        'organizations' => (int) $organizations,
        'assets' => (int) $assets,
    ];
}

function verifyBundle(string $root): array
{
    if ($root === '' || ! str_starts_with($root, '/') || is_link($root) || ! is_dir($root)) {
        fail('invalid_bundle_root');
    }
    foreach (['metadata.json', 'vault-index.json', 'database.sql.gz', 'vault'] as $entry) {
        $path = $root.'/'.$entry;
        if (is_link($path)) {
            fail('unsafe_bundle_entry');
        }
    }
    if (! is_file($root.'/metadata.json') || ! is_file($root.'/vault-index.json')
        || ! is_file($root.'/database.sql.gz') || filesize($root.'/database.sql.gz') < 1
        || ! is_dir($root.'/vault')) {
        fail('incomplete_bundle');
    }
    $metadata = json_decode((string) file_get_contents($root.'/metadata.json'), true);
    $storedIndex = json_decode((string) file_get_contents($root.'/vault-index.json'), true);
    if (! is_array($metadata) || ! is_array($storedIndex)
        || array_keys($metadata) !== [
            'schema', 'database_file', 'database_fingerprint', 'vault_index_file',
            'vault_index_sha256', 'release_version', 'release_sha', 'created_at',
            'organizations', 'assets',
        ]
        || ($metadata['schema'] ?? null) !== 'grindflow-recovery-bundle-v1') {
        fail('invalid_bundle_metadata');
    }
    $actualIndex = buildVaultIndex($root.'/vault');
    if (! hash_equals(
        (string) ($metadata['vault_index_sha256'] ?? ''),
        (string) ($actualIndex['vault_index_sha256'] ?? ''),
    ) || canonicalJson($storedIndex) !== canonicalJson($actualIndex)) {
        fail('vault_index_mismatch');
    }
    $orgCount = count($actualIndex['organizations']);
    $assetCount = array_sum(array_column($actualIndex['organizations'], 'assets'));
    if (($metadata['organizations'] ?? null) !== $orgCount || ($metadata['assets'] ?? null) !== $assetCount) {
        fail('vault_count_mismatch');
    }
    assertHash((string) ($metadata['database_fingerprint'] ?? ''), 'invalid_database_fingerprint');
    assertHash((string) ($metadata['vault_index_sha256'] ?? ''), 'invalid_vault_index_sha');

    return [
        'status' => 'verified',
        'database_fingerprint' => $metadata['database_fingerprint'],
        'vault_index_sha256' => $metadata['vault_index_sha256'],
        'release_version' => $metadata['release_version'],
        'release_sha' => $metadata['release_sha'],
        'created_at' => $metadata['created_at'],
        'organizations' => $orgCount,
        'assets' => $assetCount,
    ];
}

$operation = $argv[1] ?? '';
try {
    if ($operation === 'vault-index' && count($argv) === 3) {
        echo canonicalJson(buildVaultIndex($argv[2]))."\n";
        exit(0);
    }
    if ($operation === 'metadata') {
        echo canonicalJson(buildMetadata(array_slice($argv, 2)))."\n";
        exit(0);
    }
    if ($operation === 'verify' && count($argv) === 3) {
        echo canonicalJson(verifyBundle($argv[2]))."\n";
        exit(0);
    }
} catch (JsonException) {
    fail('json_failed');
} catch (Throwable) {
    fail('bundle_unavailable');
}

fail('invalid_operation');
