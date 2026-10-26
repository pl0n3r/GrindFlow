from __future__ import annotations

from pathlib import Path
import json
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[1]
SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")


def extract_function(source: str, name: str, next_anchor: str) -> str:
    start = source.index(f"{name}() {{")
    end = source.index(next_anchor, start)
    return source[start:end].rstrip()


HTTP_CLASS_FN = extract_function(
    SMOKE,
    "safe_s4_media_readiness_http_class",
    "# Observe only allowlisted media readiness",
)


def classify(status: str) -> str:
    completed = subprocess.run(
        [
            "bash",
            "-c",
            HTTP_CLASS_FN + "\n" + 'safe_s4_media_readiness_http_class "$1"',
            "_",
            status,
        ],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return completed.stdout.strip()


class S4MediaReadinessHttpDiagnosticsTests(unittest.TestCase):
    def test_scheduler_smoke_failure_reason_is_allowlisted(self) -> None:
        start = SMOKE.index("safe_module_http_class() {")
        end = SMOKE.index("\nmodule_failure() {", start)
        helper = SMOKE[start:end]
        cases = {
            "200": "ok",
            "": "unreachable",
            "000": "unreachable",
            "301": "redirect",
            "302": "redirect",
            "401": "unauthorized",
            "403": "forbidden",
            "404": "not_found",
            "500": "server_error",
            "503": "server_error",
            "418": "other_non_200",
            "302\nPASSWORD=leak": "other_non_200",
            "503; echo leak": "other_non_200",
            "200\r\nLocation: /secret": "other_non_200",
        }
        for status, expected in cases.items():
            with self.subTest(status=status):
                completed = subprocess.run(
                    ["bash", "-c", helper + '\nsafe_module_http_class "$1"', "_", status],
                    cwd=ROOT, text=True, capture_output=True, check=False,
                )
                self.assertEqual(completed.returncode, 0, completed.stderr)
                self.assertEqual(completed.stdout.strip(), expected)
                self.assertEqual(completed.stderr, "")
                self.assertNotIn("leak", completed.stdout)
                self.assertNotIn("secret", completed.stdout)
        for sensitive in ("Location:", "cookie", "password", "headers", "--request"):
            self.assertNotIn(sensitive.lower(), helper.lower())

    def test_scheduler_smoke_stays_fail_closed(self) -> None:
        block = SMOKE.split("check_workspace_modules() {", 1)[1].split(
            "# Reduce the authenticated S4 readiness", 1
        )[0]
        self.assertEqual(1, block.count('"$BASE_URL$workspace_path/$module"'))
        self.assertIn('curl_common --cookie "$cookie_jar"', block)
        self.assertIn('if [[ "$status" != "200" ]]; then', block)
        self.assertIn('if ! assert_contains "$module_html" "$marker"; then', block)
        self.assertIn('MODULE_READ_ONLY_DIAGNOSTIC=%s', block)
        self.assertIn('MODULE_READ_ONLY_DIAGNOSTIC=marker_missing', block)
        self.assertEqual(2, block.count('module_failure "$module"; return $?'))
        self.assertLess(
            block.index('if [[ "$status" != "200" ]]'),
            block.index('if ! assert_contains "$module_html" "$marker"; then'),
        )
        self.assertNotIn("--request POST", block)
        self.assertIn('printf \'MODULE_READ_ONLY=%s:ok\\n\' "$module"', block)

    def test_authenticated_non_200_is_classified_with_allowlist(self) -> None:
        cases = {
            "301": "redirect",
            "302": "redirect",
            "307": "redirect",
            "401": "unauthorized",
            "403": "forbidden",
            "404": "not_found",
            "500": "server_error",
            "503": "server_error",
            "418": "other_non_200",
        }
        for status, expected in cases.items():
            with self.subTest(status=status):
                self.assertEqual(expected, classify(status))

        self.assertIn(
            "S4_MEDIA_READINESS_HTTP_CLASS=%s",
            SMOKE,
        )
        self.assertIn(
            'if [[ "$runtime" == "s4" ]]; then',
            SMOKE,
        )
        self.assertIn(
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200",
            SMOKE,
        )

    def test_http_classification_is_secret_free(self) -> None:
        for status in ("302", "401", "403", "404", "503", "418"):
            rendered = classify(status)
            self.assertIn(
                rendered,
                {
                    "redirect",
                    "unauthorized",
                    "forbidden",
                    "not_found",
                    "server_error",
                    "other_non_200",
                },
            )
            self.assertNotIn(status, rendered)

        self.assertNotIn("Location:", HTTP_CLASS_FN)
        self.assertNotIn("cookie", HTTP_CLASS_FN.lower())
        self.assertNotIn("body", HTTP_CLASS_FN.lower())
        self.assertNotIn("password", HTTP_CLASS_FN.lower())
        self.assertNotIn("secret", HTTP_CLASS_FN.lower())

    def test_workflow_publishes_s4_http_class_only_for_post_auth_non_200(self) -> None:
        self.assertIn(
            "S4_MEDIA_READINESS_HTTP_CLASS=(redirect|unauthorized|forbidden|not_found|server_error|other_non_200)",
            WORKFLOW,
        )
        self.assertIn(
            'if [[ "$diagnostic_state" == "http_non_200" && "$s4_auth_state" == "ready" ]]; then',
            WORKFLOW,
        )
        self.assertIn(
            "S4 media readiness HTTP class: `%s`",
            WORKFLOW,
        )
        self.assertIn(
            "S4 media readiness HTTP class: `unavailable`",
            WORKFLOW,
        )
        self.assertNotIn("S4 media readiness Location:", WORKFLOW)
        self.assertNotIn("S4 media readiness body:", WORKFLOW)

    def test_release_is_synchronized_and_suite_is_canonical(self) -> None:
        import re

        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        expected = match.group(1)
        self.assertEqual(expected, PACKAGE["version"])
        self.assertEqual(expected, LOCK["version"])
        self.assertEqual(expected, LOCK["packages"][""]["version"])
        self.assertIn(f"V{expected}", README)
        self.assertIn(
            "tests/test_s4_media_readiness_http_diagnostics.py",
            PACKAGE["scripts"]["test"],
        )

    def test_operational_closeout_requires_exact_main_http_class(self) -> None:
        self.assertIn("Exact deployed SHA:", WORKFLOW)
        self.assertIn("actions/runs/$GITHUB_RUN_ID", WORKFLOW)
        self.assertIn("S4 auth state:", WORKFLOW)
        self.assertIn("S4 media readiness HTTP class:", WORKFLOW)
        self.assertIn("Status: `BLOCKED_TARGET_ENV`", WORKFLOW)
        self.assertIn(
            "No remote response body, filesystem path, cookie, header, hash or secret was published.",
            WORKFLOW,
        )


if __name__ == "__main__":
    unittest.main()
