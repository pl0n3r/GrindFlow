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
        """Execute the classifier with synthetic input for contract tests."""
        return subprocess.run(
            [sys.executable, str(SCRIPT), *args],
            input=log,
            capture_output=True,
            text=True,
            encoding="utf-8",
            check=False,
        )

    def test_rejected_login_with_stable_anonymous_session(self):
        """Identify a rejected login with a stable anonymous session."""
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
        """Keep changed and unavailable session checks distinct."""
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
        """Avoid attributing a preflight failure to credentials."""
        result = self.run_cli("LOGIN_SESSION_PREFLIGHT=inconsistent", "--markdown")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("antes del POST", result.stdout)
        self.assertNotIn("contraseña incorrecta", result.stdout)

    def test_dashboard_authentication_redirect_is_separate(self):
        """Report a redirect after apparent login separately."""
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

    def test_dashboard_error_requires_a_dashboard_login_redirect(self):
        """Reject incompatible login paths without exposing a contaminated log."""
        dashboard_error = (
            "ERROR: authenticated dashboard returned HTTP 302, redirect path /login; "
            "check authentication/session. No repeated login attempts."
        )
        for redirect in ("/login", "/admin", "(missing)"):
            with self.subTest(redirect=redirect):
                log = "\n".join([
                    "LOGIN_SESSION_PREFLIGHT=consistent",
                    "LOGIN_REDIRECT_PATH=" + redirect,
                    "LOGIN_FAILURE_SESSION_CHECK=stable" if redirect == "/login" else "",
                    dashboard_error,
                ])
                result = self.run_cli(log, "--markdown")
                self.assertEqual(2, result.returncode)
                self.assertEqual("", result.stdout)
                self.assertEqual(
                    "ERROR: smoke auth summary unavailable\n", result.stderr,
                )

    def test_unexpected_login_redirect_is_not_a_normal_login_rejection(self):
        """A 301 back to login is a distinct HTTP outcome, never a normal POST."""
        log = "\n".join([
            "LOGIN_SESSION_PREFLIGHT=consistent",
            "LOGIN_REDIRECT_PATH=/login",
            "ERROR: login returned HTTP 301; stop authentication retries on unexpected redirect.",
        ])
        self.assertEqual("login_http_rejected", TRIAGE.classify(log)["diagnosis"])

    def test_outcome_signals_from_different_smoke_stages_fail_closed(self):
        """A recheck cannot belong to a dashboard or preflight failure."""
        cases = [
            "\n".join([
                "LOGIN_SESSION_PREFLIGHT=consistent",
                "LOGIN_REDIRECT_PATH=/dashboard",
                "LOGIN_FAILURE_SESSION_CHECK=stable",
            ]),
            "\n".join([
                "LOGIN_SESSION_PREFLIGHT=inconsistent",
                "LOGIN_REDIRECT_PATH=/login",
            ]),
            "\n".join([
                "LOGIN_SESSION_PREFLIGHT=consistent",
                "LOGIN_REDIRECT_PATH=/dashboard",
                "ERROR: authenticated dashboard returned HTTP 302, redirect path /login; "
                "check authentication/session. No repeated login attempts.",
                "LOGIN_FAILURE_SESSION_CHECK=changed",
            ]),
            "\n".join([
                "LOGIN_SESSION_PREFLIGHT=consistent",
                "LOGIN_REDIRECT_PATH=/login",
                "LOGIN_FAILURE_SESSION_CHECK=stable",
                "ERROR: login returned HTTP 301; stop authentication retries on unexpected redirect.",
            ]),
            "\n".join([
                "LOGIN_SESSION_PREFLIGHT=consistent",
                "LOGIN_REDIRECT_PATH=/login",
                "ERROR: login returned HTTP 401; stop authentication retries.",
            ]),
        ]
        for log in cases:
            with self.subTest(log=log.splitlines()[-1]):
                result = self.run_cli(log, "--markdown")
                self.assertEqual(2, result.returncode)
                self.assertEqual("", result.stdout)
                self.assertEqual(
                    "ERROR: smoke auth summary unavailable\n", result.stderr,
                )

    def test_login_http_rejection_is_reported_without_echo(self):
        """Report HTTP rejection without echoing the log."""
        log = "\n".join([
            "LOGIN_SESSION_PREFLIGHT=consistent",
            "ERROR: login returned HTTP 429; stop authentication retries.",
        ])
        self.assertEqual("login_http_rejected", TRIAGE.classify(log)["diagnosis"])

    def test_non_auth_smoke_failure_does_not_claim_login_failure(self):
        """Do not infer login failure from unrelated smoke signals."""
        log = "MIGRATIONS_PENDING=3\nVAULT_READ_ONLY=failed\n"
        result = self.run_cli(log)
        self.assertEqual("not_classified", json.loads(result.stdout)["diagnosis"])

    def test_repeat_identical_signals_are_idempotent(self):
        """Accept duplicate telemetry only when values agree."""
        value = "LOGIN_REDIRECT_PATH=/login\n"
        self.assertEqual("/login", TRIAGE.classify(value * 15)["login_redirect"])

    def test_conflicting_signals_fail_closed(self):
        """Reject contradictory telemetry without output."""
        log = "LOGIN_SESSION_PREFLIGHT=consistent\nLOGIN_SESSION_PREFLIGHT=inconsistent"
        result = self.run_cli(log)
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertEqual(
            "ERROR: smoke auth summary unavailable\n",
            result.stderr,
        )

    def test_invalid_signal_values_fail_closed_without_leak(self):
        """Reject unexpected values without exposing their content."""
        secret = "fake-cookie-not-for-output"
        result = self.run_cli("LOGIN_REDIRECT_PATH=/login?" + secret, "--markdown")
        self.assertEqual(2, result.returncode)
        self.assertNotIn(secret, result.stdout + result.stderr)

    def test_only_allowlisted_signal_labels_leave_log(self):
        """Ensure private log values never reach the issue summary."""
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
        """Bound input and reject invalid encoding without disclosure."""
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
