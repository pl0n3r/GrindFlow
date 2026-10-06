from __future__ import annotations

import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[1]
SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")


def shell_function(name: str, next_name: str) -> str:
    start = SMOKE.index(f"{name}() {{")
    end = SMOKE.index(f"{next_name}() {{", start)
    return SMOKE[start:end].rstrip()


SAFE_HEADER_FN = shell_function("safe_s4_bridge_header_state", "extract_s4_bridge_state")
EXTRACT_BODY_FN = shell_function("extract_s4_bridge_state", "extract_vault_path")
PROBE_FN = shell_function("check_s4_media_web_runtime_readiness", "check_failed_login_session")


def run_probe_fixture(*, status: str, headers: str, body: str) -> str:
    with tempfile.TemporaryDirectory() as temp_dir:
        root = Path(temp_dir)
        body_path = root / "bridge.json"
        headers_path = root / "bridge.headers"
        body_path.write_text(body, encoding="utf-8")
        headers_path.write_text(headers, encoding="utf-8")
        script = textwrap.dedent(
            f"""
            set -euo pipefail
            s4_bridge_body={json.dumps(str(body_path))}
            s4_bridge_headers={json.dumps(str(headers_path))}
            s4_cookie_jar={json.dumps(str(root / "cookie.txt"))}
            s4_login_html={json.dumps(str(root / "login.html"))}
            s4_login_headers={json.dumps(str(root / "login.headers"))}
            s4_login_post_headers={json.dumps(str(root / "login-post.headers"))}
            s4_organizations_html={json.dumps(str(root / "organizations.html"))}
            s4_organizations_headers={json.dumps(str(root / "organizations.headers"))}
            s4_select_headers={json.dumps(str(root / "select.headers"))}
            s4_login_csrf_file={json.dumps(str(root / "csrf.txt"))}
            s4_organization_id_file={json.dumps(str(root / "org.txt"))}
            password_file={json.dumps(str(root / "password.txt"))}
            BASE_URL='https://example.invalid'
            E2E_USER_EMAIL='synthetic@example.invalid'
            FIXTURE_HTTP_STATUS={json.dumps(status)}

            curl_common() {{
              printf '%s' "$FIXTURE_HTTP_STATUS"
            }}

            write_s4_login_csrf() {{ return 99; }}
            extract_s4_organization_id() {{ return 99; }}
            assert_redirect_path() {{ return 99; }}

            {SAFE_HEADER_FN}
            {EXTRACT_BODY_FN}
            {PROBE_FN}

            check_s4_media_web_runtime_readiness
            """
        )
        completed = subprocess.run(
            ["bash", "-c", script],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
        )
        return completed.stdout


def workflow_run_step(name: str, next_name: str) -> str:
    start = WORKFLOW.index(f"      - name: {name}")
    run_start = WORKFLOW.index("        run: |\n", start) + len("        run: |\n")
    end = WORKFLOW.index(f"      - name: {next_name}", run_start)
    return textwrap.dedent(WORKFLOW[run_start:end]).rstrip()


RECONCILE_STEP = workflow_run_step(
    "Reconcile media web-runtime readiness",
    "Upload short-lived smoke diagnostics",
)


