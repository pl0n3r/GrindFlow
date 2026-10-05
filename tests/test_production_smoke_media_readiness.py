from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[1]
SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
README = (ROOT / "README.md").read_text(encoding="utf-8")


class ProductionSmokeMediaReadinessTests(unittest.TestCase):
    def test_smoke_reuses_authenticated_cookie_and_queries_only_web_runtime_readiness_endpoint(self) -> None:
        self.assertEqual(1, SMOKE.count("$BASE_URL/api/admin/schedules/media-readiness"))
        block = self._readiness_function()
        self.assertIn('curl_common --cookie "$cookie_jar" --output "$media_readiness_body"', block)
        self.assertNotIn("GRINDFLOW_SMOKE_PASSWORD", block)
        self.assertNotIn("Authorization:", block)

    def test_readiness_requires_closed_allowlisted_ready_contract_and_emits_only_safe_marker(self) -> None:
        block = self._readiness_function()
        for token in (
            '"contract": "media-pilot-readiness-v1"',
            '"evidence_scope": "web_runtime"',
            '"decoder", "temporary_storage", "private_vault"',
            "MEDIA_WEB_RUNTIME_CHECK_decoder=",
            "MEDIA_WEB_RUNTIME_CHECK_temporary_storage=",
            "MEDIA_WEB_RUNTIME_CHECK_private_vault=",
            "MEDIA_WEB_RUNTIME_READY=1",
            "MEDIA_WEB_RUNTIME_READY=0",
        ):
            self.assertIn(token, block)
        self.assertIn('set(payload) != {"data"}', block)
        self.assertIn('data.get("ci_equivalent") is not False', block)
        self.assertIn("set(data) != {", block)
        self.assertIn("set(checks) != {", block)

    def test_ci_equivalent_requires_json_boolean_false_strictly(self) -> None:
        parser = self._readiness_parser()
        base = self._payload(
            decoder="ready",
            temporary_storage="ready",
            private_vault="ready",
        )

        for value, expected_code in ((False, 0), (0, 1), (None, 1), ("false", 1)):
            with self.subTest(value=value):
                payload = json.loads(json.dumps(base))
                payload["data"]["ci_equivalent"] = value
                result = self._run_parser(parser, payload)
                self.assertEqual(expected_code, result.returncode, result.stderr)

    def test_valid_contract_emits_allowlisted_subcheck_markers(self) -> None:
        parser = self._readiness_parser()
        cases = (
            (
                self._payload(
                    decoder="ready",
                    temporary_storage="ready",
                    private_vault="ready",
                ),
                (
                    "MEDIA_WEB_RUNTIME_CHECK_decoder=ready",
                    "MEDIA_WEB_RUNTIME_CHECK_temporary_storage=ready",
                    "MEDIA_WEB_RUNTIME_CHECK_private_vault=ready",
                    "MEDIA_WEB_RUNTIME_READY=1",
                ),
            ),
            (
                self._payload(
                    decoder="not_ready",
                    temporary_storage="ready",
                    private_vault="not_ready",
                ),
                (
                    "MEDIA_WEB_RUNTIME_CHECK_decoder=not_ready",
                    "MEDIA_WEB_RUNTIME_CHECK_temporary_storage=ready",
                    "MEDIA_WEB_RUNTIME_CHECK_private_vault=not_ready",
                    "MEDIA_WEB_RUNTIME_READY=0",
                ),
            ),
        )
        for payload, expected_lines in cases:
            with self.subTest(payload=payload):
                result = self._run_parser(parser, payload)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(list(expected_lines), result.stdout.splitlines())

    def test_invalid_contract_never_emits_remote_or_arbitrary_diagnostics(self) -> None:
        parser = self._readiness_parser()
        cases = (
            {"data": {"path": "/srv/private/vault"}},
            self._payload(
                decoder="ready",
                temporary_storage="unexpected",
                private_vault="ready",
            ),
            self._payload(
                decoder="ready",
                temporary_storage="ready",
                private_vault="ready",
                status="not_ready",
            ),
        )
        for payload in cases:
            with self.subTest(payload=payload):
                result = self._run_parser(parser, payload)
                self.assertNotEqual(0, result.returncode)
                self.assertEqual("", result.stdout)

        block = self._readiness_function()
        self.assertIn('if [[ "$status" != "200" ]]', block)
        self.assertIn("json.JSONDecodeError", block)
        self.assertNotIn('cat "$media_readiness_body"', SMOKE)
        self.assertNotIn("facebook", block.lower())
        self.assertNotIn("publish", block.lower())
        self.assertNotIn("--request POST", block)

    def test_blocked_summary_reports_only_allowlisted_subchecks(self) -> None:
        script = self._reconcile_script()
        smoke_log = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_CHECK_decoder=not_ready",
                "MEDIA_WEB_RUNTIME_CHECK_temporary_storage=ready",
                "MEDIA_WEB_RUNTIME_CHECK_private_vault=ready",
                "MEDIA_WEB_RUNTIME_READY=0",
                "REMOTE_BODY=/srv/private/should-not-leak",
                "",
            )
        )
        completed, summary, calls = self._run_reconcile(script, smoke_log)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertNotIn("issue close 307", calls)
        text = summary.read_text(encoding="utf-8")
        self.assertIn("BLOCKED_TARGET_ENV", text)
        self.assertIn("decoder: `not_ready`", text)
        self.assertIn("temporary_storage: `ready`", text)
        self.assertIn("private_vault: `ready`", text)
        self.assertNotIn("/srv/private", text)
        self.assertNotIn("REMOTE_BODY", text)

    def test_workflow_closes_issue_307_only_after_successful_exact_deploy_web_runtime_evidence(self) -> None:
        script = self._reconcile_script()
        cases = (
            (
                "MEDIA_WEB_RUNTIME_CHECK_decoder=ready\n"
                "MEDIA_WEB_RUNTIME_CHECK_temporary_storage=ready\n"
                "MEDIA_WEB_RUNTIME_CHECK_private_vault=ready\n"
                "MEDIA_WEB_RUNTIME_READY=1\n",
                True,
            ),
            (
                "MEDIA_WEB_RUNTIME_CHECK_decoder=not_ready\n"
                "MEDIA_WEB_RUNTIME_CHECK_temporary_storage=ready\n"
                "MEDIA_WEB_RUNTIME_CHECK_private_vault=ready\n"
                "MEDIA_WEB_RUNTIME_READY=0\n",
                False,
            ),
            ("", False),
            ("MEDIA_WEB_RUNTIME_READY=1\nMEDIA_WEB_RUNTIME_READY=1\n", False),
        )
        for smoke_log, should_close in cases:
            with self.subTest(smoke_log=smoke_log):
                completed, summary, calls = self._run_reconcile(script, smoke_log)
                self.assertEqual(0, completed.returncode, completed.stderr)
                self.assertEqual(should_close, "issue close 307" in calls)
                evidence = Path("/tmp/grindflow-media-web-runtime.md")
                if should_close:
                    proof = evidence.read_text(encoding="utf-8")
                    self.assertIn(f"Exact deployed SHA: `{'a' * 40}`", proof)
                    self.assertIn("Evidence scope: `web_runtime`", proof)
                else:
                    self.assertIn("BLOCKED_TARGET_ENV", summary.read_text(encoding="utf-8"))
                evidence.unlink(missing_ok=True)

    def test_v0192_identity_is_synchronized_without_making_s3_a_requirement(self) -> None:
        self.assertIn("'number' => '0.1.192'", VERSION)
        self.assertEqual("0.1.192", PACKAGE["version"])
        self.assertEqual("0.1.192", LOCK["version"])
        self.assertEqual("0.1.192", LOCK["packages"][""]["version"])
        self.assertIn("V0.1.192", README)
        self.assertIn("Quick upload remains available.", WORKFLOW)

    @staticmethod
    def _payload(
        *,
        decoder: str,
        temporary_storage: str,
        private_vault: str,
        status: str | None = None,
    ) -> dict[str, object]:
        checks = {
            "decoder": decoder,
            "temporary_storage": temporary_storage,
            "private_vault": private_vault,
        }
        if status is None:
            status = "ready" if all(value == "ready" for value in checks.values()) else "not_ready"
        return {
            "data": {
                "contract": "media-pilot-readiness-v1",
                "status": status,
                "checks": checks,
                "evidence_scope": "web_runtime",
                "ci_equivalent": False,
            }
        }

    @staticmethod
    def _run_parser(parser: str, payload: dict[str, object]) -> subprocess.CompletedProcess[str]:
        with tempfile.NamedTemporaryFile("w", encoding="utf-8") as handle:
            json.dump(payload, handle)
            handle.flush()
            return subprocess.run(
                ["python3", "-", handle.name],
                input=parser,
                text=True,
                capture_output=True,
                check=False,
            )

    @staticmethod
    def _readiness_function() -> str:
        start = SMOKE.index("check_media_web_runtime_readiness() {")
        end = SMOKE.index("# One anonymous GET after a rejected POST", start)
        return SMOKE[start:end]

    @classmethod
    def _readiness_parser(cls) -> str:
        block = cls._readiness_function()
        marker = 'python3 - "$media_readiness_body" <<\'PY\'\n'
        start = block.index(marker) + len(marker)
        end = block.index("\nPY\n", start)
        return block[start:end]

    @staticmethod
    def _reconcile_script() -> str:
        step_start = WORKFLOW.index("      - name: Reconcile media web-runtime readiness")
        step_end = WORKFLOW.index("      - name: Upload short-lived smoke diagnostics", step_start)
        step = WORKFLOW[step_start:step_end]
        if "steps.smoke.outcome == 'success'" not in step:
            raise AssertionError("readiness reconciliation must depend on a successful smoke")
        if "MEDIA_READINESS_ISSUE: '307'" not in step:
            raise AssertionError("readiness reconciliation must target issue 307")
        if 'cat "$media_readiness_body"' in step:
            raise AssertionError("workflow must never expose the remote body")
        run_marker = "        run: |\n"
        script_start = step.index(run_marker) + len(run_marker)
        return textwrap.dedent(step[script_start:])

    @staticmethod
    def _run_reconcile(
        script: str,
        smoke_log: str,
    ) -> tuple[subprocess.CompletedProcess[str], Path, str]:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bin_dir = root / "bin"
            bin_dir.mkdir()
            gh_log = root / "gh.log"
            fake_gh = bin_dir / "gh"
            fake_gh.write_text(
                "#!/bin/sh\n"
                "printf '%s\\n' \"$*\" >> \"$GH_LOG\"\n"
                "if [ \"$1 $2\" = \"issue view\" ]; then printf 'OPEN\\n'; fi\n",
                encoding="utf-8",
            )
            fake_gh.chmod(0o755)
            (root / "production-smoke.log").write_text(smoke_log, encoding="utf-8")
            summary = root / "summary.md"
            evidence = Path("/tmp/grindflow-media-web-runtime.md")
            evidence.unlink(missing_ok=True)

            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{bin_dir}:{env['PATH']}",
                    "GH_LOG": str(gh_log),
                    "GITHUB_SHA": "a" * 40,
                    "GITHUB_STEP_SUMMARY": str(summary),
                    "GITHUB_SERVER_URL": "https://github.example",
                    "GITHUB_REPOSITORY": "pl0n3r/GrindFlow",
                    "GITHUB_RUN_ID": "12345",
                    "MEDIA_READINESS_ISSUE": "307",
                }
            )
            completed = subprocess.run(
                ["bash", "-c", script],
                cwd=root,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
            calls = gh_log.read_text(encoding="utf-8") if gh_log.exists() else ""
            summary_copy = Path(tempfile.mkstemp(prefix="gf-summary-", suffix=".md")[1])
            summary_copy.write_text(
                summary.read_text(encoding="utf-8") if summary.exists() else "",
                encoding="utf-8",
            )
            return completed, summary_copy, calls


if __name__ == "__main__":
    unittest.main()
