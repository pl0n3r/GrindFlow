#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Read-only CLI diagnostic for the first image-only pilot path.
 * It never prints filesystem paths, secrets, hashes or media metadata.
 * Target readiness must be observed through the authenticated web-runtime probe.
 */
function result(string $status): string
{
    return $status === 'ready' ? 'ready' : 'not_ready';
}

function decoderReady(): bool
{
    return extension_loaded('gd')
        && function_exists('getimagesize')
        && function_exists('imagecreatefromstring')
        && function_exists('imagejpeg')
        && function_exists('imagepng');
}

function temporaryStorageReady(): bool
{
    $root = sys_get_temp_dir();
    if (! is_dir($root) || is_link($root) || ! is_writable($root)) {
        return false;
    }

    $probe = @tempnam($root, 'gf-ready-');
    if (! is_string($probe)) {
        return false;
    }

    try {
        if (is_link($probe) || ! @chmod($probe, 0600)) {
            return false;
        }
        $written = @file_put_contents($probe, 'ok', LOCK_EX);
        if ($written !== 2) {
            return false;
        }
        clearstatcache(true, $probe);
        $size = @filesize($probe);

        return $size === 2 && is_readable($probe) && is_writable($probe);
    } finally {
        if (is_file($probe) && ! is_link($probe)) {
            @unlink($probe);
        }
    }
}

/** @return array{ready: bool, reason: ?string, action: ?string} */
function privateVaultReadiness(): array
{
    $notReady = static fn (?string $reason = null, ?string $action = null): array => [
        'ready' => false,
        'reason' => $reason,
        'action' => $action,
    ];

    $projectDir = realpath(__DIR__.'/../symfony');
    if ($projectDir === false) {
        return $notReady();
    }

    $override = trim((string) getenv('GRINDFLOW_VAULT_ROOT'));
    $root = $override === '' ? $projectDir.'/var/vault' : rtrim($override, '/');
    if ($root === '' || ! str_starts_with($root, '/') || str_contains($root, "\0")
        || str_contains($root, '\\') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#D', $root) === 1) {
        return $notReady();
    }
    if ($override !== '') {
        $releaseRoot = realpath(dirname($projectDir));
        $resolved = realpath($root);
        if ($releaseRoot === false || $resolved === false || $resolved === $releaseRoot
            || str_starts_with($resolved, rtrim($releaseRoot, '/').'/')) {
            return $notReady();
        }
    }
    if (! is_dir($root) || is_link($root) || ! is_readable($root)) {
        return $notReady();
    }
    $mode = @fileperms($root);
    if ($mode === false) {
        return $notReady();
    }
    if (($mode & 0077) !== 0) {
        return $notReady('mode_not_private', 'set_private_vault_mode_0700');
    }

    return ['ready' => true, 'reason' => null, 'action' => null];
}

$vault = privateVaultReadiness();
$checks = [
    'decoder' => decoderReady(),
    'temporary_storage' => temporaryStorageReady(),
    'private_vault' => $vault['ready'],
];
$ready = ! in_array(false, $checks, true);
$payload = [
    'contract' => 'media-pilot-readiness-v1',
    'status' => result($ready ? 'ready' : 'not_ready'),
    'checks' => array_map(static fn (bool $ok): string => result($ok ? 'ready' : 'not_ready'), $checks),
    'evidence_scope' => 'cli_diagnostic',
    'ci_equivalent' => false,
];
if (is_string($vault['reason'])) {
    $payload['reasons'] = ['private_vault' => $vault['reason']];
}
if (is_string($vault['action'])) {
    $payload['actions'] = ['private_vault' => $vault['action']];
}

echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ready ? 0 : 2);
