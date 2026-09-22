"""Contracts for redacted single-writer rehearsal evidence."""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


def load(path: str, name: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load contract module")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


SINGLE = load(
    "scripts/single-writer-rehearsal-evidence.py",
    "single_writer_rehearsal_evidence",
)
OPERATOR = load(
    "scripts/operator-evidence-verifier.py",
    "single_writer_operator_evidence",
)
OWNERSHIP = load(
    "scripts/cutover-ownership-plan.py",
    "single_writer_ownership",
)


class SingleWriterRehearsalEvidenceTest(unittest.TestCase):
    def ownership_report(self, module="identity"):
        draft = OWNERSHIP.draft_envelope(module)
        return OWNERSHIP.build_report(draft["source"], draft["plan"])

    def operator_envelope(self, module="identity"):
        ownership = self.ownership_report(module)
        return {
            "contract": OPERATOR.INPUT_CONTRACT,
            "module": module,
            "ownership_report": ownership,
            "receipts": {
                "authorized_metadata_inventory": {
                    "contract": OPERATOR.RECEIPT_CONTRACT,
                    "evidence_type": "authorized_metadata_inventory",
                    "module": module,
                    "source_inventory_sha256": ownership["source_inventory_sha256"],
                    "evidence_sha256": "a" * 64,
                    "observed_at_utc": "2026-09-22T12:00:00Z",
                    "environment": "authorized_read_only_capture",
                    "operator_observed": True,
                    "contains_row_data": False,
                    "contains_secrets": False,
                },
                "real_backup_restore_rehearsal": {
                    "contract": OPERATOR.RECEIPT_CONTRACT,
                    "evidence_type": "real_backup_restore_rehearsal",
                    "module": module,
                    "source_inventory_sha256": ownership["source_inventory_sha256"],
                    "evidence_sha256": "b" * 64,
                    "observed_at_utc": "2026-09-22T12:10:00Z",
                    "environment": "isolated_restore_observation",
                    "operator_observed": True,
                    "contains_row_data": False,
                    "contains_secrets": False,
                },
            },
            "production_authorized": False,
        }

    def operator_report(self, module="identity"):
        return OPERATOR.build_report(self.operator_envelope(module))

    def receipt(self, module="identity"):
        report = self.operator_report(module)
        return {
            "contract": SINGLE.RECEIPT_CONTRACT,
            "module": module,
            "source_inventory_sha256": report["source_inventory_sha256"],
            "operator_evidence_bundle_sha256": report["evidence_bundle_sha256"],
            "evidence_sha256": "c" * 64,
            "observed_at_utc": "2026-09-22T12:30:00Z",
            "environment": "authorized_nonproduction_rehearsal",
            "previous_writer": "laravel",
            "candidate_writer": "symfony",
            "rollback_writer": "laravel",
            "legacy_writer_frozen": True,
            "candidate_writer_exclusive": True,
            "concurrent_writers_observed": False,
            "rollback_path_observed": True,
            "operator_observed": True,
            "contains_row_data": False,
            "contains_secrets": False,
        }

    def envelope(self, module="identity"):
        return {
            "contract": SINGLE.INPUT_CONTRACT,
            "module": module,
            "operator_evidence_report": self.operator_report(module),
            "single_writer_receipt": self.receipt(module),
            "production_authorized": False,
        }

    def assert_rejected(self, pattern, envelope):
        with self.assertRaisesRegex(ValueError, pattern):
            SINGLE.build_report(envelope)

    def test_identity_and_vault_remain_non_authorizing(self):
        for module in ("identity", "vault"):
            report = SINGLE.build_report(self.envelope(module))
            self.assertEqual(module, report["module"])
            self.assertIs(True, report["single_writer_receipt_validated"])
            self.assertIs(False, report["receipt_content_verified"])
            self.assertIs(False, report["production_ready"])
            self.assertIs(False, report["production_authorized"])
            self.assertEqual(
                list(SINGLE.REMAINING_PRECONDITIONS),
                report["remaining_preconditions"],
            )
            self.assertEqual(
                "authorized_nonproduction_rehearsal_reference_only",
                report["scope"],
            )

    def test_operator_report_must_be_same_module_and_safe(self):
        wrong_module = self.envelope()
        wrong_module["operator_evidence_report"]["module"] = "vault"
        self.assert_rejected("module mismatch", wrong_module)

        ready = self.envelope()
        ready["operator_evidence_report"]["production_ready"] = True
        self.assert_rejected("production_ready", ready)

        authorized = self.envelope()
        authorized["operator_evidence_report"]["production_authorized"] = True
        self.assert_rejected("production_authorized", authorized)

    def test_operator_report_preconditions_and_references_are_strict(self):
        pending = self.envelope()
        pending["operator_evidence_report"]["remaining_preconditions"] = ["production_smoke"]
        self.assert_rejected("preconditions", pending)

        refs = self.envelope()
        refs["operator_evidence_report"]["validated_receipt_references"].pop(
            "real_backup_restore_rehearsal"
        )
        self.assert_rejected("references", refs)

        bad_env = self.envelope()
        bad_env["operator_evidence_report"]["validated_receipt_references"][
            "authorized_metadata_inventory"
        ]["environment"] = "production"
        self.assert_rejected("environment", bad_env)

    def test_single_writer_transition_is_exact(self):
        for field, value in (
            ("previous_writer", "symfony"),
            ("candidate_writer", "laravel"),
            ("rollback_writer", "symfony"),
        ):
            envelope = self.envelope()
            envelope["single_writer_receipt"][field] = value
            self.assert_rejected("transition", envelope)

    def test_single_writer_boolean_attestations_are_exact(self):
        invalid = (
            ("legacy_writer_frozen", False),
            ("legacy_writer_frozen", 1),
            ("candidate_writer_exclusive", False),
            ("concurrent_writers_observed", True),
            ("rollback_path_observed", False),
            ("operator_observed", False),
            ("contains_row_data", True),
            ("contains_secrets", True),
        )
        for field, value in invalid:
            envelope = self.envelope()
            envelope["single_writer_receipt"][field] = value
            self.assert_rejected(field, envelope)

    def test_single_writer_receipt_must_bind_to_operator_bundle(self):
        envelope = self.envelope()
        envelope["single_writer_receipt"]["operator_evidence_bundle_sha256"] = "f" * 64
        self.assert_rejected("operator evidence mismatch", envelope)

    def test_evidence_digest_cannot_be_reused_across_stages(self):
        envelope = self.envelope()
        operator_digest = envelope["operator_evidence_report"][
            "validated_receipt_references"
        ]["authorized_metadata_inventory"]["evidence_sha256"]
        envelope["single_writer_receipt"]["evidence_sha256"] = operator_digest
        self.assert_rejected("distinct evidence", envelope)

    def test_operator_report_rejects_reused_receipt_digests(self):
        envelope = self.envelope()
        references = envelope["operator_evidence_report"]["validated_receipt_references"]
        references["real_backup_restore_rehearsal"]["evidence_sha256"] = (
            references["authorized_metadata_inventory"]["evidence_sha256"]
        )
        self.assert_rejected("remain distinct", envelope)

    def test_receipt_provenance_digest_timestamp_and_environment_are_strict(self):
        cases = (
            ("source_inventory_sha256", "f" * 64, "source inventory"),
            ("evidence_sha256", "bad", "digest"),
            ("observed_at_utc", "2026-09-22", "timestamp"),
            ("environment", "production", "environment"),
        )
        for field, value, pattern in cases:
            envelope = self.envelope()
            envelope["single_writer_receipt"][field] = value
            self.assert_rejected(pattern, envelope)

    def test_extra_fields_and_production_authorization_fail_closed(self):
        extra = self.envelope()
        extra["single_writer_receipt"]["database_url"] = "private"
        self.assert_rejected("fields", extra)

        authorized = self.envelope()
        authorized["production_authorized"] = True
        self.assert_rejected("production_authorized", authorized)

        integer_false = self.envelope()
        integer_false["production_authorized"] = 0
        self.assert_rejected("production_authorized", integer_false)

    def test_cli_is_offline_and_does_not_echo_secrets(self):
        payload = json.dumps(self.envelope())
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, "sitecustomize.py").write_text(
                "import sys\n"
                "def audit(event, args):\n"
                "    if event.startswith(('socket.', 'subprocess.', 'os.system')):\n"
                "        raise RuntimeError('external operation blocked')\n"
                "sys.addaudithook(audit)\n",
                encoding="utf-8",
            )
            env = dict(
                os.environ,
                PYTHONPATH=tmp,
                DATABASE_URL="mysql://private-secret.invalid/db",
            )
            result = subprocess.run(
                [
                    sys.executable,
                    str(ROOT / "scripts/single-writer-rehearsal-evidence.py"),
                    "--json",
                ],
                input=payload,
                text=True,
                encoding="utf-8",
                capture_output=True,
                env=env,
                check=False,
            )
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn("private-secret", result.stdout)
        self.assertNotIn("private-secret", result.stderr)

    def test_cli_rejects_duplicate_json_keys_at_any_depth(self):
        cmd = [
            sys.executable,
            str(ROOT / "scripts/single-writer-rehearsal-evidence.py"),
            "--json",
        ]
        payload = json.dumps(self.envelope(), separators=(",", ":"))
        root_duplicate = payload[:-1] + ',"production_authorized":false}'
        needle = '"contains_secrets":false'
        nested_duplicate = payload.replace(
            needle,
            needle + ',"contains_secrets":false',
            1,
        )
        for raw in (root_duplicate.encode("utf-8"), nested_duplicate.encode("utf-8")):
            result = subprocess.run(cmd, input=raw, capture_output=True, check=False)
            self.assertEqual(2, result.returncode)
            self.assertEqual(b"", result.stdout)
            self.assertNotIn(b"Traceback", result.stderr)
            self.assertIn(b"validation failed", result.stderr)

    def test_cli_rejects_non_utf8_large_and_recursive_input(self):
        cmd = [
            sys.executable,
            str(ROOT / "scripts/single-writer-rehearsal-evidence.py"),
            "--json",
        ]
        payload = json.dumps(self.envelope())
        for raw in (
            payload.encode("utf-16"),
            payload.encode("utf-32"),
            b"x" * (SINGLE.MAX_STDIN_BYTES + 1),
            ("[" * 2000 + "]" * 2000).encode("utf-8"),
        ):
            result = subprocess.run(
                cmd,
                input=raw,
                capture_output=True,
                check=False,
            )
            self.assertEqual(2, result.returncode)
            self.assertEqual(b"", result.stdout)
            self.assertNotIn(b"Traceback", result.stderr)


if __name__ == "__main__":
    unittest.main()
