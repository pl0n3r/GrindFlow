"""Contracts for disposable GF-ARCH-002 rehearsal evidence."""
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
    "scripts/disposable-rehearsal-evidence.py",
    "disposable_rehearsal_evidence",
)


class DisposableRehearsalEvidenceTest(unittest.TestCase):
    HEAD = "a" * 40
    RUN_ID = "123456"

    def gate_results(self):
        return {
            "head_sha": self.HEAD,
            "run_id": self.RUN_ID,
            "checks": dict.fromkeys(EVIDENCE.CHECKS, "passed"),
        }

    def envelope(self, module="identity"):
        return EVIDENCE.draft_envelope(
            module,
            self.HEAD,
            self.RUN_ID,
            self.gate_results()["checks"],
        )

    def assert_rejected(self, pattern, envelope):
        with self.assertRaisesRegex(ValueError, pattern):
            EVIDENCE.build_report(envelope)

    def test_identity_report_is_disposable_and_never_authorizes_production(self):
        report = EVIDENCE.build_report(self.envelope())
        self.assertEqual(EVIDENCE.REPORT_CONTRACT, report["contract"])
        self.assertEqual("identity", report["module"])
        self.assertEqual("ci_disposable_only", report["scope"])
        self.assertTrue(report["ci"]["disposable"])
        self.assertTrue(report["disposable_evidence"])
        self.assertFalse(report["production_ready"])
        self.assertFalse(report["production_authorized"])
        self.assertEqual(list(EVIDENCE.CHECKS), report["passed_checks"])
        self.assertEqual(
            list(EVIDENCE.EXTERNAL_PRECONDITIONS),
            report["external_preconditions_pending"],
        )
        self.assertRegex(report["evidence_sha256"], r"^[0-9a-f]{64}$")

    def test_vault_template_round_trip(self):
        envelope = self.envelope("vault")
        report = EVIDENCE.build_report(envelope)
        self.assertEqual("vault", report["module"])
        self.assertEqual(
            envelope["ownership_report"]["source_inventory_sha256"],
            report["source_inventory_sha256"],
        )

    def test_input_digest_is_canonical_across_root_key_order(self):
        envelope = self.envelope()
        first = EVIDENCE.build_report(envelope)
        reordered = dict(reversed(list(envelope.items())))
        second = EVIDENCE.build_report(reordered)
        self.assertEqual(first["evidence_sha256"], second["evidence_sha256"])

    def test_rejects_production_authorization(self):
        envelope = self.envelope()
        envelope["production_authorized"] = True
        self.assert_rejected("cannot authorize production", envelope)

    def test_rejects_integer_boolean_substitution(self):
        envelope = self.envelope()
        envelope["production_authorized"] = 0
        self.assert_rejected("cannot authorize production", envelope)
        envelope = self.envelope()
        envelope["ci"]["disposable"] = 1
        self.assert_rejected("disposable CI", envelope)

    def test_rejects_non_github_or_invalid_ci_provenance(self):
        envelope = self.envelope()
        envelope["ci"]["provider"] = "local"
        self.assert_rejected("CI provider", envelope)

        envelope = self.envelope()
        envelope["ci"]["head_sha"] = "main"
        self.assert_rejected("head SHA", envelope)

        envelope = self.envelope()
        envelope["ci"]["run_id"] = "0"
        self.assert_rejected("run id", envelope)

    def test_requires_every_disposable_check_to_pass(self):
        for name in EVIDENCE.CHECKS:
            envelope = self.envelope()
            envelope["checks"][name] = "failed"
            self.assert_rejected("must have passed", envelope)

    def test_rejects_missing_or_extra_check(self):
        envelope = self.envelope()
        envelope["checks"].pop(EVIDENCE.CHECKS[0])
        self.assert_rejected("exact disposable contract", envelope)

        envelope = self.envelope()
        envelope["checks"]["production_smoke"] = "passed"
        self.assert_rejected("exact disposable contract", envelope)

    def test_rejects_ownership_report_that_claims_cutover_or_db_contact(self):
        envelope = self.envelope()
        envelope["ownership_report"]["cutover_authorized"] = True
        self.assert_rejected("source-only and unauthorized", envelope)

        envelope = self.envelope()
        envelope["ownership_report"]["database_contacted"] = True
        self.assert_rejected("source-only and unauthorized", envelope)

    def test_rejects_modified_ownership_contract_or_digest(self):
        envelope = self.envelope()
        envelope["ownership_report"]["contract"] = "other"
        self.assert_rejected("ownership report contract", envelope)

        envelope = self.envelope()
        envelope["ownership_report"]["source_inventory_sha256"] = "x"
        self.assert_rejected("inventory digest", envelope)

    def test_rejects_forged_but_well_shaped_ownership_report(self):
        envelope = self.envelope()
        envelope["ownership_report"]["next_action"] = "pretend this is approved"
        self.assert_rejected("checked-in migrations", envelope)

        envelope = self.envelope()
        envelope["ownership_report"]["outside_this_proposal"]["laravel"].append(
            "invented_table"
        )
        self.assert_rejected("checked-in migrations", envelope)

    def test_rejects_removed_pending_preconditions(self):
        envelope = self.envelope()
        envelope["ownership_report"]["pending_preconditions"] = []
        self.assert_rejected("retain pending preconditions", envelope)

    def test_rejects_extra_root_and_ci_fields(self):
        envelope = self.envelope()
        envelope["database_url"] = "secret"
        self.assert_rejected("envelope fields", envelope)

        envelope = self.envelope()
        envelope["ci"]["token"] = "secret"
        self.assert_rejected("ci provenance", envelope)

    def test_report_does_not_copy_detailed_ownership_payload(self):
        envelope = self.envelope()
        report = EVIDENCE.build_report(envelope)
        self.assertNotIn("table_ownership", report)
        self.assertNotIn("outside_this_proposal", report)
        self.assertNotIn("pending_preconditions", report)
        self.assertIn("next_action", report)

    def test_cli_template_requires_ci_identifiers_and_gate_results(self):
        cmd = [
            sys.executable,
            str(ROOT / "scripts/disposable-rehearsal-evidence.py"),
            "--template",
            "identity",
        ]
        result = subprocess.run(
            cmd,
            text=True,
            encoding="utf-8",
            capture_output=True,
            check=False,
        )
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)

    def test_gate_results_must_match_same_ci_run(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "gates.json"
            payload = self.gate_results()
            payload["run_id"] = "999999"
            path.write_text(json.dumps(payload), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "do not match"):
                EVIDENCE.load_gate_results(str(path), self.HEAD, self.RUN_ID)

    def test_gate_results_require_all_explicit_passes(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "gates.json"
            payload = self.gate_results()
            payload["checks"][EVIDENCE.CHECKS[0]] = "pending"
            path.write_text(json.dumps(payload), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "must have passed"):
                EVIDENCE.load_gate_results(str(path), self.HEAD, self.RUN_ID)

    def test_cli_template_round_trip(self):
        script = str(ROOT / "scripts/disposable-rehearsal-evidence.py")
        with tempfile.TemporaryDirectory() as directory:
            gate_results = Path(directory) / "gates.json"
            gate_results.write_text(
                json.dumps(self.gate_results()),
                encoding="utf-8",
            )
            generated = subprocess.run(
                [
                    sys.executable,
                    script,
                    "--template",
                    "vault",
                    "--head-sha",
                    self.HEAD,
                    "--run-id",
                    self.RUN_ID,
                    "--gate-results",
                    str(gate_results),
                ],
                text=True,
                encoding="utf-8",
                capture_output=True,
                check=False,
            )
        self.assertEqual(0, generated.returncode, generated.stderr)
        checked = subprocess.run(
            [sys.executable, script, "--json"],
            input=generated.stdout,
            text=True,
            encoding="utf-8",
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, checked.returncode, checked.stderr)
        report = json.loads(checked.stdout)
        self.assertTrue(report["ci"]["disposable"])
        self.assertFalse(report["production_ready"])
        self.assertFalse(report["production_authorized"])

    def test_cli_is_offline_under_audit_barrier(self):
        script = str(ROOT / "scripts/disposable-rehearsal-evidence.py")
        payload = json.dumps(self.envelope())
        with tempfile.TemporaryDirectory() as directory:
            guard = Path(directory) / "sitecustomize.py"
            guard.write_text(
                "import sys\n"
                "def _block_external(event, args):\n"
                "    blocked = {\n"
                "        'socket.connect', 'subprocess.Popen', 'os.system',\n"
                "        'os.posix_spawn', 'os.posix_spawnp',\n"
                "    }\n"
                "    if event in blocked or event.startswith('os.spawn'):\n"
                "        raise RuntimeError('external contact blocked by audit hook')\n"
                "sys.addaudithook(_block_external)\n",
                encoding="utf-8",
            )
            env = dict(os.environ)
            env["PYTHONPATH"] = (
                str(directory)
                + (os.pathsep + env["PYTHONPATH"] if env.get("PYTHONPATH") else "")
            )
            result = subprocess.run(
                [sys.executable, script, "--json"],
                input=payload,
                text=True,
                encoding="utf-8",
                capture_output=True,
                env=env,
                check=False,
            )
        self.assertEqual(0, result.returncode, result.stderr)
        report = json.loads(result.stdout)
        self.assertFalse(report["production_ready"])
        self.assertFalse(report["production_authorized"])

    def test_cli_does_not_echo_malformed_or_secret_input(self):
        script = str(ROOT / "scripts/disposable-rehearsal-evidence.py")
        secret = "do-not-echo-rehearsal-secret"
        result = subprocess.run(
            [sys.executable, script, "--json"],
            input='{"secret":"' + secret + '"}',
            text=True,
            encoding="utf-8",
            capture_output=True,
            check=False,
        )
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertNotIn(secret, result.stderr)

    def test_cli_byte_limit_is_deterministic(self):
        script = str(ROOT / "scripts/disposable-rehearsal-evidence.py")
        payload = "á" * ((EVIDENCE.MAX_STDIN_BYTES // 2) + 1)
        result = subprocess.run(
            [sys.executable, script],
            input=payload,
            text=True,
            encoding="utf-8",
            capture_output=True,
            check=False,
        )
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)

    def test_cli_rejects_utf16_and_utf32_json(self):
        script = str(ROOT / "scripts/disposable-rehearsal-evidence.py")
        payload = json.dumps(self.envelope())
        for encoding in ("utf-16", "utf-32"):
            result = subprocess.run(
                [sys.executable, script, "--json"],
                input=payload.encode(encoding),
                capture_output=True,
                check=False,
            )
            self.assertEqual(2, result.returncode)
            self.assertEqual(b"", result.stdout)
            self.assertIn(
                b"disposable rehearsal evidence validation failed",
                result.stderr,
            )


if __name__ == "__main__":
    unittest.main()
