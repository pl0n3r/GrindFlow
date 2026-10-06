<?php

declare(strict_types=1);

$args = $_SERVER['argv'] ?? null;
if (! is_array($args) || count($args) !== 3) {
    fwrite(STDERR, "usage: s4-migration-fingerprint.php <checkout-sha> <migration-dir>\n");
    exit(2);
}

array_shift($args);
$checkoutSha = strtolower(trim((string) array_shift($args)));
$migrationDir = rtrim((string) array_shift($args), DIRECTORY_SEPARATOR);

if (preg_match('/^[0-9a-f]{40,64}$/', $checkoutSha) !== 1) {
    fwrite(STDERR, "invalid checkout sha\n");
    exit(2);
}
if ($migrationDir === '' || ! is_dir($migrationDir) || is_link($migrationDir)) {
    fwrite(STDERR, "invalid migration directory\n");
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
    $migrations[basename($file)] = $hash;
}
ksort($migrations, SORT_STRING);

$rawVersions = stream_get_contents(STDIN);
if ($rawVersions === false) {
    fwrite(STDERR, "cannot read applied versions\n");
    exit(2);
}
$rawVersions = preg_split('/\R/u', $rawVersions);
if ($rawVersions === false) {
    fwrite(STDERR, "cannot encode fingerprint payload\n");
    exit(2);
}

$versions = [];
foreach ($rawVersions as $version) {
    $version = trim($version);
    if ($version === '') {
        continue;
    }
    if (str_contains($version, "\0")) {
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
if (! is_string($payload)) {
    fwrite(STDERR, "cannot encode fingerprint payload\n");
    exit(2);
}

fwrite(STDOUT, hash('sha256', $payload).PHP_EOL);
