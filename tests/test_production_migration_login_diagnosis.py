#!/usr/bin/env python3
from __future__ import annotations

import http.server
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "run-production-migrations.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "production-migration.yml"


class _Fixture(http.server.BaseHTTPRequestHandler):
    login_status = 302
    admin_status = 403
    cookie_value = "private-cookie-sentinel"

    def log_message(self, *args: object) -> None:
        pass

    def _reply(self, status: int, body: str = "", *, location: str | None = None) -> None:
        payload = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Set-Cookie", f"session={type(self).cookie_value}; Path=/; HttpOnly")
        if location is not None:
            self.send_header("Location", location)
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self) -> None:
        if self.path == "/login":
            self._reply(
                200,
                '<form><input name="_token" value="' + ("a" * 40) + '"></form>',
            )
            return
        if self.path == "/admin/system":
            location = "/login" if type(self).admin_status in (302, 303) else None
            self._reply(type(self).admin_status, location=location)
            return
        self._reply(404)

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        self.rfile.read(length)
        if self.path == "/login":
            location = "/dashboard" if type(self).login_status in (302, 303) else None
            self._reply(type(self).login_status, location=location)
            return
        self._reply(404)


class ProductionMigrationLoginDiagnosisTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), _Fixture)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=3)

    def _run(self, *, login_status: int, admin_status: int):
        _Fixture.login_status = login_status
        _Fixture.admin_status = admin_status
        secret = "production-password-sentinel"
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / "result.json"
            env = os.environ.copy()
            env.update(
                {
                    "BASE_URL": f"http://127.0.0.1:{self.server.server_address[1]}",
                    "E2E_USER_PASSWORD": secret,
                    "EXPECTED_PENDING": "3",
                    "OUTPUT_PATH": str(output),
                }
            )
            result = subprocess.run(
                ["bash", str(SCRIPT)],
                cwd=ROOT,
                env=env,
                capture_output=True,
                text=True,
                timeout=20,
                check=False,
            )
            payload = json.loads(output.read_text(encoding="utf-8"))
            return result, payload, secret

    def test_script_distinguishes_login_failure_from_admin_system_access_denied(self) -> None:
        login_failure, login_payload, _ = self._run(login_status=422, admin_status=403)
        self.assertNotEqual(login_failure.returncode, 0)
        self.assertEqual(login_payload["diagnostic_code"], "login_failed")

        access_denied, access_payload, _ = self._run(login_status=302, admin_status=403)
        self.assertNotEqual(access_denied.returncode, 0)
        self.assertEqual(access_payload["diagnostic_code"], "admin_system_access_denied")
        self.assertNotEqual(login_payload["message"], access_payload["message"])

        session_rejected, session_payload, _ = self._run(login_status=302, admin_status=302)
        self.assertNotEqual(session_rejected.returncode, 0)
        self.assertEqual(
            session_payload["diagnostic_code"],
            "login_session_not_persisted",
        )

    def test_result_and_logs_never_contain_credentials_or_cookies(self) -> None:
        result, payload, secret = self._run(login_status=302, admin_status=403)
        retained = result.stdout + result.stderr + json.dumps(payload, sort_keys=True)

        self.assertNotIn(secret, retained)
        self.assertNotIn(_Fixture.cookie_value, retained)
        self.assertNotIn("Set-Cookie", retained)
        self.assertEqual(payload["diagnostic_code"], "admin_system_access_denied")

    def test_workflow_summary_only_claims_verified_backup_when_server_validated_it(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        summary = workflow.split(
            "- name: Publish safe status to workflow summary",
            1,
        )[1].split("- name: Fail after publishing safe status", 1)[0]

        self.assertIn("E2E_USER_EMAIL: e2e-oidc-smoke@grindflow.test", workflow)
        self.assertIn('backup_state="resolved and validated server-side"', summary)
        self.assertGreaterEqual(summary.count('backup_state="no ejecutado"'), 2)
        self.assertEqual(
            summary.count("resolved and validated server-side"),
            1,
        )
        self.assertIn(
            "printf 'Verified backup evidence: **%s**\\n' \"$backup_state\"",
            summary,
        )
        script = SCRIPT.read_text(encoding="utf-8")
        self.assertIn(
            'E2E_USER_EMAIL="${E2E_USER_EMAIL:-e2e-oidc-smoke@grindflow.test}"',
            script,
        )


if __name__ == "__main__":
    unittest.main()
