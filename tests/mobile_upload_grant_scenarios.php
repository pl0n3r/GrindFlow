<?php

declare(strict_types=1);

require __DIR__.'/../app/Support/Security/MobileUploadGrant.php';

use App\Support\Security\MobileUploadGrant;

$scenario = $argv[1] ?? '';
$key = str_repeat('k', 32);
$grants = new MobileUploadGrant($key);

function outcome(callable $callback): array
{
    try {
        return ['ok' => true, 'value' => $callback()];
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => $error::class, 'message' => $error->getMessage()];
    }
}

if ($scenario === 'valid') {
    $token = $grants->issue('org-123', 2000, 5, 10485760, 'nonce-1');
    $boundaryToken = $grants->issue('org-123', 2000, 25, 104857600, 'nonce-max');
    echo json_encode([
        'token' => $token,
        'validated' => $grants->validate($token, 'org-123', 1000),
        'boundary' => $grants->validate($boundaryToken, 'org-123', 1000),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'invalid') {
    $token = $grants->issue('org-123', 2000, 5, 10485760, 'nonce-1');
    $parts = explode('.', $token);
    $parts[1][0] = $parts[1][0] === 'A' ? 'B' : 'A';
    $tampered = implode('.', $parts);

    echo json_encode([
        'tampered' => outcome(fn (): array => $grants->validate($tampered, 'org-123', 1000)),
        'expired' => outcome(fn (): array => $grants->validate($token, 'org-123', 2000)),
        'cross_tenant' => outcome(fn (): array => $grants->validate($token, 'org-999', 1000)),
        'zero_files' => outcome(fn (): string => $grants->issue('org-123', 2000, 0, 10485760, 'nonce-zero-files')),
        'zero_bytes' => outcome(fn (): string => $grants->issue('org-123', 2000, 5, 0, 'nonce-zero-bytes')),
        'too_many_files' => outcome(fn (): string => $grants->issue('org-123', 2000, 26, 10485760, 'nonce-2')),
        'too_many_bytes' => outcome(fn (): string => $grants->issue('org-123', 2000, 5, 104857601, 'nonce-3')),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario inválido\n");
exit(2);
