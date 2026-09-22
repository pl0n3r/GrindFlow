"""Contracts for redacted GF-ARCH-002 operator evidence."""
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


EVIDENCE = load(
    "scripts/operator-evidence-verifier.py",
    "operator_evidence_verifier",
)
OWNERSHIP = load(
    "scripts/cutover-ownership-plan.py",
    "operator_evidence_ownership",
)


class OperatorEvidenceVerifierTest(unittest.TestCase):
    def ownership_report(self, module="identity"):
        draft = OWNERSHIP.draft_envelope(module)
        return OWNERSHIP.build_report(draft["source"], draft["plan"])

    def receipt(self, evidence_type, module="identity", digest_char="a"):
        report = self.ownership_report(module)
        return {
            "contract": EVIDENCE.RECEIPT_CONTRACT,
            "evidence_type": evidence_type,
            "module": module,
            "source_inventory_sha256": report["source_inventory_sha256"],
            "evidence_sha256": digest_char * 64,
            "observed_at_utc": "2026-09-22T12:34:56Z",
            "environment": EVIDENCE.EVIDENCE_TYPES[evidence_type],
            "operator_observed": True,
            "contains_row_data": False,
            "contains_secrets": False,
        }

    def envelope(self, module="identity"):
        return {
            "contract": EVIDENCE.INPUT_CONTRACT,
            "module": module,
            "ownership_report": self.ownership_report(module),
            "receipts": {
                evidence_type: self.receipt(evidence_type, module, char)
                for evidence_type, char in zip(
                    EVIDENCE.EVIDENCE_TYPES,
                    ("a", "b"),
                    strict=True,
                )
            },
            "production_authorized": False,
        }

    def assert_rejected(self, pattern, envelope):
        with self.assertRaisesRegex(ValueError, pattern):
            EVIDENCE.build_report(envelope)

    def test_identity_and_vault_reports_remain_non_authorizing(self):
        for module in ("identity", "vault"):
            report = EVIDENCE.build_report(self.envelope(module))
            self.assertEqual(module, report["module"])
            self.assertIs(False, report["production_ready"])
            self.assertIs(False, report["production_authorized"])
            self.assertEqual("redacted_references_only", report["scope"])
            self.assertIs(False, report["receipt_content_verified"])
            self.assertEqual(
                list(EVIDENCE.PENDING_PRECONDITIONS),
                report["remaining_preconditions"],
            )
            self.assertRegex(report["evidence_bundle_sha256"], r"^[0-9a-f]{64}$")

    def test_receipts_keep_only_redacted_reference_fields(self):
        report = EVIDENCE.build_report(self.envelope())
        self.assertEqual(
            set(EVIDENCE.EVIDENCE_TYPES),
            set(report["validated_receipt_references"]),
        )
        serialized = json.dumps(report)
        self.assertNotIn("operator_observed", serialized)
        self.assertNotIn("contains_row_data", serialized)
        self.assertNotIn("contains_secrets", serialized)

    def test_production_authorization_requires_exact_false_boolean(self):
        for value in (True, 0, 1, "false"):
            envelope = self.envelope()
            envelope["production_authorized"] = value
            self.assert_rejected("production", envelope)

    def test_receipt_safety_flags_require_exact_booleans(self):
        for field, value in (
            ("operator_observed", 1),
            ("contains_row_data", 0),
            ("contains_secrets", 0),
            ("contains_row_data", True),
            ("contains_secrets", True),
        ):
            envelope = self.envelope()
            envelope["receipts"]["authorized_metadata_inventory"][field] = value
            self.assert_rejected(field, envelope)

    def test_receipt_type_module_and_inventory_must_match(self):
        mismatches = [
            ("evidence_type", "real_backup_restore_rehearsal", "evidence type"),
            ("module", "vault", "module mismatch"),
            ("source_inventory_sha256", "f" * 64, "source inventory"),
            ("environment", "production", "environment"),
        ]
        for field, value, pattern in mismatches:
            envelope = self.envelope()
            envelope["receipts"]["authorized_metadata_inventory"][field] = value
            self.assert_rejected(pattern, envelope)

    def test_receipts_must_reference_distinct_evidence(self):
        envelope = self.envelope()
        digest = envelope["receipts"]["authorized_metadata_inventory"]["evidence_sha256"]
        envelope["receipts"]["real_backup_restore_rehearsal"]["evidence_sha256"] = digest
        self.assert_rejected("distinct evidence", envelope)

    def test_receipt_digest_and_timestamp_are_strict(self):
        for field, value, pattern in (
            ("evidence_sha256", "abc", "digest"),
            ("evidence_sha256", "A" * 64, "digest"),
            ("observed_at_utc", "2026-09-22", "timestamp"),
            ("observed_at_utc", "2026-99-99T99:99:99Z", "timestamp"),
        ):
            envelope = self.envelope()
            envelope["receipts"]["real_backup_restore_rehearsal"][field] = value
            self.assert_rejected(pattern, envelope)

    def test_extra_missing_or_unknown_receipts_fail_closed(self):
        extra = self.envelope()
        extra["receipts"]["authorized_metadata_inventory"]["url"] = "private"
        self.assert_rejected("fields", extra)

        missing = self.envelope()
        missing["receipts"].pop("real_backup_restore_rehearsal")
        self.assert_rejected("exactly", missing)

        unknown = self.envelope()
        receipt = unknown["receipts"].pop("real_backup_restore_rehearsal")
        unknown["receipts"]["owner_authorization"] = receipt
        self.assert_rejected("exactly", unknown)

    def test_forged_ownership_report_is_rejected(self):
        envelope = self.envelope()
        envelope["ownership_report"]["source_inventory_sha256"] = "f" * 64
        self.assert_rejected("checked-in migrations", envelope)

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
                    str(ROOT / "scripts/operator-evidence-verifier.py"),
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
        self.assertIs(False, json.loads(result.stdout)["production_ready"])

    def test_cli_rejects_duplicate_json_keys_at_any_depth(self):
        cmd = [
            sys.executable,
            str(ROOT / "scripts/operator-evidence-verifier.py"),
            "--json",
        ]
        envelope = self.envelope()
        payload = json.dumps(envelope, separators=(",", ":"))
        root_duplicate = payload[:-1] + ',"production_authorized":false}'
        nested = json.dumps(envelope, separators=(",", ":"))
        needle = '"contains_secrets":false'
        nested_duplicate = nested.replace(
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

    def test_cli_rejects_utf16_utf32_large_and_recursive_input(self):
        cmd = [
            sys.executable,
            str(ROOT / "scripts/operator-evidence-verifier.py"),
            "--json",
        ]
        payload = json.dumps(self.envelope())
        for raw in (
            payload.encode("utf-16"),
            payload.encode("utf-32"),
            b"x" * (EVIDENCE.MAX_STDIN_BYTES + 1),
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

    def test_root_contract_and_module_are_strict(self):
        extra = self.envelope()
        extra["operator_note"] = "private"
        self.assert_rejected("fields", extra)

        wrong_contract = self.envelope()
        wrong_contract["contract"] = "other"
        self.assert_rejected("contract", wrong_contract)

        wrong_module = self.envelope()
        wrong_module["module"] = "finance"
        self.assert_rejected("module mismatch", wrong_module)


if __name__ == "__main__":
    unittest.main()
