from __future__ import annotations

import json
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


HTTP_CLASS_FN = shell_function(
    "safe_s4_media_readiness_http_class",
    "check_media_web_runtime_readiness",
)
MEDIA_FN = shell_function(
    "check_media_web_runtime_readiness",
    "check_s4_media_web_runtime_readiness",
)


def classify(status: str) -> str:
    completed = subprocess.run(
        ["bash", "-c", HTTP_CLASS_FN + "\n" + 'safe_s4_media_readiness_http_class "$1"', "_", status],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return completed.stdout.strip()


def run_non_200_fixture(status: str, remote_body: str) -> str:
    with tempfile.TemporaryDirectory() as temp_dir:
        root = Path(temp_dir)
        media_body = root / "media-readiness.json"
        media_body.write_text(remote_body, encoding="utf-8")
        script = textwrap.dedent(
            f"""
            set -euo pipefail
            cookie_jar={json.dumps(str(root / "laravel-cookie.txt"))}
            s4_cookie_jar={json.dumps(str(root / "s4-cookie.txt"))}
            media_readiness_body={json.dumps(str(media_body))}
            BASE_URL='https://example.invalid'
            curl_common() {{ printf '%s' {json.dumps(status)}; }}
            {HTTP_CLASS_FN}
            {MEDIA_FN}
            check_media_web_runtime_readiness s4
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


class S4MediaReadinessHttpDiagnosticsTests(unittest.TestCase):
    def test_authenticated_non_200_is_classified_with_allowlist(self) -> None:
        expected = {
            "301": "redirect",
            "302": "redirect",
            "307": "redirect",
            "401": "unauthorized",
            "403": "forbidden",
            "404": "not_found",
            "500": "server_error",
            "503": "server_error",
            "204": "other_non_200",
            "418": "other_non_200",
        }
        for status, http_class in expected.items():
            with self.subTest(status=status):
                self.assertEqual(http_class, classify(status))

        output = run_non_200_fixture("503", '{"secret":"do-not-leak"}')
        self.assertEqual(
            [
                "S4_MEDIA_READINESS_HTTP_CLASS=server_error",
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200",
                "MEDIA_WEB_RUNTIME_READY=0",
            ],
            output.strip().splitlines(),
        )

    def test_http_classification_is_secret_free(self) -> None:
        sentinel = "remote-body-secret-do-not-leak"
        output = run_non_200_fixture("403", sentinel)
        self.assertIn("S4_MEDIA_READINESS_HTTP_CLASS=forbidden", output)
        self.assertIn("MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200", output)
        self.assertNotIn(sentinel, output)
        self.assertNotIn("Location:", output)
        self.assertNotIn("Set-Cookie:", output)
        self.assertNotIn("https://example.invalid", output)

        self.assertNotIn("media_readiness_body", HTTP_CLASS_FN)
        self.assertNotIn("headers", HTTP_CLASS_FN.lower())
        self.assertNotIn("cookie", HTTP_CLASS_FN.lower())

    def test_workflow_publishes_s4_http_class_only_for_post_auth_non_200(self) -> None:
        self.assertIn("s4_media_readiness_http_class_marker()", WORKFLOW)
        self.assertIn(
            "S4_MEDIA_READINESS_HTTP_CLASS=(redirect|unauthorized|forbidden|not_found|server_error|other_non_200)",
            WORKFLOW,
        )
        self.assertIn(
            '[[ "$diagnostic_state" == "http_non_200" && "$s4_auth_state" == "ready" ]]',
            WORKFLOW,
        )
        self.assertIn(
            "S4 media readiness HTTP class: `%s`",
            WORKFLOW,
        )
        self.assertIn(
            "No remote response body, filesystem path, cookie, header, hash or secret was published.",
            WORKFLOW,
        )

    def test_release_v0211_is_synchronized_and_suite_is_canonical(self) -> None:
        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        self.assertEqual("0.1.211", match.group(1))
        self.assertEqual("0.1.211", PACKAGE["version"])
        self.assertEqual("0.1.211", LOCK["version"])
        self.assertEqual("0.1.211", LOCK["packages"][""]["version"])
        self.assertIn("V0.1.211", README)
        self.assertIn(
            "tests/test_s4_media_readiness_http_diagnostics.py",
            PACKAGE["scripts"]["test"],
        )

    def test_operational_closeout_requires_exact_main_http_class(self) -> None:
        self.assertIn("S4_AUTH_STATE=ready", SMOKE)
        self.assertIn("S4_MEDIA_READINESS_HTTP_CLASS=", SMOKE)
        self.assertIn("MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200", SMOKE)
        self.assertIn("Exact deployed SHA:", WORKFLOW)
        self.assertIn("S4 auth state:", WORKFLOW)
        self.assertIn("S4 media readiness HTTP class:", WORKFLOW)
        self.assertIn("GITHUB_RUN_ID", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
