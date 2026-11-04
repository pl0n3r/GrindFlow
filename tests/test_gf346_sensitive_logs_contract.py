"""GF-346 / #899: fail-closed, synthetic-only sensitive diagnostic evidence.

Inventory of local error/receipt/job/audit producers with executable tests.
An offline test cannot certify third-party, Hostinger or live logging. Missing
coverage in the inventoried boundaries is an error, never assumed clean.
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
PHP = shutil.which("php")

# Source -> (executable suite, test method, proof inside the method).
# Adding a PHP producer under WATCHED without an audited test fails closed.
COVERAGE = {
    "symfony/src/Observability/DiagnosticReceipt.php": (
        "tests/test_diagnostic_event_receipt.py", "test_error_receipt_redacts_secrets_and_preserves_correlation",
        ("PRIVATE_INFORMATION_DO_NOT_ECHO", "self.assertNotIn"),
    ),
    "symfony/src/Observability/DiagnosticOfflineAdapter.php": (
        "tests/test_diagnostic_offline_adapter.py", "test_unknown_stale_and_secret_fields_fail_closed",
        ("PRIVATE_INFORMATION_DO_NOT_ECHO", "self.assertNotIn"),
    ),
    "symfony/src/Infrastructure/Diagnostics/JobFailureDiagnostics.php": (
        "symfony/tests/php/JobFailureDiagnosticsTest.php", "testUntrustedPayloadsAndPersonalInformationAreRejectedWithoutEcho",
        ("InvalidArgumentException", "assertStringNotContainsString"),
    ),
    "symfony/src/Infrastructure/Scheduler/JobRunEvidence.php": (
        "symfony/tests/php/JobRunEvidenceTest.php", "testSecretsPayloadsAndAmbiguousMetadataFailClosedWithoutEcho",
        ("Bearer secret", "$this->invalid"),
    ),
    "symfony/src/Infrastructure/Audit/TeamActionAudit.php": (
        "symfony/tests/php/TeamActionAuditTest.php", "testSensitiveDataHiddenInAllowlistedFieldsNeverLeaks",
        ("person@example.test", "AUDIT_INVALID_ROWS", "expectSafeError"),
    ),
    "symfony/src/Infrastructure/Storage/VaultAuditCommand.php": (
        "symfony/tests/php/VaultAuditCommandTest.php", "testAuditReportsTenantScopedIntegrityAndRejectsIncompleteRestore",
        ("assertStringNotContainsString", "getDisplay()"),
    ),
    "app/Support/Diagnostics/DiagnosticLog.php": (
        "tests/Feature/DiagnosticsTest.php", "test_diagnostic_log_sanitizes_server_failure_context",
        ("assertStringNotContainsString", "recent(1)"),
    ),
}
WATCHED = (
    "symfony/src/Observability",
    "symfony/src/Infrastructure/Diagnostics",
    "symfony/src/Infrastructure/Audit",
    "app/Support/Diagnostics",
)
KERNEL_SUITE = "symfony/tests/php/LogSanitizationContractTest.php"
KERNEL_METHOD = "testRealKernel500ResponseAndSyntheticDiagnosticLogContainNoSensitiveFixture"


def method_body(source: str, method: str) -> str:
    """Extract a test body; reject missing/renamed tests and assertions."""
    match = re.search(r"\b(?:function|def)\s+" + re.escape(method) + r"\s*\(", source)
    if not match:
        raise AssertionError("Missing executable negative scenario: " + method)
    tail = source[match.end():]
    next_method = re.search(r"\n\s*(?:public|protected|private)?\s*(?:function|def)\s+\w+\s*\(", tail)
    return tail[:next_method.start()] if next_method else tail


def audit_inventory(read=lambda path: (ROOT / path).read_text(encoding="utf-8")) -> None:
    for producer, (suite, method, proofs) in COVERAGE.items():
        src, tests = read(producer), read(suite)
        if not src.strip() or not tests.strip():
            raise AssertionError("Missing source or suite: " + producer)
        body = method_body(tests, method)
        if not all(proof in body for proof in proofs):
            raise AssertionError("Unproven negative assertion: " + suite + "::" + method)

    for directory in WATCHED:
        current = {p.relative_to(ROOT).as_posix() for p in (ROOT / directory).glob("*.php")}
        unmapped = current.difference(COVERAGE)
        if unmapped:
            raise AssertionError("New sensitive producer without coverage: " + repr(sorted(unmapped)))

    kernel = method_body(read(KERNEL_SUITE), KERNEL_METHOD)
    for proof in ("static::createClient()", "HTTP_AUTHORIZATION", "TestHandler",
                  "$log->getRecords()", "assertSanitized", "getContent()"):
        if proof not in kernel:
            raise AssertionError("Missing kernel/log negative: " + proof)
    ci = read(".github/workflows/grindflow-ci.yml")
    if "vendor/bin/phpunit" not in ci or "php vendor/bin/simple-phpunit -c phpunit.xml.dist" not in ci:
        raise AssertionError("Actual PHP negative suites are no longer wired to CI")
    if "<directory>tests/php</directory>" not in read("symfony/phpunit.xml.dist"):
        raise AssertionError("Symfony negative suites are not discovered by PHPUnit")


PHP_RUNNER = r'''
require $argv[1];
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
try {
    switch ($argv[2]) {
        case 'receipt':
            $value = \GrindFlow\Observability\DiagnosticReceipt::fromTrustedMetadata($input);
            break;
        case 'jobs':
            $value = \GrindFlow\Infrastructure\Diagnostics\JobFailureDiagnostics::summarize(
                [$input], new DateTimeImmutable('@1780000000'));
            break;
        case 'scheduler':
            $audit = new \GrindFlow\Infrastructure\Scheduler\JobRunEvidence();
            $now = new DateTimeImmutable('@1780000000');
            $audit->begin('scheduler', $input['key'], 1, $now);
            $audit->finish($input['key'], 1, 'failed', $now);
            $value = $audit->records();
            break;
        default:
            throw new RuntimeException('INVALID_TEST_MODE');
    }
} catch (InvalidArgumentException $exception) {
    $value = ['error' => $exception->getMessage()];
}
echo json_encode($value, JSON_THROW_ON_ERROR);
'''


def run_real_php(mode: str, payload: dict, source: Path | None = None):
    if PHP is None:
        raise AssertionError("Se necesita PHP CLI real; prohibido fingir la regresión")
    paths = {
        "receipt": "symfony/src/Observability/DiagnosticReceipt.php",
        "jobs": "symfony/src/Infrastructure/Diagnostics/JobFailureDiagnostics.php",
        "scheduler": "symfony/src/Infrastructure/Scheduler/JobRunEvidence.php",
    }
    target = source or ROOT / paths[mode]
    result = subprocess.run(
        [PHP, "-r", PHP_RUNNER, str(target), mode],
        input=json.dumps(payload), text=True, capture_output=True, timeout=7, check=False,
    )
    if result.returncode:
        raise AssertionError("PHP runtime guard failed: " + result.stderr[-300:])
    return json.loads(result.stdout)


def no_sensitive_output(result: object, forbidden: tuple[str, ...]) -> None:
    visible = json.dumps(result, ensure_ascii=False)
    for secret in forbidden:
        if secret in visible:
            raise AssertionError("Sensitive synthetic fixture escaped the evidence boundary")


class GF346SensitiveLogsContract(unittest.TestCase):
    def test_inventory_covers_known_producers_with_real_negative_suites(self):
        audit_inventory()

    def test_real_php_receipts_reject_payload_authorization_pii_and_exception(self):
        fixture = {"event_id": "evt_0123456789abcdef", "kind": "HTTP_500",
                   "state": "FAILED", "request_id": "req_0123456789abcdef"}
        safe = run_real_php("receipt", fixture)
        self.assertEqual(set(safe), {"schema_version", "receipt_id", "kind", "state",
                                     "diagnostic_status", "operational_ready", "correlation"})
        self.assertFalse(safe["operational_ready"])
        forbidden = ("synthetic-token-noncredential", "fictional@example.test",
                     "synthetic-private-exception", "Authorization: Bearer")
        for field, bad in (("authorization", "Authorization: Bearer " + forbidden[0]),
                           ("email", forbidden[1]), ("exception", forbidden[2]),
                           ("payload", {"token": forbidden[0]})):
            with self.subTest(field=field):
                hostile = {**fixture, field: bad}
                result = run_real_php("receipt", hostile)
                self.assertEqual(result, {"error": "INVALID_DIAGNOSTIC_METADATA"})
                no_sensitive_output(result, forbidden)

    def test_real_php_job_diagnostics_and_evidence_hide_sensitive_metadata(self):
        fixture = {"type": "scheduler", "attempts": 1,
                   "cause": "timeout", "failed_at": 1779999999}
        result = run_real_php("jobs", fixture)
        self.assertEqual(result["total_failures"], 1)
        self.assertEqual(result["jobs"][0]["last_cause"], "timeout")
        bad_cases = ({**fixture, "payload": "synthetic-job-secret"},
                     {**fixture, "email": "fictional@example.test"},
                     {**fixture, "cause": "Authorization: Bearer synthetic-token"})
        for hostile in bad_cases:
            response = run_real_php("jobs", hostile)
            self.assertEqual(response, {"error": "INVALID_JOB_FAILURE_METADATA"})
            no_sensitive_output(response, ("synthetic-job-secret", "fictional@example.test", "synthetic-token"))
        key = "c849f01e92b63e71f40bc02a365d9e17"
        records = run_real_php("scheduler", {"key": key})
        self.assertEqual(len(records), 1)
        self.assertEqual(records[0]["result"], "failed")
        no_sensitive_output(records, (key, "payload", "Authorization", "@example.test"))
        for invalid in ("fictional@example.test", "Bearer synthetic-token", "aaaa" * 8):
            self.assertEqual(run_real_php("scheduler", {"key": invalid}),
                             {"error": "INVALID_JOB_RUN_EVIDENCE"})

    def test_mutations_remove_negative_assertions_and_prove_guard_is_falsable(self):
        producer = "symfony/src/Observability/DiagnosticReceipt.php"
        suite = "tests/test_diagnostic_event_receipt.py"
        original = (ROOT / suite).read_text(encoding="utf-8")
        damaged = original.replace("PRIVATE_INFORMATION_DO_NOT_ECHO", "")
        self.assertNotEqual(original, damaged)

        def injected_read(path):
            return damaged if path == suite else (ROOT / path).read_text(encoding="utf-8")

        with self.assertRaises(AssertionError):
            audit_inventory(injected_read)

        original_php = (ROOT / producer).read_text(encoding="utf-8")
        changed = original_php.replace("'schema_version' => 1,",
                                       "'schema_version' => 1, 'payload' => 'synthetic-leak',", 1)
        self.assertNotEqual(original_php, changed)
        with tempfile.TemporaryDirectory() as tmp:
            injected = Path(tmp) / "DiagnosticReceipt.php"
            injected.write_text(changed, encoding="utf-8")
            receipt = run_real_php("receipt", {"event_id": "evt_0123456789abcdef",
                                               "kind": "HTTP_500", "state": "FAILED",
                                               "request_id": "req_0123456789abcdef"}, injected)
        with self.assertRaises(AssertionError):
            no_sensitive_output(receipt, ("synthetic-leak",))


if __name__ == "__main__":
    unittest.main()