def run_reconcile_fixture(log: str) -> tuple[str, str]:
    with tempfile.TemporaryDirectory() as temp_dir:
        root = Path(temp_dir)
        (root / "production-smoke.log").write_text(log, encoding="utf-8")
        evidence = root / "evidence.md"
        summary = root / "summary.md"
        bin_dir = root / "bin"
        bin_dir.mkdir()
        fake_gh = bin_dir / "gh"
        fake_gh.write_text(
            "#!/usr/bin/env bash\n"
            "set -euo pipefail\n"
            "if [[ \"$1\" == issue && \"$2\" == view ]]; then exit 0; fi\n"
            "exit 0\n",
            encoding="utf-8",
        )
        fake_gh.chmod(0o755)

        env = os.environ.copy()
        env.update(
            {
                "PATH": f"{bin_dir}:{env['PATH']}",
                "GH_TOKEN": "fixture-token",
                "MEDIA_READINESS_ISSUE": "307",
                "MEDIA_WEB_RUNTIME_EVIDENCE_PATH": str(evidence),
                "GITHUB_STEP_SUMMARY": str(summary),
                "GITHUB_SHA": "f" * 40,
                "GITHUB_RUN_ID": "424242",
                "GITHUB_SERVER_URL": "https://github.com",
                "GITHUB_REPOSITORY": "pl0n3r/GrindFlow",
                "RUNNER_TEMP": str(root),
            }
        )
        subprocess.run(
            ["bash", "-c", RECONCILE_STEP],
            cwd=root,
            env=env,
            check=True,
            text=True,
            capture_output=True,
        )
        return (
            evidence.read_text(encoding="utf-8"),
            summary.read_text(encoding="utf-8"),
        )


