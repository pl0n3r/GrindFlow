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


LOGIN_CLASS_FN = shell_function("safe_s4_login_redirect_class", "extract_csrf")
MEDIA_FN = shell_function(
    "check_media_web_runtime_readiness",
    "check_s4_media_web_runtime_readiness",
)
S4_FN = shell_function(
    "check_s4_media_web_runtime_readiness",
    "check_failed_login_session",
)


def classify_redirect(value: str) -> str:
    completed = subprocess.run(
        [
            "bash",
            "-c",
            LOGIN_CLASS_FN + "\n" + "safe_s4_login_redirect_class \"$1\"",
            "_",
            value,
        ],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return completed.stdout.strip()


def run_login_redirect_fixture(safe_path: str) -> str:
    with tempfile.TemporaryDirectory() as temp_dir:
        root = Path(temp_dir)
        media_body = root / "media-readiness.json"
        media_body.write_text(
            json.dumps(
                {
                    "data": {
                        "contract": "media-pilot-readiness-v1",
                        "status": "ready",
                        "checks": {
                            "decoder": "ready",
                            "temporary_storage": "ready",
                            "private_vault": "ready",
                        },
                        "evidence_scope": "web_runtime",
                        "ci_equivalent": False,
                    }
                }
            ),
            encoding="utf-8",
        )
        paths = {
            "s4_bridge_body": root / "bridge.json",
            "s4_bridge_headers": root / "bridge.headers",
            "s4_cookie_jar": root / "s4-cookie.txt",
            "s4_login_html": root / "login.html",
            "s4_login_headers": root / "login.headers",
            "s4_login_post_headers": root / "login-post.headers",
            "s4_organizations_html": root / "organizations.html",
            "s4_organizations_headers": root / "organizations.headers",
            "s4_select_headers": root / "select.headers",
            "s4_login_csrf_file": root / "login-csrf.txt",
            "s4_select_csrf_file": root / "select-csrf.txt",
            "s4_organization_id_file": root / "organization-id.txt",
            "password_file": root / "password.txt",
            "media_readiness_body": media_body,
        }
        assignments = "\n".join(
            f"{name}={json.dumps(str(path))}" for name, path in paths.items()
        )
        script = textwrap.dedent(
            f"""
            set -euo pipefail
            {assignments}
            BASE_URL='https://example.invalid'
            E2E_USER_EMAIL='synthetic@example.invalid'
            cookie_jar="$s4_cookie_jar"
            SAFE_LOGIN_PATH={json.dumps(safe_path)}

            safe_s4_bridge_header_state() {{ printf '%s\\n' 'ready_for_web_probe'; }}
            extract_s4_bridge_state() {{ printf '%s\\n' 'ready_for_web_probe'; }}
            write_s4_login_csrf() {{ printf '%s' token > "$s4_login_csrf_file"; }}
            write_s4_organization_selection() {{
              printf '%s' org > "$s4_organization_id_file"
              printf '%s' token > "$s4_select_csrf_file"
            }}
            safe_s4_redirect_path() {{
              if [[ "$1" == "$s4_login_post_headers" ]]; then
                printf '%s\\n' "$SAFE_LOGIN_PATH"
              else
                printf '%s\\n' '/s4/admin'
              fi
            }}
            {LOGIN_CLASS_FN}
            curl_common() {{
              local rendered=" $* "
              if [[ "$rendered" == *"/s4/_bridge-readiness"* ]]; then
                printf '200'
              elif [[ "$rendered" == *"/s4/api/admin/schedules/media-readiness"* ]]; then
                printf '200'
              elif [[ "$rendered" == *"/s4/organizations/select"* ]]; then
                printf '302'
              elif [[ "$rendered" == *"/s4/organizations"* ]]; then
                printf '200'
              elif [[ "$rendered" == *"/s4/login"* && "$rendered" == *"--request POST"* ]]; then
                printf '302'
              elif [[ "$rendered" == *"/s4/login"* ]]; then
                printf '200'
              else
                printf '500'
              fi
            }}

            {MEDIA_FN}
            {S4_FN}

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


def login_contract_invalid_log(class_lines: str = "") -> str:
    return (
        "S4_BRIDGE_HTTP_STATUS=200\n"
        "S4_BRIDGE_HEADER_STATE=ready_for_web_probe\n"
        "S4_BRIDGE_BODY_CONTRACT=valid\n"
        "S4_BRIDGE_STATE=ready_for_web_probe\n"
        "S4_AUTH_STATE=identity_unavailable\n"
        "S4_POST_BRIDGE_CONTRACT_STAGE=login_redirect\n"
        f"{class_lines}"
        "MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid\n"
        "MEDIA_WEB_RUNTIME_READY=0\n"
    )


class S4LoginRedirectDiagnosticsTests(unittest.TestCase):
    def test_login_redirect_emits_one_safe_class_without_location(self) -> None:
        output = run_login_redirect_fixture("/s4/login")
        self.assertEqual(
            1,
            output.splitlines().count(
                "S4_LOGIN_REDIRECT_CLASS=returned_to_login"
            ),
        )
        self.assertEqual(
            1,
            output.splitlines().count(
                "S4_POST_BRIDGE_CONTRACT_STAGE=login_redirect"
            ),
        )
        self.assertIn("MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid", output)
        self.assertNotIn("/s4/login", output)
        self.assertNotIn("Location:", output)

    def test_redirect_classes_are_deterministic_and_fail_closed(self) -> None:
        cases = {
            "/s4/login": "returned_to_login",
            "/s4/organizations": "organizations",
            "/s4/admin": "admin",
            "(missing)": "missing",
            "(redacted)": "redacted",
            "/outside-allowlist?token=do-not-leak": "redacted",
        }
        for raw, expected in cases.items():
            with self.subTest(raw=raw):
                self.assertEqual(expected, classify_redirect(raw))

        success_output = run_login_redirect_fixture("/s4/organizations")
        self.assertNotIn("S4_LOGIN_REDIRECT_CLASS=", success_output)
        self.assertNotIn("S4_POST_BRIDGE_CONTRACT_STAGE=login_redirect", success_output)

    def test_reconciler_rejects_invalid_redirect_class_markers(self) -> None:
        cases = {
            "missing": "",
            "duplicate": (
                "S4_LOGIN_REDIRECT_CLASS=returned_to_login\n"
                "S4_LOGIN_REDIRECT_CLASS=admin\n"
            ),
            "out_of_domain": "S4_LOGIN_REDIRECT_CLASS=secret_target\n",
        }
        for label, class_lines in cases.items():
            with self.subTest(label=label):
                evidence, summary = run_reconcile_fixture(
                    login_contract_invalid_log(class_lines)
                )
                for rendered in (evidence, summary):
                    self.assertIn(
                        "S4 login redirect class: `unavailable`",
                        rendered,
                    )
                    self.assertNotIn("secret_target", rendered)

    def test_login_redirect_evidence_is_exact_and_secret_free(self) -> None:
        evidence, summary = run_reconcile_fixture(
            login_contract_invalid_log(
                "S4_LOGIN_REDIRECT_CLASS=returned_to_login\n"
            )
        )
        for rendered in (evidence, summary):
            self.assertIn("Exact deployed SHA: `" + ("f" * 40) + "`", rendered)
            self.assertIn("actions/runs/424242", rendered)
            self.assertIn("Cause: `contract_invalid`", rendered)
            self.assertIn(
                "S4 post-bridge contract stage: `login_redirect`",
                rendered,
            )
            self.assertIn(
                "S4 login redirect class: `returned_to_login`",
                rendered,
            )
            self.assertIn("No remote response body", rendered)
            self.assertNotIn("Location:", rendered)
            self.assertNotIn("do-not-leak", rendered)
            self.assertNotIn("password", rendered.lower())

    def test_release_v0209_is_synchronized_and_suite_is_canonical(self) -> None:
        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertGreaterEqual(
            tuple(int(part) for part in release.split(".")),
            (0, 1, 209),
        )
        self.assertEqual(release, PACKAGE["version"])
        self.assertEqual(release, LOCK["version"])
        self.assertEqual(release, LOCK["packages"][""]["version"])
        self.assertIn(f"V{release}", README)
        self.assertIn(
            "tests/test_s4_login_redirect_diagnostics.py",
            PACKAGE["scripts"]["test"],
        )

    def test_operational_closeout_requires_exact_main_redirect_class(self) -> None:
        evidence, _ = run_reconcile_fixture(
            login_contract_invalid_log(
                "S4_LOGIN_REDIRECT_CLASS=returned_to_login\n"
            )
        )
        self.assertIn("Exact deployed SHA: `" + ("f" * 40) + "`", evidence)
        self.assertIn("actions/runs/424242", evidence)
        self.assertIn(
            "S4 login redirect class: `returned_to_login`",
            evidence,
        )
        self.assertIn("Status: `BLOCKED_TARGET_ENV`", evidence)


if __name__ == "__main__":
    unittest.main()
