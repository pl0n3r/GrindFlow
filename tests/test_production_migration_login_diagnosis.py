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
SMOKE_WORKFLOW = ROOT / ".github" / "workflows" / "production-smoke.yml"
RUNBOOK = ROOT / "docs" / "PRODUCTION-SMOKE-AUTH-TRIAGE.md"


class _Fixture(http.server.BaseHTTPRequestHandler):
    login_status = 302
    admin_statuses = [403]
    admin_get_count = 0
    migration_post_count = 0
    cookie_value = "private-cookie-sentinel"
    close_login_without_response = False

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
            index = min(
                type(self).admin_get_count,
                len(type(self).admin_statuses) - 1,
            )
            status = type(self).admin_statuses[index]
            type(self).admin_get_count += 1
            location = "/login" if status in (302, 303) else None
            self._reply(status, location=location)
            return
        self._reply(404)

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        self.rfile.read(length)
        if self.path == "/login":
            if type(self).close_login_without_response:
                self.close_connection = True
                return
            location = "/dashboard" if type(self).login_status in (302, 303) else None
            self._reply(type(self).login_status, location=location)
            return
        if self.path == "/admin/system/migrations":
            type(self).migration_post_count += 1
            self._reply(503)
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

    def _run(
        self,
        *,
        login_status: int,
        admin_status: int = 403,
        admin_statuses: list[int] | None = None,
        close_login_without_response: bool = False,
    ):
        _Fixture.login_status = login_status
        _Fixture.admin_statuses = list(admin_statuses or [admin_status])
        _Fixture.admin_get_count = 0
        _Fixture.migration_post_count = 0
        _Fixture.close_login_without_response = close_login_without_response
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
        login_failure, login_payload, _ = self._run(login_status=422)
        self.assertNotEqual(login_failure.returncode, 0)
        self.assertEqual(login_payload["diagnostic_code"], "login_failed")

        transport_failure, transport_payload, _ = self._run(
            login_status=302,
            close_login_without_response=True,
        )
        self.assertNotEqual(transport_failure.returncode, 0)
        self.assertEqual(transport_payload["diagnostic_code"], "login_request_failed")

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

        admin_unavailable, unavailable_payload, _ = self._run(
            login_status=302,
            admin_status=418,
        )
        self.assertNotEqual(admin_unavailable.returncode, 0)
        self.assertEqual(
            unavailable_payload["diagnostic_code"],
            "admin_system_unavailable",
        )

    def test_result_and_logs_never_contain_credentials_or_cookies(self) -> None:
        result, payload, secret = self._run(login_status=302, admin_status=403)
        retained = result.stdout + result.stderr + json.dumps(payload, sort_keys=True)

        self.assertNotIn(secret, retained)
        self.assertNotIn(_Fixture.cookie_value, retained)
        self.assertNotIn("Set-Cookie", retained)
        self.assertEqual(payload["diagnostic_code"], "admin_system_access_denied")

    def test_pre_migration_admin_system_get_uses_bounded_retries_without_retrying_post(self) -> None:
        result, payload, _ = self._run(
            login_status=302,
            admin_statuses=[503, 503, 403],
        )

        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(payload["diagnostic_code"], "admin_system_access_denied")
        self.assertEqual(3, _Fixture.admin_get_count)
        self.assertEqual(0, _Fixture.migration_post_count)

        script = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("curl_status_read()", script)
        helper = script.split("curl_status_read()", 1)[1].split(
            "extract_login_csrf()", 1
        )[0]
        self.assertIn("--retry 4", helper)
        self.assertIn("--retry-all-errors", helper)
        self.assertNotIn("--fail", helper)

        preflight = script.split('system_status="$(curl_status_read', 1)[1].split(
            'case "$system_status"', 1
        )[0]
        self.assertIn('"$BASE_URL/admin/system"', preflight)

        migration_post = script.split('migration_status="$(curl', 1)[1].split(
            "# Never retry the migration POST", 1
        )[0]
        self.assertIn("--request POST", migration_post)
        self.assertNotIn("--retry", migration_post)
        self.assertNotIn("curl_status_read", migration_post)

    def test_workflow_summary_only_claims_verified_backup_when_server_validated_it(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        summary = workflow.split(
            "- name: Publish safe status to workflow summary",
            1,
        )[1].split("- name: Fail after publishing safe status", 1)[0]

        self.assertIn("E2E_USER_EMAIL: e2e-oidc-smoke@grindflow.test", workflow)
        self.assertIn('result_state="$(python3 - <<\'PY\'', summary)

        credentials_branch = summary.split(
            'if [[ "${{ steps.credentials.outputs.configured }}" != "true" ]]',
            1,
        )[1].split(
            'elif [[ "${{ steps.migrate.outcome }}" != "success" ]]',
            1,
        )[0]
        failed_branch = summary.split(
            'elif [[ "${{ steps.migrate.outcome }}" != "success" ]]',
            1,
        )[1].split('elif [[ "$result_state" == "applied" ]]', 1)[0]
        applied_branch = summary.split(
            'elif [[ "$result_state" == "applied" ]]',
            1,
        )[1].split('elif [[ "$result_state" == "no-op" ]]', 1)[0]
        noop_branch = summary.split(
            'elif [[ "$result_state" == "no-op" ]]',
            1,
        )[1].split("\n          else\n", 1)[0]
        ambiguous_branch = summary.rsplit("\n          else\n", 1)[1].split(
            "\n          fi\n",
            1,
        )[0]

        self.assertIn('backup_state="no ejecutado"', credentials_branch)
        self.assertIn(
            'backup_state="no confirmado (falló el paso de migración)"',
            failed_branch,
        )
        self.assertIn(
            'backup_state="resolved and validated server-side"',
            applied_branch,
        )
        self.assertNotIn("resolved and validated server-side", noop_branch)
        self.assertIn(
            'backup_state="no requerido (sin lote pendiente)"',
            noop_branch,
        )
        self.assertIn(
            'backup_state="no confirmado (evidencia ambigua)"',
            ambiguous_branch,
        )
        self.assertEqual(summary.count("resolved and validated server-side"), 1)
        self.assertIn(
            "printf 'Verified backup evidence: **%s**\\n' \"$backup_state\"",
            summary,
        )

    def test_runbook_documents_safe_synthetic_identity_recovery(self) -> None:
        runbook = RUNBOOK.read_text(encoding="utf-8")
        smoke_workflow = SMOKE_WORKFLOW.read_text(encoding="utf-8")

        self.assertIn(
            "Recuperación segura de la identidad sintética de Production Migration",
            runbook,
        )
        self.assertIn("e2e-oidc-smoke@grindflow.test", runbook)
        self.assertIn("e2e-admin@grindflow.test", runbook)
        self.assertIn(".github/workflows/production-smoke.yml", runbook)
        self.assertIn("Reconcile synthetic smoke identity through GitHub OIDC", runbook)
        self.assertIn("/internal/production-smoke/bootstrap", runbook)
        self.assertIn(
            "Reconcile synthetic smoke identity through GitHub OIDC",
            smoke_workflow,
        )
        self.assertIn("/internal/production-smoke/bootstrap", smoke_workflow)

    def test_workflow_remains_owner_only(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")

        self.assertIn("github.actor == github.repository_owner", workflow)
        self.assertIn("github.triggering_actor == github.repository_owner", workflow)
        self.assertIn("permissions:\n  contents: read", workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertNotIn("issues: write", workflow)
        self.assertNotIn("pull-requests: write", workflow)


if __name__ == "__main__":
    unittest.main()
