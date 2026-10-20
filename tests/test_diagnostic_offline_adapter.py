"""#524: execute the real PHP synthetic-only diagnostic adapter offline."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RECEIPT = ROOT / "symfony/src/Observability/DiagnosticReceipt.php"
ADAPTER = ROOT / "symfony/src/Observability/DiagnosticOfflineAdapter.php"
PHP = shutil.which("php")


def fixture(kind="HTTP_500", state="FAILED"):
    return {
        "provenance": "synthetic",
        "event_id": "evt_0123456789abcdef",
        "kind": kind,
        "state": state,
        "request_id": "req_0123456789abcdef",
        "job_id": "job_fedcba9876543210",
        "publication_id": "pub_0011223344556677",
    }


def adapt(payload):
    if PHP is None:
        raise AssertionError("PHP CLI requerido: prohibido saltar la prueba real")
    script = r'''
require $argv[1];
require $argv[2];
try {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) {
        throw new InvalidArgumentException('INVALID_DIAGNOSTIC_METADATA');
    }
    $value = \GrindFlow\Observability\DiagnosticOfflineAdapter::fromSyntheticFixture($input);
    echo json_encode($value, JSON_THROW_ON_ERROR);
} catch (Throwable $ignored) {
    echo json_encode(['error' => 'INVALID_DIAGNOSTIC_METADATA'], JSON_THROW_ON_ERROR);
}
'''
    result = subprocess.run(
        [PHP, "-r", script, str(RECEIPT), str(ADAPTER)],
        input=json.dumps(payload), text=True, capture_output=True,
        timeout=8, check=False,
    )
    if result.returncode:
        raise AssertionError("Falló PHP CLI: " + result.stderr[:300])
    return json.loads(result.stdout)


class DiagnosticOfflineAdapterTests(unittest.TestCase):
    def test_only_trusted_metadata_reaches_receipt(self):
        fields = {
            "schema_version", "receipt_id", "kind", "state",
            "diagnostic_status", "operational_ready", "correlation",
        }
        for kind in ("HTTP_500", "JOB_FAILURE", "PUBLICATION_FAILURE"):
            with self.subTest(kind=kind):
                source = fixture(kind=kind)
                actual = adapt(source)
                self.assertEqual(set(actual), fields)
                self.assertEqual(actual, adapt(source))
                self.assertEqual(actual["receipt_id"], source["event_id"])
                self.assertEqual(actual["schema_version"], 1)
                self.assertEqual(actual["state"], "FAILED")
                self.assertEqual(actual["diagnostic_status"], "INVESTIGATE")
                self.assertIs(actual["operational_ready"], False)
                self.assertEqual(actual["correlation"], {
                    name: source[name]
                    for name in ("request_id", "job_id", "publication_id")
                })
                self.assertNotIn("provenance", json.dumps(actual))

        for kind, required in (
            ("HTTP_500", "request_id"),
            ("JOB_FAILURE", "job_id"),
            ("PUBLICATION_FAILURE", "publication_id"),
        ):
            with self.subTest(minimal=kind):
                obj = fixture(kind)
                for key in ("request_id", "job_id", "publication_id"):
                    if key != required:
                        del obj[key]
                self.assertEqual(adapt(obj)["correlation"], {required: obj[required]})

    def test_unknown_stale_and_secret_fields_fail_closed(self):
        for state in ("UNKNOWN", "STALE"):
            with self.subTest(state=state):
                result = adapt(fixture(state=state))
                self.assertEqual(result["state"], state)
                self.assertEqual(result["diagnostic_status"], "BLOCKED")
                self.assertIs(result["operational_ready"], False)

        invalid = {"error": "INVALID_DIAGNOSTIC_METADATA"}
        for provenance in (None, "observed", "cached", "real", "UNKNOWN", ""):
            with self.subTest(provenance=provenance):
                obj = fixture()
                obj["provenance"] = provenance
                self.assertEqual(adapt(obj), invalid)
        no_origin = fixture()
        del no_origin["provenance"]
        self.assertEqual(adapt(no_origin), invalid)

        for field in ("message", "stack", "password", "token", "authorization",
                      "headers", "payload", "tenant_id", "user_email", "source"):
            with self.subTest(secret_field=field):
                obj = fixture()
                obj[field] = "PRIVATE_INFORMATION_DO_NOT_ECHO"
                response = adapt(obj)
                self.assertEqual(response, invalid)
                self.assertNotIn("PRIVATE_INFORMATION_DO_NOT_ECHO", json.dumps(response))

        for field in ("event_id", "request_id", "job_id", "publication_id"):
            with self.subTest(null_field=field):
                obj = fixture()
                obj[field] = None
                self.assertEqual(adapt(obj), invalid)
        for state in ("HEALTHY", "READY", "OK", None, 0):
            with self.subTest(invalid_state=state):
                self.assertEqual(adapt(fixture(state=state)), invalid)
        for field, value in (
            ("event_id", "evt_user@example.com"),
            ("request_id", "req_secret"),
            ("kind", "UNKNOWN_KIND"),
        ):
            obj = fixture()
            obj[field] = value
            self.assertEqual(adapt(obj), invalid)
        self.assertEqual(adapt([]), invalid)
        self.assertEqual(adapt(None), invalid)
