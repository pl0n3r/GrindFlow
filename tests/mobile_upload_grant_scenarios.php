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

function signedGrant(array $payload, string $key): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', 'v1.'.$encoded, $key, true);

    return 'v1.'.$encoded.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}

if ($scenario === 'valid') {
    $token = $grants->issue('org-123', 1000, 2000, 5, 10485760, 'nonce-1');
    $boundaryToken = $grants->issue('org-123', 1000, 2000, 25, 104857600, 'nonce-max');
    echo json_encode([
        'token' => $token,
        'validated' => $grants->validate($token, 'org-123', 1100),
        'boundary' => $grants->validate($boundaryToken, 'org-123', 1100),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'invalid') {
    $token = $grants->issue('org-123', 1000, 2000, 5, 10485760, 'nonce-1');
    $parts = explode('.', $token);
    $parts[1][0] = $parts[1][0] === 'A' ? 'B' : 'A';
    $tampered = implode('.', $parts);

    $futureToken = $grants->issue('org-123', 2000, 2500, 5, 10485760, 'nonce-future');
    $tooLongSigned = signedGrant([
        'v' => 1,
        'scope' => 'guest-upload',
        'organization_id' => 'org-123',
        'issued_at' => 1000,
        'expires_at' => 4601,
        'max_files' => 5,
        'max_bytes' => 10485760,
        'nonce' => 'nonce-too-long-signed',
    ], $key);

    echo json_encode([
        'tampered' => outcome(fn (): array => $grants->validate($tampered, 'org-123', 1100)),
        'expired' => outcome(fn (): array => $grants->validate($token, 'org-123', 2000)),
        'cross_tenant' => outcome(fn (): array => $grants->validate($token, 'org-999', 1100)),
        'future_issued' => outcome(fn (): array => $grants->validate($futureToken, 'org-123', 1500)),
        'too_long_issue' => outcome(fn (): string => $grants->issue('org-123', 1000, 4601, 5, 10485760, 'nonce-too-long')),
        'too_long_signed' => outcome(fn (): array => $grants->validate($tooLongSigned, 'org-123', 1100)),
        'zero_files' => outcome(fn (): string => $grants->issue('org-123', 1000, 2000, 0, 10485760, 'nonce-zero-files')),
        'zero_bytes' => outcome(fn (): string => $grants->issue('org-123', 1000, 2000, 5, 0, 'nonce-zero-bytes')),
        'too_many_files' => outcome(fn (): string => $grants->issue('org-123', 1000, 2000, 26, 10485760, 'nonce-2')),
        'too_many_bytes' => outcome(fn (): string => $grants->issue('org-123', 1000, 2000, 5, 104857601, 'nonce-3')),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario inválido\n");
exit(2);
