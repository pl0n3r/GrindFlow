"""Contracts for redacted GF-ARCH-002 single-writer rehearsal evidence."""
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
    "scripts/single-writer-rehearsal-verifier.py",
    "single_writer_rehearsal_verifier",
)
OPERATOR = load(
    "scripts/operator-evidence-verifier.py",
    "single_writer_operator_evidence",
)
OWNERSHIP = load(
    "scripts/cutover-ownership-plan.py",
    "single_writer_ownership",
)


class SingleWriterRehearsalVerifierTest(unittest.TestCase):
    def ownership_report(self, module="identity"):
        draft = OWNERSHIP.draft_envelope(module)
        return OWNERSHIP.build_report(draft["source"], draft["plan"])

    def operator_receipt(self, evidence_type, module="identity", char="a"):
        report = self.ownership_report(module)
        return {
            "contract": OPERATOR.RECEIPT_CONTRACT,
            "evidence_type": evidence_type,
            "module": module,
            "source_inventory_sha256": report["source_inventory_sha256"],
            "evidence_sha256": char * 64,
            "observed_at_utc": "2026-09-22T12:00:00Z",
            "environment": OPERATOR.EVIDENCE_TYPES[evidence_type],
            "operator_observed": True,
            "contains_row_data": False,
            "contains_secrets": False,
        }

    def operator_input(self, module="identity"):
        return {
            "contract": OPERATOR.INPUT_CONTRACT,
            "module": module,
            "ownership_report": self.ownership_report(module),
            "receipts": {
                evidence_type: self.operator_receipt(evidence_type, module, char)
                for evidence_type, char in zip(
                    OPERATOR.EVIDENCE_TYPES,
                    ("a", "b"),
                    strict=True,
                )
            },
            "production_authorized": False,
        }

    def receipt(self, module="identity"):
        operator_report = OPERATOR.build_report(self.operator_input(module))
        return {
            "contract": SINGLE.RECEIPT_CONTRACT,
            "module": module,
            "source_inventory_sha256": operator_report["source_inventory_sha256"],
            "operator_evidence_bundle_sha256": operator_report[
                "evidence_bundle_sha256"
            ],
            "freeze_evidence_sha256": "c" * 64,
            "observed_at_utc": "2026-09-22T12:15:00Z",
            "window_started_at_utc": "2026-09-22T12:05:00Z",
            "window_ended_at_utc": "2026-09-22T12:10:00Z",
            "environment": SINGLE.EXPECTED_ENVIRONMENT,
            "previous_writer": "laravel",
            "proposed_writer": "symfony",
            "old_writer_writes_blocked": True,
            "new_writer_writes_enabled": True,
            "overlapping_writes_observed": False,
            "operator_observed": True,
            "contains_row_data": False,
            "contains_secrets": False,
        }

    def envelope(self, module="identity"):
        return {
            "contract": SINGLE.INPUT_CONTRACT,
            "module": module,
            "operator_evidence_input": self.operator_input(module),
            "receipt": self.receipt(module),
            "production_authorized": False,
        }

    def assert_rejected(self, pattern, envelope):
        with self.assertRaisesRegex(ValueError, pattern):
            SINGLE.build_report(envelope)

    def test_identity_and_vault_remain_non_authorizing(self):
        for module in ("identity", "vault"):
            report = SINGLE.build_report(self.envelope(module))
            self.assertEqual(module, report["module"])
            self.assertTrue(report["single_writer_rehearsal_verified"])
            self.assertFalse(report["production_ready"])
            self.assertFalse(report["production_authorized"])
            self.assertEqual(
                list(SINGLE.REMAINING_PRECONDITIONS),
                report["remaining_preconditions"],
            )

    def test_receipt_is_bound_to_operator_and_source_evidence(self):
        source_mismatch = self.envelope()
        source_mismatch["receipt"]["source_inventory_sha256"] = "f" * 64
        self.assert_rejected("source inventory", source_mismatch)

        operator_mismatch = self.envelope()
        operator_mismatch["receipt"]["operator_evidence_bundle_sha256"] = "f" * 64
        self.assert_rejected("operator evidence", operator_mismatch)

    def test_writer_transition_is_exact(self):
        previous = self.envelope()
        previous["receipt"]["previous_writer"] = "symfony"
        self.assert_rejected("previous writer", previous)

        proposed = self.envelope()
        proposed["receipt"]["proposed_writer"] = "laravel"
        self.assert_rejected("proposed writer", proposed)

    def test_rehearsal_flags_require_exact_booleans(self):
        cases = (
            ("old_writer_writes_blocked", False),
            ("old_writer_writes_blocked", 1),
            ("new_writer_writes_enabled", False),
            ("new_writer_writes_enabled", 1),
            ("overlapping_writes_observed", True),
            ("overlapping_writes_observed", 0),
            ("operator_observed", 1),
            ("contains_row_data", True),
            ("contains_secrets", True),
        )
        for field, value in cases:
            envelope = self.envelope()
            envelope["receipt"][field] = value
            self.assert_rejected(field, envelope)

    def test_rehearsal_window_is_ordered_and_observed_after_completion(self):
        zero = self.envelope()
        zero["receipt"]["window_ended_at_utc"] = zero["receipt"][
            "window_started_at_utc"
        ]
        self.assert_rejected("positive duration", zero)

        reversed_window = self.envelope()
        reversed_window["receipt"]["window_ended_at_utc"] = "2026-09-22T12:00:00Z"
        self.assert_rejected("positive duration", reversed_window)

        early_observation = self.envelope()
        early_observation["receipt"]["observed_at_utc"] = "2026-09-22T12:06:00Z"
        self.assert_rejected("predate", early_observation)

    def test_timestamps_digest_environment_and_fields_are_strict(self):
        cases = (
            ("freeze_evidence_sha256", "ABC", "digest"),
            ("observed_at_utc", "2026-09-22", "UTC"),
            ("window_started_at_utc", "2026-99-99T12:00:00Z", "invalid"),
            ("environment", "production", "environment"),
        )
        for field, value, pattern in cases:
            envelope = self.envelope()
            envelope["receipt"][field] = value
            self.assert_rejected(pattern, envelope)

        extra = self.envelope()
        extra["receipt"]["url"] = "private"
        self.assert_rejected("fields", extra)

    def test_operator_evidence_is_revalidated_not_trusted(self):
        envelope = self.envelope()
        envelope["operator_evidence_input"]["ownership_report"][
            "source_inventory_sha256"
        ] = "f" * 64
        self.assert_rejected("checked-in migrations", envelope)

    def test_root_contract_and_production_authorization_are_strict(self):
        extra = self.envelope()
        extra["note"] = "private"
        self.assert_rejected("fields", extra)

        wrong_contract = self.envelope()
        wrong_contract["contract"] = "other"
        self.assert_rejected("contract", wrong_contract)

        for value in (True, 0, 1, "false"):
            envelope = self.envelope()
            envelope["production_authorized"] = value
            self.assert_rejected("production", envelope)

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
                    str(ROOT / "scripts/single-writer-rehearsal-verifier.py"),
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
        report = json.loads(result.stdout)
        self.assertFalse(report["production_ready"])
        self.assertFalse(report["production_authorized"])

    def test_cli_rejects_utf16_utf32_large_and_recursive_input(self):
        cmd = [
            sys.executable,
            str(ROOT / "scripts/single-writer-rehearsal-verifier.py"),
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
