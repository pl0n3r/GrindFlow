"""Safe production smoke incident summaries, without exposing remote response content."""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/production-smoke-auth-triage.py"
spec = importlib.util.spec_from_file_location("gf_smoke_triage", SCRIPT)
if spec is None or spec.loader is None:
    raise RuntimeError("smoke triage module unavailable")
TRIAGE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(TRIAGE)


class ProductionSmokeAuthTriageTest(unittest.TestCase):
    def run_cli(self, log: str, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SCRIPT), *args],
            input=log,
            capture_output=True,
            text=True,
            encoding="utf-8",
            check=False,
        )

    def test_rejected_login_with_stable_anonymous_session(self):
        log = "\n".join([
            "LOGIN_SESSION_PREFLIGHT=consistent",
            "LOGIN_REDIRECT_PATH=/login",
            "LOGIN_FAILURE_SESSION_CHECK=stable",
        ])
        result = self.run_cli(log)
        self.assertEqual(0, result.returncode, result.stderr)
        payload = json.loads(result.stdout)
        self.assertEqual("login_rejected_anonymous_session_stable", payload["diagnosis"])
        self.assertEqual("stable", payload["recheck"])

    def test_rejected_login_with_changed_or_unavailable_recheck(self):
        base = "LOGIN_SESSION_PREFLIGHT=consistent\nLOGIN_REDIRECT_PATH=/login\n"
        for recheck, diagnosis in (
            ("changed", "login_rejected_anonymous_session_changed"),
            ("unavailable", "login_rejected_recheck_unavailable"),
        ):
            log = base + "LOGIN_FAILURE_SESSION_CHECK=" + recheck
            self.assertEqual(diagnosis, TRIAGE.classify(log)["diagnosis"])
        self.assertEqual(
            "login_rejected_recheck_unavailable",
            TRIAGE.classify(base)["diagnosis"],
        )

    def test_preflight_failure_does_not_claim_bad_password(self):
        result = self.run_cli("LOGIN_SESSION_PREFLIGHT=inconsistent", "--markdown")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("antes del POST", result.stdout)
        self.assertNotIn("contraseña incorrecta", result.stdout)

    def test_dashboard_authentication_redirect_is_separate(self):
        log = "\n".join([
            "LOGIN_SESSION_PREFLIGHT=consistent",
            "LOGIN_REDIRECT_PATH=/dashboard",
            "ERROR: authenticated dashboard returned HTTP 302, redirect path /login; "
            "check authentication/session. No repeated login attempts.",
        ])
        self.assertEqual(
            "dashboard_authentication_redirect",
            TRIAGE.classify(log)["diagnosis"],
        )

    def test_dashboard_and_login_errors_are_mutually_exclusive(self):
        """Contaminated logs with incompatible outcomes must fail without leaking input."""
        log = "\n".join([
            "LOGIN_SESSION_PREFLIGHT=consistent",
            "LOGIN_REDIRECT_PATH=/dashboard",
            "ERROR: authenticated dashboard returned HTTP 302, redirect path /login; "
            "check authentication/session. No repeated login attempts.",
            "ERROR: login returned HTTP 429; stop authentication retries.",
        ])
        result = self.run_cli(log, "--markdown")
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertEqual("ERROR: smoke auth summary unavailable\n", result.stderr)

    def test_login_http_rejection_is_reported_without_echo(self):
        log = "\n".join([
            "LOGIN_SESSION_PREFLIGHT=consistent",
            "ERROR: login returned HTTP 429; stop authentication retries.",
        ])
        self.assertEqual("login_http_rejected", TRIAGE.classify(log)["diagnosis"])

    def test_non_auth_smoke_failure_does_not_claim_login_failure(self):
        log = "MIGRATIONS_PENDING=3\nVAULT_READ_ONLY=failed\n"
        result = self.run_cli(log)
        self.assertEqual("not_classified", json.loads(result.stdout)["diagnosis"])

    def test_repeat_identical_signals_are_idempotent(self):
        value = "LOGIN_REDIRECT_PATH=/login\n"
        self.assertEqual("/login", TRIAGE.classify(value * 15)["login_redirect"])

    def test_conflicting_signals_fail_closed(self):
        log = "LOGIN_SESSION_PREFLIGHT=consistent\nLOGIN_SESSION_PREFLIGHT=inconsistent"
        result = self.run_cli(log)
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertEqual(
            "ERROR: smoke auth summary unavailable\n",
            result.stderr,
        )

    def test_invalid_signal_values_fail_closed_without_leak(self):
        secret = "fake-cookie-not-for-output"
        result = self.run_cli("LOGIN_REDIRECT_PATH=/login?" + secret, "--markdown")
        self.assertEqual(2, result.returncode)
        self.assertNotIn(secret, result.stdout + result.stderr)

    def test_only_allowlisted_signal_labels_leave_log(self):
        secret = "fake-sensitive-token-not-for-output"
        log = "\n".join([
            "LOGIN_SESSION_PREFLIGHT=consistent",
            "LOGIN_REDIRECT_PATH=/login",
            "LOGIN_FAILURE_SESSION_CHECK=stable",
            "Set-Cookie: laravel_session=" + secret,
            "password=" + secret,
            "HTML BODY: " + secret,
            "https://other.example.test/private?" + secret,
        ])
        result = self.run_cli(log, "--markdown")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn(secret, result.stdout + result.stderr)
        self.assertNotIn("Set-Cookie", result.stdout)
        self.assertNotIn("other.example.test", result.stdout)
        self.assertIn("El POST volvió a /login", result.stdout)

    def test_utf8_and_size_rejections_do_not_echo_payload(self):
        result = self.run_cli("á" * ((TRIAGE.MAX_BYTES // 2) + 1))
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertEqual(
            "ERROR: smoke auth summary unavailable\n",
            result.stderr,
        )
        invalid = subprocess.run(
            [sys.executable, str(SCRIPT)],
            input=b"\xfffake-secret",
            capture_output=True,
            check=False,
        )
        self.assertEqual(2, invalid.returncode)
        self.assertNotIn(b"fake-secret", invalid.stdout + invalid.stderr)


if __name__ == "__main__":
    unittest.main()
