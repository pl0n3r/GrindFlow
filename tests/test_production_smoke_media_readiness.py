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
            '"status": "ready"',
            '"evidence_scope": "web_runtime"',
            '"decoder", "temporary_storage", "private_vault"',
            "MEDIA_WEB_RUNTIME_READY=1",
            "MEDIA_WEB_RUNTIME_READY=0",
        ):
            self.assertIn(token, block)
        self.assertIn('set(payload) != {"data"}', block)
        self.assertIn('data.get("ci_equivalent") is not False', block)
        self.assertIn("set(data) != {", block)
        self.assertIn("set(checks) != {", block)

    def test_ci_equivalent_requires_json_boolean_false_strictly(self) -> None:
        block = self._readiness_function()
        marker = "if python3 - \"$media_readiness_body\" <<'PY'\n"
        start = block.index(marker) + len(marker)
        end = block.index("\nPY\n", start)
        parser = block[start:end]

        base = {
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

        for value, expected_code in ((False, 0), (0, 1), (None, 1), ("false", 1)):
            with self.subTest(value=value):
                payload = json.loads(json.dumps(base))
                payload["data"]["ci_equivalent"] = value
                with tempfile.NamedTemporaryFile("w", encoding="utf-8") as handle:
                    json.dump(payload, handle)
                    handle.flush()
                    result = subprocess.run(
                        ["python3", "-", handle.name],
                        input=parser,
                        text=True,
                        capture_output=True,
                        check=False,
                    )
                self.assertEqual(expected_code, result.returncode, result.stderr)

    def test_invalid_or_not_ready_response_fails_closed_without_provider_io_or_sensitive_output(self) -> None:
        block = self._readiness_function()
        self.assertIn('if [[ "$status" != "200" ]]', block)
        self.assertIn("json.JSONDecodeError", block)
        self.assertNotIn('cat "$media_readiness_body"', SMOKE)
        self.assertNotIn("facebook", block.lower())
        self.assertNotIn("publish", block.lower())
        self.assertNotIn("--request POST", block)

    def test_workflow_closes_issue_307_only_after_successful_exact_deploy_web_runtime_evidence(self) -> None:
        step_start = WORKFLOW.index("      - name: Reconcile media web-runtime readiness")
        step_end = WORKFLOW.index("      - name: Upload short-lived smoke diagnostics", step_start)
        step = WORKFLOW[step_start:step_end]
        self.assertIn("steps.smoke.outcome == 'success'", step)
        self.assertIn("MEDIA_READINESS_ISSUE: '307'", step)
        self.assertNotIn('cat "$media_readiness_body"', step)

        run_marker = "        run: |\n"
        script_start = step.index(run_marker) + len(run_marker)
        script = textwrap.dedent(step[script_start:])

        cases = (
            ("MEDIA_WEB_RUNTIME_READY=1\n", True),
            ("MEDIA_WEB_RUNTIME_READY=0\n", False),
            ("", False),
            ("MEDIA_WEB_RUNTIME_READY=1\nMEDIA_WEB_RUNTIME_READY=1\n", False),
        )
        for smoke_log, should_close in cases:
            with self.subTest(smoke_log=smoke_log):
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

                    sha = "a" * 40
                    env = os.environ.copy()
                    env.update(
                        {
                            "PATH": f"{bin_dir}:{env['PATH']}",
                            "GH_LOG": str(gh_log),
                            "GITHUB_SHA": sha,
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
                    self.assertEqual(0, completed.returncode, completed.stderr)
                    calls = gh_log.read_text(encoding="utf-8") if gh_log.exists() else ""
                    self.assertEqual(should_close, "issue close 307" in calls)
                    if should_close:
                        proof = evidence.read_text(encoding="utf-8")
                        self.assertIn(f"Exact deployed SHA: `{sha}`", proof)
                        self.assertIn("Evidence scope: `web_runtime`", proof)
                    else:
                        self.assertIn("BLOCKED_TARGET_ENV", summary.read_text(encoding="utf-8"))
                    evidence.unlink(missing_ok=True)

    def test_v0191_identity_is_synchronized_without_making_s3_a_requirement(self) -> None:
        self.assertIn("'number' => '0.1.191'", VERSION)
        self.assertEqual("0.1.191", PACKAGE["version"])
        self.assertEqual("0.1.191", LOCK["version"])
        self.assertEqual("0.1.191", LOCK["packages"][""]["version"])
        self.assertIn("V0.1.191", README)
        self.assertIn("Quick upload remains available.", WORKFLOW)

    @staticmethod
    def _readiness_function() -> str:
        start = SMOKE.index("check_media_web_runtime_readiness() {")
        end = SMOKE.index("# One anonymous GET after a rejected POST", start)
        return SMOKE[start:end]


if __name__ == "__main__":
    unittest.main()
