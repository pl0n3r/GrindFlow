"""Offline integration checks for observability and media/storage contracts.

Only synthetic evidence is passed to existing evaluator code. This suite must
never verify a real hosting provider, call the network, or infer go-live.
"""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[1]
HEALTH = ROOT / "scripts" / "operations-health.py"
CORS = ROOT / "scripts" / "media-storage-cors-preflight.py"
STORAGE = ROOT / "symfony" / "src" / "Infrastructure" / "Storage" / "StorageHostingCapabilityDoctor.php"
RECEIPT = ROOT / "symfony" / "src" / "Observability" / "DiagnosticReceipt.php"
ADAPTER = ROOT / "symfony" / "src" / "Observability" / "DiagnosticOfflineAdapter.php"
SHA = "a" * 40
ORIGIN = "https://studio.invalid"


def load_offline_module(path: Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def operations_payload() -> dict[str, object]:
    signals = []
    for index, source in enumerate(("ci_health", "deploy_observer", "production_smoke"), 101):
        signals.append({
            "source": source,
            "status": "completed",
            "conclusion": "success",
            "head_sha": SHA,
            "run_id": index,
            "url": f"https://github.com/pl0n3r/GrindFlow/actions/runs/{index}",
            "updated_at": "2026-10-27T00:00:00Z",
        })
    return {"version": 1, "signals": signals}


class OperationsStorageReadinessTests(unittest.TestCase):
    def test_readonly_health_and_storage_contract(self) -> None:
        """#343/#345: errors, jobs, diagnostics, CORS, PUT and binaries fail closed."""
        health = load_offline_module(HEALTH, "gf637_operations_health")
        cors = load_offline_module(CORS, "gf637_offline_cors")

        healthy = health.evaluate(operations_payload(), main_sha=SHA)
        self.assertEqual("HEALTHY", healthy["state"])
        self.assertEqual(3, len(healthy["signals"]))
        self.assertEqual({"ci_health", "deploy_observer", "production_smoke"},
                         {row["source"] for row in healthy["signals"]})

        error_5xx = operations_payload()
        error_5xx["signals"][2]["conclusion"] = "failure"
        degraded = health.evaluate(error_5xx, main_sha=SHA)
        self.assertEqual("DEGRADED", degraded["state"])
        failed_smoke = next(row for row in degraded["signals"]
                            if row["source"] == "production_smoke")
        self.assertEqual(("DEGRADED", "CURRENT", "terminal_non_success"),
                         (failed_smoke["state"], failed_smoke["freshness"],
                          failed_smoke["reason"]))
        self.assertIn("actions/runs/103", degraded["alert"]["body"])

        job_failure = operations_payload()
        job_failure["signals"][1]["conclusion"] = "failure"
        failed_job = health.evaluate(job_failure, main_sha=SHA)
        self.assertEqual("DEGRADED", failed_job["state"])
        stale = operations_payload()
        stale["signals"][1]["head_sha"] = "b" * 40
        self.assertEqual("UNKNOWN", health.evaluate(stale, main_sha=SHA)["state"])
        missing = operations_payload()
        missing["signals"][1] = {
            "source": "deploy_observer", "status": "missing", "conclusion": None,
            "head_sha": None, "run_id": None, "url": None, "updated_at": None,
        }
        self.assertEqual("UNKNOWN", health.evaluate(missing, main_sha=SHA)["state"])
        tainted = operations_payload()
        tainted["signals"][1]["authorization"] = "Bearer fake-never-print"
        with self.assertRaises(health.OperationsHealthError) as rejected:
            health.evaluate(tainted, main_sha=SHA)
        self.assertNotIn("fake-never-print", str(rejected.exception))
        self.assertNotIn("fake-never-print", degraded["alert"]["body"])

        policy = {"CORSRules": [{
            "AllowedOrigins": [ORIGIN],
            "AllowedMethods": ["PUT"],
            "AllowedHeaders": ["Content-Type", "x-amz-*"],
        }]}
        self.assertEqual("compatible", cors.assess(policy, ORIGIN))
        wildcard = {"CORSRules": [{
            **policy["CORSRules"][0], "AllowedOrigins": ["*"],
        }]}
        self.assertEqual("incompatible", cors.assess(wildcard, ORIGIN))
        wrong_method = {"CORSRules": [{
            **policy["CORSRules"][0], "AllowedMethods": ["GET"],
        }]}
        self.assertEqual("incompatible", cors.assess(wrong_method, ORIGIN))
        self.assertEqual("unknown", cors.assess(policy, "http://studio.invalid"))
        self.assertEqual("unknown", cors.assess({"CORSRules": []}, ORIGIN))

        self.assertIsNotNone(shutil.which("php"), "PHP CLI requerido para la integración real")
        for path in (STORAGE, RECEIPT, ADAPTER):
            self.assertTrue(path.is_file(), str(path))
        # Execute production-class PHP code in a standalone, synthetic CLI.
        # Do not connect to a server, composer runtime, bucket or subprocess shell.
        php = (
            "<?php\n"
            + "\n".join("require " + json.dumps(str(path)) + ";"
                        for path in (STORAGE, RECEIPT, ADAPTER))
            + r"""
$doctor = new \GrindFlow\Infrastructure\Storage\StorageHostingCapabilityDoctor();
$adapter = \GrindFlow\Observability\DiagnosticOfflineAdapter::class;
$readiness = ['disk' => 'media', 'driver' => 's3', 'max_bytes' => 2147483648, 'configured' => true];
$put = [
    'method' => 'PUT',
    'url' => 'https://uploads.invalid/tenant/tenant_a/object?signature=fake-secret',
    'tenant_id' => 'tenant_a', 'expected_tenant_id' => 'tenant_a',
    'signature_present' => true, 'ttl_seconds' => 900,
    'issued_at' => 1000, 'expires_at' => 1900,
];
$cors = [
    'expected_origin' => 'https://studio.invalid',
    'allowed_origins' => ['https://studio.invalid'],
    'allowed_methods' => ['PUT'],
];
$tools = ['ffmpeg' => 'present', 'ffprobe' => 'present'];
$valid = $doctor->inspect($readiness, $put, $cors, $tools);
$unconfigured = $doctor->inspect(
    array_replace($readiness, ['configured' => false, 'bucket' => 'fake-secret']),
    $put, $cors, $tools
);
$badCors = $doctor->inspect(
    $readiness, $put, array_replace($cors, ['allowed_origins' => ['*']]), $tools
);
$badTenant = $doctor->inspect(
    $readiness, array_replace($put, ['tenant_id' => 'other_tenant']), $cors, $tools
);
$absentBinary = $doctor->inspect($readiness, $put, $cors, ['ffmpeg' => 'present']);
$missingBinary = $doctor->inspect(
    $readiness, $put, $cors, ['ffmpeg' => 'missing', 'ffprobe' => 'present']
);
$http = $adapter::fromSyntheticFixture([
    'provenance' => 'synthetic', 'event_id' => 'evt_aaaaaaaaaaaaaaaa',
    'kind' => 'HTTP_500', 'state' => 'FAILED', 'request_id' => 'req_bbbbbbbbbbbbbbbb',
]);
$job = $adapter::fromSyntheticFixture([
    'provenance' => 'synthetic', 'event_id' => 'evt_cccccccccccccccc',
    'kind' => 'JOB_FAILURE', 'state' => 'FAILED', 'job_id' => 'job_dddddddddddddddd',
]);
$rejected = 0;
foreach ([
    ['provenance' => 'untrusted', 'event_id' => 'evt_aaaaaaaaaaaaaaaa',
     'kind' => 'HTTP_500', 'state' => 'FAILED', 'request_id' => 'req_bbbbbbbbbbbbbbbb'],
    ['provenance' => 'synthetic', 'event_id' => 'evt_aaaaaaaaaaaaaaaa',
     'kind' => 'HTTP_500', 'state' => 'FAILED', 'request_id' => 'req_bbbbbbbbbbbbbbbb',
     'authorization' => 'fake-secret'],
    ['provenance' => 'synthetic', 'event_id' => 'evt_aaaaaaaaaaaaaaaa',
     'kind' => 'JOB_FAILURE', 'state' => 'FAILED'],
] as $bad) {
    try { $adapter::fromSyntheticFixture($bad); }
    catch (\InvalidArgumentException $exception) { $rejected++; }
}
echo json_encode([
    'valid' => $valid, 'unconfigured' => $unconfigured,
    'bad_cors' => $badCors, 'bad_tenant' => $badTenant,
    'absent_binary' => $absentBinary, 'missing_binary' => $missingBinary,
    'http' => $http, 'job' => $job, 'rejected' => $rejected,
], JSON_THROW_ON_ERROR);
"""
        )
        proc = subprocess.run(
            ["php", "-n"], input=php, text=True, capture_output=True,
            check=False, cwd=ROOT, timeout=20,
        )
        self.assertEqual(0, proc.returncode, proc.stderr)
        self.assertEqual("", proc.stderr)
        result = json.loads(proc.stdout)
        self.assertEqual("CANDIDATE_ONLY", result["valid"]["status"])
        self.assertTrue(result["valid"]["cors_contract_valid"])
        self.assertTrue(result["valid"]["signed_put_contract_valid"])
        self.assertTrue(result["valid"]["media_tools_reported"])
        self.assertEqual("NOT_CONFIGURED", result["unconfigured"]["status"])
        self.assertEqual("UNKNOWN", result["bad_cors"]["status"])
        self.assertFalse(result["bad_cors"]["cors_contract_valid"])
        self.assertEqual("UNKNOWN", result["bad_tenant"]["status"])
        self.assertFalse(result["bad_tenant"]["signed_put_contract_valid"])
        self.assertEqual("CHECKS_PENDING", result["absent_binary"]["status"])
        self.assertEqual("UNKNOWN", result["missing_binary"]["status"])
        self.assertFalse(result["missing_binary"]["media_tools_reported"])
        for name in ("valid", "unconfigured", "bad_cors", "bad_tenant",
                     "absent_binary", "missing_binary"):
            self.assertIs(result[name]["hosting_verified"], False)
            self.assertIs(result[name]["production_ready"], False)
        for name in ("http", "job"):
            self.assertEqual("FAILED", result[name]["state"])
            self.assertEqual("INVESTIGATE", result[name]["diagnostic_status"])
            self.assertIs(result[name]["operational_ready"], False)
        self.assertEqual("HTTP_500", result["http"]["kind"])
        self.assertEqual("JOB_FAILURE", result["job"]["kind"])
        self.assertEqual(3, result["rejected"])
        output = json.dumps(result, sort_keys=True)
        for secret in ("fake-secret", "uploads.invalid", "tenant_a", "other_tenant"):
            self.assertNotIn(secret, output)


if __name__ == "__main__":
    unittest.main()