class S4BridgeContractDiagnosticsTests(unittest.TestCase):
    def test_probe_emits_only_allowlisted_status_header_and_body_contract(self) -> None:
        self.assertIn("safe_s4_bridge_header_state()", SMOKE)
        self.assertIn("S4_BRIDGE_HTTP_STATUS=%s", SMOKE)
        self.assertIn("S4_BRIDGE_HEADER_STATE=%s", SMOKE)
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=valid", SMOKE)
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=invalid", SMOKE)
        self.assertNotIn('cat "$s4_bridge_body"', SMOKE)
        self.assertNotIn('cat "$s4_bridge_headers"', SMOKE)
        self.assertNotIn('echo "$s4_bridge_body"', SMOKE)
        self.assertNotIn('echo "$s4_bridge_headers"', SMOKE)

        output = run_probe_fixture(
            status="200",
            headers="HTTP/2 200\r\nX-GrindFlow-S4-State: schema_missing\r\n\r\n",
            body='{"data":{"contract":"s4-bridge-readiness-v1","state":"schema_missing"}}',
        )
        self.assertIn("S4_BRIDGE_HTTP_STATUS=200", output)
        self.assertIn("S4_BRIDGE_HEADER_STATE=schema_missing", output)
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=valid", output)
        self.assertIn("S4_BRIDGE_STATE=schema_missing", output)
        self.assertNotIn('{"data":', output)

    def test_invalid_body_never_promotes_readiness_from_header(self) -> None:
        output = run_probe_fixture(
            status="200",
            headers="HTTP/2 200\r\nX-GrindFlow-S4-State: ready_for_web_probe\r\n\r\n",
            body='{"data":{"contract":"unexpected","state":"ready_for_web_probe"}}',
        )
        self.assertIn("S4_BRIDGE_HTTP_STATUS=200", output)
        self.assertIn("S4_BRIDGE_HEADER_STATE=ready_for_web_probe", output)
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=invalid", output)
        self.assertIn("MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid", output)
        self.assertIn("MEDIA_WEB_RUNTIME_READY=0", output)
        self.assertNotIn("S4_BRIDGE_STATE=ready_for_web_probe", output)

    def test_contract_invalid_comment_is_safe_and_actionable(self) -> None:
        evidence, summary = run_reconcile_fixture(
            "\n".join(
                (
                    "S4_BRIDGE_HTTP_STATUS=200",
                    "S4_BRIDGE_HEADER_STATE=ready_for_web_probe",
                    "S4_BRIDGE_BODY_CONTRACT=invalid",
                    "MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid",
                    "MEDIA_WEB_RUNTIME_READY=0",
                    "",
                )
            )
        )
        for rendered in (evidence, summary):
            self.assertIn("Exact deployed SHA: `" + ("f" * 40) + "`", rendered)
            self.assertIn("actions/runs/424242", rendered)
            self.assertIn("Cause: `contract_invalid`", rendered)
            self.assertIn("S4 bridge HTTP status: `200`", rendered)
            self.assertIn("S4 bridge header state: `ready_for_web_probe`", rendered)
            self.assertIn("S4 bridge body contract: `invalid`", rendered)
            self.assertIn("No remote response body", rendered)
            self.assertNotIn("Remote response body:", rendered)
            self.assertNotIn("S4 bridge headers:", rendered)

    def test_missing_or_invalid_header_remains_unknown_and_fail_closed(self) -> None:
        missing = run_probe_fixture(
            status="503",
            headers="HTTP/2 503\r\n\r\n",
            body="not-json",
        )
        invalid = run_probe_fixture(
            status="503",
            headers="HTTP/2 503\r\nX-GrindFlow-S4-State: definitely-not-allowed\r\n\r\n",
            body="not-json",
        )
        duplicate = run_probe_fixture(
            status="503",
            headers=(
                "HTTP/2 503\r\n"
                "X-GrindFlow-S4-State: schema_missing\r\n"
                "X-GrindFlow-S4-State: ready_for_web_probe\r\n\r\n"
            ),
            body="not-json",
        )
        for output in (missing, invalid, duplicate):
            self.assertIn("S4_BRIDGE_HTTP_STATUS=503", output)
            self.assertIn("S4_BRIDGE_HEADER_STATE=unknown", output)
            self.assertIn("S4_BRIDGE_BODY_CONTRACT=invalid", output)
            self.assertIn("MEDIA_WEB_RUNTIME_READY=0", output)
            self.assertNotIn("S4_BRIDGE_STATE=", output)

    def test_release_identity_is_synchronized_and_suite_is_canonical(self) -> None:
        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertEqual(release, PACKAGE["version"])
        self.assertEqual(release, LOCK["version"])
        self.assertEqual(release, LOCK["packages"][""]["version"])
        self.assertIn(f"V{release}", README)
        self.assertIn("tests/test_s4_bridge_contract_diagnostics.py", PACKAGE["scripts"]["test"])

    def test_operational_closeout_requires_exact_main_diagnostic_evidence(self) -> None:
        cases = {
            "missing": (
                "S4_BRIDGE_HTTP_STATUS=200\n"
                "S4_BRIDGE_HEADER_STATE=ready_for_web_probe\n"
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid\n"
                "MEDIA_WEB_RUNTIME_READY=0\n"
            ),
            "duplicate": (
                "S4_BRIDGE_HTTP_STATUS=200\n"
                "S4_BRIDGE_HEADER_STATE=ready_for_web_probe\n"
                "S4_BRIDGE_HEADER_STATE=schema_missing\n"
                "S4_BRIDGE_BODY_CONTRACT=invalid\n"
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid\n"
                "MEDIA_WEB_RUNTIME_READY=0\n"
            ),
            "out_of_domain": (
                "S4_BRIDGE_HTTP_STATUS=200\n"
                "S4_BRIDGE_HEADER_STATE=definitely-not-allowed\n"
                "S4_BRIDGE_BODY_CONTRACT=invalid\n"
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid\n"
                "MEDIA_WEB_RUNTIME_READY=0\n"
            ),
        }
        for label, log in cases.items():
            with self.subTest(label=label):
                evidence, _ = run_reconcile_fixture(log)
                self.assertIn("Exact deployed SHA: `" + ("f" * 40) + "`", evidence)
                self.assertIn("actions/runs/424242", evidence)
                self.assertIn("Cause: `contract_invalid`", evidence)
                self.assertIn("S4 bridge safe classification: `unavailable`", evidence)
                self.assertNotIn("S4 bridge HTTP status:", evidence)
                self.assertNotIn("S4 bridge header state:", evidence)
                self.assertNotIn("S4 bridge body contract:", evidence)
                self.assertNotIn("definitely-not-allowed", evidence)


if __name__ == "__main__":
    unittest.main()
