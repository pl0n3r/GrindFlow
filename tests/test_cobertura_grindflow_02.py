"""GrindFlow #755: synthetic offline contract for the Direct Upload readiness slice.

Only SPEC:013 is independently testable as a narrow offline sub-contract here.
The other 19 source rows need production evidence or are policy/architecture.
No coverage-matrix state is read, asserted, or modified by this suite.

The PHP probe requires the REAL repository source files; it uses only a
synthetic in-memory DirectUploadStorage. If Composer is unavailable, the only
stub is Symfony's Uuid::isValid() identifier parser, not application logic.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STORAGE = ROOT / "symfony/src/Infrastructure/Storage/DirectUploadStorage.php"
CIPHER = ROOT / "symfony/src/Infrastructure/Storage/DirectUploadTokenCipher.php"
READINESS = ROOT / "symfony/src/Infrastructure/Storage/DirectUploadReadiness.php"

# Executable PHP probe is deliberately kept inside the only claimed Python test.
# Production source classes are loaded verbatim, never reimplemented here.
PHP_PROBE = r'''<?php
declare(strict_types=1);
namespace Symfony\Component\Uid {
    // Exercise the real Composer dependency when present. A missing or broken
    // Symfony Uuid class must never fall back silently to a permissive stub.
    $autoload = $argv[5] ?? '';
    if ($autoload !== '' && is_file($autoload)) {
        require_once $autoload;
        if (!class_exists(Uuid::class)) {
            throw new \RuntimeException('Symfony Uuid is unavailable despite Composer autoload');
        }
    }
    if (!class_exists(Uuid::class)) {
        // Offline-only fallback for the identifier shape (not application logic).
        final class Uuid {
            public static function isValid(string $value): bool {
                return preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/iD', $value) === 1;
            }
        }
    }
}
namespace {
    require $argv[1];
    require $argv[2];
    require $argv[3];

    use GrindFlow\Infrastructure\Storage\DirectUploadStorage;
    use GrindFlow\Infrastructure\Storage\DirectUploadTokenCipher;
    use GrindFlow\Infrastructure\Storage\DirectUploadReadiness;

    final class SyntheticStorage implements DirectUploadStorage {
        public function __construct(private readonly bool $up) {}
        public function available(): bool { return $this->up; }
        public function disk(): string { return 'synthetic-local'; }
        public function driver(): string { return 'synthetic-driver'; }
        public function temporaryUpload(string $storageKey, string $mimeType, int $byteSize, int $expiresAt): array {
            throw new \LogicException('No provider/network calls allowed');
        }
        public function exists(string $storageKey): bool { return false; }
        public function size(string $storageKey): ?int { return null; }
        public function readStream(string $storageKey) { return null; }
        public function delete(string $storageKey): void { throw new \LogicException('No writes'); }
        public function promote(string $stagingKey, string $finalKey): void { throw new \LogicException('No writes'); }
    }

    function check(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    function mustReject(callable $fn, string $expected): void {
        try { $fn(); }
        catch (\InvalidArgumentException $e) {
            check($e->getMessage() === $expected, 'Unexpected rejection reason: '.$e->getMessage());
            return;
        }
        throw new \RuntimeException('Expected argument rejection: '.$expected);
    }

    $org = '11111111-1111-4111-8111-111111111111';
    $actor = '22222222-2222-4222-8222-222222222222';
    $staging = '33333333-3333-4333-8333-333333333333';
    $disk = 'synthetic-local';
    $key = 'organizations/'.$org.'/staging/'.$staging;
    $now = 1700000000;
    $secret = str_repeat('S', 32); // synthetic local fixture, never an application secret
    $cipher = new DirectUploadTokenCipher($secret);
    $storage = new SyntheticStorage(true);
    $summary = (new DirectUploadReadiness($storage, $cipher))->publicSummary();
    $method = $argv[4] ?? '';

    if ($method === 'positive') {
        check($cipher->configured(), 'Synthetic 32-byte secret must enable crypto');
        check($summary === [
            'disk' => 'synthetic-local',
            'driver' => 'synthetic-driver',
            'max_bytes' => 2147483648,
            'configured' => true,
        ], 'Readiness must be precise and sanitized');
        foreach ([300, 900, 3600] as $ttl) {
            $token = $cipher->issue($org, $actor, $disk, $key, 'synthetic.webp',
                'image/webp', 2147483648, $ttl, $now);
            $decoded = $cipher->decryptFor($token, $org, $actor, $disk, $now);
            check(is_array($decoded), 'Valid token must round-trip');
            check($decoded['expires_at'] === $now + $ttl, 'TTL mismatch');
            check($decoded['byte_size'] === 2147483648, 'Size ceiling mismatch');
            check($decoded['storage_key'] === $key, 'Staging key mismatch');
            check($decoded['filename'] === 'synthetic.webp', 'Filename mismatch');
        }
    } elseif ($method === 'negative') {
        $token = $cipher->issue($org, $actor, $disk, $key, 'synthetic.webp',
            'image/webp', 128, 300, $now);
        check($cipher->decryptFor($token, $org, $actor, $disk, $now + 299) !== null,
            'Token must work before expiry');
        check($cipher->decryptFor($token, $org, $actor, $disk, $now + 300) === null,
            'Token must expire exactly at deadline');
        check($cipher->decryptFor($token, $org, '44444444-4444-4444-8444-444444444444', $disk, $now) === null,
            'Foreign actor must fail closed');
        check($cipher->decryptFor($token, '55555555-5555-4555-8555-555555555555', $actor, $disk, $now) === null,
            'Foreign organization must fail closed');
        check($cipher->decryptFor($token, $org, $actor, 'foreign-disk', $now) === null,
            'Foreign disk must fail closed');
        $parts = explode('.', $token);
        check(count($parts) === 4, 'Invalid token format');
        $parts[3][0] = $parts[3][0] === 'A' ? 'B' : 'A';
        check($cipher->decryptFor(implode('.', $parts), $org, $actor, $disk, $now) === null,
            'Tampered authentication tag must fail');
        foreach ([299, 3601] as $ttl) {
            mustReject(fn () => $cipher->issue($org, $actor, $disk, $key,
                'synthetic.webp', 'image/webp', 128, $ttl, $now),
                'Direct upload TTL is outside the allowed range.');
        }
        foreach ([
            [$key, 'image/webp', 2147483649, 'synthetic.webp'],
            [$key, 'image/webp', 0, 'synthetic.webp'],
            [$key, 'application/x-php', 128, 'synthetic.webp'],
            ['organizations/foreign/staging/'.$staging, 'image/webp', 128, 'synthetic.webp'],
            [$key, 'image/webp', 128, '../not-safe.webp'],
        ] as [$storageKey, $mime, $bytes, $name]) {
            mustReject(fn () => $cipher->issue($org, $actor, $disk, $storageKey,
                $name, $mime, $bytes, 900, $now),
                'Direct upload completion token payload is invalid.');
        }
        $short = new DirectUploadTokenCipher(str_repeat('S', 31));
        check(!$short->configured(), '31-byte secret must not configure encryption');
        $noKey = (new DirectUploadReadiness($storage, $short))->publicSummary();
        check($noKey['configured'] === false, 'Missing key must make readiness false');
        check((new DirectUploadReadiness(new SyntheticStorage(false), $cipher))
            ->publicSummary()['configured'] === false, 'Unavailable provider must fail closed');
        check($short->decryptFor($token, $org, $actor, $disk, $now) === null,
            'Missing crypto key must deny token decryption');
        // Tokens are short-lived capabilities, not bearer credentials that can
        // survive a key rotation, malformed transport, or a future-issued clock.
        $rotated = new DirectUploadTokenCipher(str_repeat('R', 32));
        check($rotated->configured(), 'Synthetic rotated key must be usable');
        check($rotated->decryptFor($token, $org, $actor, $disk, $now) === null,
            'Token signed by a different key must fail closed');
        foreach (['', 'v1', $token.'.extra', str_repeat('x', 4097),
            'v1.%.AA.AA'] as $malformed) {
            check($cipher->decryptFor($malformed, $org, $actor, $disk, $now) === null,
                'Malformed or oversized token must fail closed');
        }
        $futureToken = $cipher->issue($org, $actor, $disk, $key,
            'synthetic.webp', 'image/webp', 128, 300, $now + 61);
        check($cipher->decryptFor($futureToken, $org, $actor, $disk, $now) === null,
            'Token issued more than 60 seconds ahead must be rejected');
        $boundaryToken = $cipher->issue($org, $actor, $disk, $key,
            'synthetic.webp', 'image/webp', 128, 300, $now + 60);
        check($cipher->decryptFor($boundaryToken, $org, $actor, $disk, $now) !== null,
            'Token exactly 60 seconds ahead is within documented skew tolerance');
        try {
            $short->issue($org, $actor, $disk, $key, 'synthetic.webp',
                'image/webp', 128, 300, $now);
            throw new \RuntimeException('Missing crypto key unexpectedly issued a token');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'Direct upload token encryption is not configured.',
                'Unexpected reason for missing encryption key');
        }
    } elseif ($method === 'privacy') {
        check(array_keys($summary) === ['disk', 'driver', 'max_bytes', 'configured'],
            'Readiness must not expose additional metadata');
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        foreach (['password', 'secret', 'credential', 'bucket', 'endpoint', 'token', 'access_key'] as $term) {
            check(!str_contains($encoded, $term), 'Readiness discloses private attribute');
        }
        check(!str_contains($encoded, $secret), 'Readiness leaked synthetic test secret');
    } else { throw new \InvalidArgumentException('Unknown test mode'); }
    echo json_encode(['ok' => true, 'mode' => $method], JSON_THROW_ON_ERROR);
}
'''


class CoberturaGrindflow02Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        super().setUpClass()
        cls.php = shutil.which("php")
        if not cls.php:
            raise AssertionError("PHP CLI required: no silent acceptance skips")
        for path in (STORAGE, CIPHER, READINESS):
            if not path.is_file():
                raise AssertionError(f"Real application PHP source missing: {path}")
        cls._tmp = tempfile.TemporaryDirectory(prefix="gf755-synthetic-")
        cls.script = Path(cls._tmp.name) / "probe.php"
        cls.script.write_text(PHP_PROBE, encoding="utf-8")

    @classmethod
    def tearDownClass(cls) -> None:
        if hasattr(cls, "_tmp"):
            cls._tmp.cleanup()
        super().tearDownClass()

    def _probe(self, mode: str) -> dict[str, object]:
        result = subprocess.run(
            [self.php, str(self.script), str(STORAGE), str(CIPHER), str(READINESS),
             mode, str(ROOT / "symfony/vendor/autoload.php")],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
        )
        self.assertEqual(result.returncode, 0, result.stderr[-2000:] + result.stdout[-500:])
        self.assertEqual(result.stderr, "", "PHP probe emitted a warning/error")
        try:
            payload = json.loads(result.stdout)
        except json.JSONDecodeError as exc:
            self.fail(f"Invalid real PHP probe JSON: {exc}: {result.stdout[:250]}")
        self.assertEqual(payload, {"ok": True, "mode": mode})
        return payload

    def test_contrato_principal(self) -> None:
        """SPEC:013: call real production classes and assert 2 GiB, TTL 300..3600."""
        self._probe("positive")

    def test_falla_cerrado(self) -> None:
        """SPEC:013: reject cross-tenant tokens, malformed inputs and clock/key abuse."""
        self._probe("negative")

    def test_sin_datos_reales(self) -> None:
        """SPEC:013: sanitized readiness only; no network or private data."""
        self._probe("privacy")
        code = self.script.read_text(encoding="utf-8")
        for forbidden in ("curl_exec(", "file_get_contents('http", "getenv('AWS_", "getenv('GH_"):
            self.assertNotIn(forbidden, code)
        self.assertTrue(re.fullmatch(r"[\x00-\x7f]*", code), "Probe must remain ASCII")


if __name__ == "__main__":
    unittest.main()
