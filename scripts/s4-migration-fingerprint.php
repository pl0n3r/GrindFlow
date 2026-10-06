<?php

declare(strict_types=1);

$args = $_SERVER['argv'] ?? null;
if (! is_array($args) || count($args) !== 4) {
    fwrite(STDERR, "usage: s4-migration-fingerprint.php <checkout-sha> <migration-dir> <applied-versions-file>\n");
    exit(2);
}

$checkoutSha = strtolower(trim((string) $args[1]));
$migrationDir = rtrim((string) $args[2], DIRECTORY_SEPARATOR);
$appliedVersionsFile = (string) $args[3];

if (preg_match('/^[0-9a-f]{40,64}$/', $checkoutSha) !== 1) {
    fwrite(STDERR, "invalid checkout sha\n");
    exit(2);
}
if ($migrationDir === '' || ! is_dir($migrationDir) || is_link($migrationDir)) {
    fwrite(STDERR, "invalid migration directory\n");
    exit(2);
}
if (! is_file($appliedVersionsFile) || is_link($appliedVersionsFile)) {
    fwrite(STDERR, "invalid applied versions file\n");
    exit(2);
}

$files = glob($migrationDir.DIRECTORY_SEPARATOR.'*.php');
if ($files === false) {
    fwrite(STDERR, "cannot enumerate migrations\n");
    exit(2);
}

$migrations = [];
foreach ($files as $file) {
    if (! is_file($file) || is_link($file)) {
        fwrite(STDERR, "invalid migration file\n");
        exit(2);
    }
    $hash = hash_file('sha256', $file);
    if ($hash === false) {
        fwrite(STDERR, "cannot hash migration file\n");
        exit(2);
    }
    $migrations[] = [basename($file), $hash];
}
usort($migrations, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

$rawVersions = file($appliedVersionsFile, FILE_IGNORE_NEW_LINES);
if ($rawVersions === false) {
    fwrite(STDERR, "cannot read applied versions\n");
    exit(2);
}

$versions = [];
foreach ($rawVersions as $version) {
    $version = trim($version);
    if ($version === '') {
        continue;
    }
    if (str_contains($version, "\0") || preg_match('/[\r\n]/', $version) === 1) {
        fwrite(STDERR, "invalid applied migration version\n");
        exit(2);
    }
    $versions[$version] = true;
}
$versions = array_keys($versions);
sort($versions, SORT_STRING);

try {
    $payload = json_encode(
        [
            'version' => 1,
            'checkout_sha' => $checkoutSha,
            'migrations' => $migrations,
            'applied_versions' => $versions,
        ],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    fwrite(STDERR, "cannot encode fingerprint payload\n");
    exit(2);
}

fwrite(STDOUT, hash('sha256', $payload).PHP_EOL);
