"""#519: synthetic-only, fail-closed GrindFlow diagnostic receipts."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "symfony/src/Observability/DiagnosticReceipt.php"
PHP = shutil.which("php")


def fixture(kind="HTTP_500", state="FAILED"):
    return {
        "event_id": "evt_0123456789abcdef",
        "kind": kind,
        "state": state,
        "request_id": "req_0123456789abcdef",
        "job_id": "job_fedcba9876543210",
        "publication_id": "pub_0011223344556677",
    }


def receipt(payload):
    if PHP is None:
        raise AssertionError("PHP CLI requerido para probar el contrato real")
    script = r'''
require $argv[1];
try {
    $value = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('INVALID_DIAGNOSTIC_METADATA');
    }
    echo json_encode(\GrindFlow\Observability\DiagnosticReceipt::fromTrustedMetadata($value), JSON_THROW_ON_ERROR);
} catch (Throwable $ignored) {
    echo json_encode(['error' => 'INVALID_DIAGNOSTIC_METADATA'], JSON_THROW_ON_ERROR);
}
'''
    p = subprocess.run(
        [PHP, "-r", script, str(SOURCE)],
        input=json.dumps(payload), text=True, capture_output=True, timeout=6,
        check=False,
    )
    if p.returncode != 0:
        raise AssertionError("Falló PHP CLI: " + p.stderr[:300])
    return json.loads(p.stdout)


class DiagnosticEventReceiptTests(unittest.TestCase):
    def test_error_receipt_redacts_secrets_and_preserves_correlation(self):
        for kind in ("HTTP_500", "JOB_FAILURE", "PUBLICATION_FAILURE"):
            with self.subTest(kind=kind):
                obj = fixture(kind)
                actual = receipt(obj)
                self.assertEqual(actual, receipt(obj))  # stable reference on replay
                self.assertEqual(actual["schema_version"], 1)
                self.assertEqual(actual["receipt_id"], obj["event_id"])
                self.assertEqual(actual["state"], "FAILED")
                self.assertEqual(actual["diagnostic_status"], "INVESTIGATE")
                self.assertFalse(actual["operational_ready"])
                self.assertEqual(actual["correlation"], {
                    k: obj[k] for k in ("request_id", "job_id", "publication_id")
                })
                self.assertNotIn("stack", actual)
                self.assertNotIn("message", actual)
                self.assertNotIn("payload", actual)
                self.assertNotIn("authorization", json.dumps(actual).lower())

        for sensitive in ("message", "password", "token", "authorization", "stack", "payload"):
            hostile = fixture()
            hostile[sensitive] = "PRIVATE_INFORMATION_DO_NOT_ECHO"
            result = receipt(hostile)
            self.assertEqual(result, {"error": "INVALID_DIAGNOSTIC_METADATA"})
            self.assertNotIn("PRIVATE_INFORMATION_DO_NOT_ECHO", json.dumps(result))

    def test_unknown_and_stale_states_fail_closed(self):
        for state in ("UNKNOWN", "STALE"):
            with self.subTest(state=state):
                obj = receipt(fixture(state=state))
                self.assertEqual(obj["state"], state)
                self.assertEqual(obj["diagnostic_status"], "BLOCKED")
                self.assertFalse(obj["operational_ready"])
        for state in ("HEALTHY", "OK", "RECOVERED", "", None, 1):
            with self.subTest(state=state):
                self.assertEqual(receipt(fixture(state=state)), {"error": "INVALID_DIAGNOSTIC_METADATA"})
        for field, value in (
            ("event_id", "evt_user@example.com"),
            ("request_id", "req_../../etc/passwd"),
            ("job_id", "job_bearer_token_value"),
            ("publication_id", "pub_1"),
            ("kind", "APPROVED"),
            ("kind", "JOB_FAILURE\n"),
        ):
            obj = fixture()
            obj[field] = value
            self.assertEqual(receipt(obj), {"error": "INVALID_DIAGNOSTIC_METADATA"})
        # Present-but-null correlation fields are malformed, not omitted.
        for field in ("event_id", "request_id", "job_id", "publication_id"):
            with self.subTest(null_field=field):
                obj = fixture()
                obj[field] = None
                self.assertEqual(receipt(obj), {"error": "INVALID_DIAGNOSTIC_METADATA"})
        # Truly absent optional correlation fields remain valid and do not
        # produce fabricated references in the resulting receipt.
        for kind, required in (("HTTP_500", "request_id"),
                               ("JOB_FAILURE", "job_id"),
                               ("PUBLICATION_FAILURE", "publication_id")):
            with self.subTest(minimal_kind=kind):
                minimal = fixture(kind)
                for ref_field in ("request_id", "job_id", "publication_id"):
                    if ref_field != required:
                        del minimal[ref_field]
                self.assertEqual(receipt(minimal)["correlation"],
                                 {required: minimal[required]})
        for kind, required in (("HTTP_500", "request_id"),
                               ("JOB_FAILURE", "job_id"),
                               ("PUBLICATION_FAILURE", "publication_id")):
            obj = fixture(kind)
            del obj[required]
            self.assertEqual(receipt(obj), {"error": "INVALID_DIAGNOSTIC_METADATA"})
        self.assertEqual(receipt([]), {"error": "INVALID_DIAGNOSTIC_METADATA"})
        self.assertEqual(receipt(None), {"error": "INVALID_DIAGNOSTIC_METADATA"})
