from __future__ import annotations

import json
import os
import re
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
        self.assertIn('readiness_cookie="$cookie_jar"', block)
        self.assertIn('readiness_cookie="$s4_cookie_jar"', block)
        self.assertIn('curl_common --cookie "$readiness_cookie" --output "$media_readiness_body"', block)
        self.assertNotIn("GRINDFLOW_SMOKE_PASSWORD", block)
        self.assertNotIn("Authorization:", block)

    def test_valid_contract_emits_allowlisted_subcheck_markers(self) -> None:
        ready = self._payload()
        completed = self._run_parser(ready)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertEqual(
            [
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=ready",
                "MEDIA_WEB_RUNTIME_READY=1",
            ],
            completed.stdout.splitlines(),
        )

        blocked = self._payload()
        blocked["data"]["status"] = "not_ready"
        blocked["data"]["checks"]["private_vault"] = "not_ready"
        completed = self._run_parser(blocked)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertEqual(
            [
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=not_ready",
                "MEDIA_WEB_RUNTIME_READY=0",
            ],
            completed.stdout.splitlines(),
        )

    def test_invalid_contract_never_emits_remote_or_arbitrary_diagnostics(self) -> None:
        mutations: list[dict[str, object]] = []

        extra = self._payload()
        extra["data"]["remote_path"] = "/sensitive/path"
        mutations.append(extra)

        arbitrary = self._payload()
        arbitrary["data"]["checks"]["decoder"] = "leak-me"
        mutations.append(arbitrary)

        mismatch = self._payload()
        mismatch["data"]["checks"]["decoder"] = "not_ready"
        mutations.append(mismatch)

        ci_truthy = self._payload()
        ci_truthy["data"]["ci_equivalent"] = True
        mutations.append(ci_truthy)

        for payload in mutations:
            with self.subTest(payload=payload):
                completed = self._run_parser(payload)
                self.assertNotEqual(0, completed.returncode)
                self.assertEqual("", completed.stdout)

        block = self._readiness_function()
        self.assertIn('if [[ "$status" != "200" ]]', block)
        self.assertIn("json.JSONDecodeError", block)
        self.assertNotIn('cat "$media_readiness_body"', SMOKE)
        read_only_probe = block.split("# Authenticate only after", 1)[0]
        self.assertNotIn("facebook", read_only_probe.lower())
        self.assertNotIn("publish", read_only_probe.lower())
        self.assertNotIn("--request POST", read_only_probe)

    def test_ci_equivalent_requires_json_boolean_false_strictly(self) -> None:
        for value, expected_code in ((False, 0), (0, 1), (None, 1), ("false", 1)):
            with self.subTest(value=value):
                payload = self._payload()
                payload["data"]["ci_equivalent"] = value
                completed = self._run_parser(payload)
                self.assertEqual(expected_code, completed.returncode, completed.stderr)

    def test_blocked_runtime_comments_only_allowlisted_subchecks(self) -> None:
        smoke_log = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed",
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=not_ready",
                "MEDIA_WEB_RUNTIME_CHECK_EVIL=leak-me",
                "MEDIA_WEB_RUNTIME_READY=0",
                "SECRET=do-not-copy",
                "",
            )
        )
        completed, calls, summary, proof = self._run_reconcile(smoke_log)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("issue comment 307 --body-file", calls)
        self.assertNotIn("issue close 307", calls)
        for content in (summary, proof):
            self.assertIn("decoder: `ready`", content)
            self.assertIn("temporary_storage: `ready`", content)
            self.assertIn("private_vault: `not_ready`", content)
            self.assertIn("Exact deployed SHA: `" + "a" * 40 + "`", content)
            self.assertIn("actions/runs/12345", content)
            self.assertIn("BLOCKED_TARGET_ENV", content)
            self.assertNotIn("leak-me", content)
            self.assertNotIn("do-not-copy", content)
        self.assertIn("grindflow-media-runtime-blocked-v1", proof)
        for forbidden in ("remote response", "filesystem path", "cookie", "header", "hash", "secret"):
            self.assertNotIn(forbidden, proof.lower())

    def test_missing_diagnostics_posts_single_classified_comment_and_never_closes(self) -> None:
        cases = {
            "endpoint_unreachable": "MEDIA_WEB_RUNTIME_DIAGNOSTIC=endpoint_unreachable\nMEDIA_WEB_RUNTIME_READY=0\n",
            "http_non_200": "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nMEDIA_WEB_RUNTIME_READY=0\n",
            "contract_invalid": "MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid\nMEDIA_WEB_RUNTIME_READY=0\n",
            "markers_absent": "MEDIA_WEB_RUNTIME_READY=0\n",
            "markers_absent_duplicate": (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed\n"
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=invalid\n"
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready\n"
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready\n"
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=ready\n"
                "MEDIA_WEB_RUNTIME_READY=1\n"
            ),
        }
        for reason, smoke_log in cases.items():
            with self.subTest(reason=reason):
                first, first_calls, _, proof = self._run_reconcile(smoke_log)
                second, second_calls, _, _ = self._run_reconcile(smoke_log, existing_comments=proof)
                self.assertEqual(0, first.returncode, first.stderr)
                self.assertEqual(0, second.returncode, second.stderr)
                self.assertIn("issue comment 307 --body-file", first_calls)
                self.assertNotIn("issue close 307", first_calls)
                self.assertNotIn("issue comment 307", second_calls)
                self.assertIn("Media web runtime: diagnóstico no disponible", proof)
                expected_reason = "markers_absent" if reason == "markers_absent_duplicate" else reason
                self.assertIn(f"Cause: `{expected_reason}`", proof)
                self.assertIn("grindflow-media-runtime-unavailable-v1", proof)

    def test_unavailable_diagnostic_includes_only_valid_allowlisted_s4_bridge_state(self) -> None:
        valid = (
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\n"
            "S4_BRIDGE_STATE=config_missing\n"
            "MEDIA_WEB_RUNTIME_READY=0\n"
        )
        completed, calls, _, proof = self._run_reconcile(valid)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("issue comment 307 --body-file", calls)
        self.assertIn("S4 bridge state: `config_missing`", proof)

        invalid_cases = (
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nS4_BRIDGE_STATE=leak-me\nMEDIA_WEB_RUNTIME_READY=0\n",
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nS4_BRIDGE_STATE=config_missing\nS4_BRIDGE_STATE=schema_missing\nMEDIA_WEB_RUNTIME_READY=0\n",
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nS4_BRIDGE_STATE=config_missing\nS4_BRIDGE_STATE=leak-me\nMEDIA_WEB_RUNTIME_READY=0\n",
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nMEDIA_WEB_RUNTIME_READY=0\n",
        )
        for smoke_log in invalid_cases:
            with self.subTest(smoke_log=smoke_log):
                completed, _, _, proof = self._run_reconcile(smoke_log)
                self.assertEqual(0, completed.returncode, completed.stderr)
                self.assertNotIn("S4 bridge state:", proof)
                self.assertNotIn("leak-me", proof)

    def test_s4_auth_state_is_optional_unique_and_allowlisted(self) -> None:
        valid = (
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\n"
            "S4_BRIDGE_STATE=ready_for_web_probe\n"
            "S4_AUTH_STATE=identity_unavailable\n"
            "MEDIA_WEB_RUNTIME_READY=0\n"
        )
        completed, _, _, proof = self._run_reconcile(valid)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("S4 auth state: `identity_unavailable`", proof)

        invalid_cases = (
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nS4_AUTH_STATE=leak-me\nMEDIA_WEB_RUNTIME_READY=0\n",
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nS4_AUTH_STATE=ready\nS4_AUTH_STATE=identity_unavailable\nMEDIA_WEB_RUNTIME_READY=0\n",
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nS4_AUTH_STATE=ready\nS4_AUTH_STATE=leak-me\nMEDIA_WEB_RUNTIME_READY=0\n",
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\nMEDIA_WEB_RUNTIME_READY=0\n",
        )
        for smoke_log in invalid_cases:
            with self.subTest(smoke_log=smoke_log):
                completed, _, _, proof = self._run_reconcile(smoke_log)
                self.assertEqual(0, completed.returncode, completed.stderr)
                self.assertNotIn("S4 auth state:", proof)
                self.assertNotIn("leak-me", proof)

    def test_s4_states_are_published_only_for_http_non_200(self) -> None:
        for diagnostic_state in ("endpoint_unreachable", "contract_invalid", "markers_absent"):
            with self.subTest(diagnostic_state=diagnostic_state):
                smoke_log = (
                    f"MEDIA_WEB_RUNTIME_DIAGNOSTIC={diagnostic_state}\n"
                    "S4_BRIDGE_STATE=config_missing\n"
                    "S4_AUTH_STATE=identity_unavailable\n"
                    "MEDIA_WEB_RUNTIME_READY=0\n"
                )
                completed, _, _, proof = self._run_reconcile(smoke_log)
                self.assertEqual(0, completed.returncode, completed.stderr)
                self.assertNotIn("S4 bridge state:", proof)
                self.assertNotIn("S4 auth state:", proof)

        completed, _, _, proof = self._run_reconcile(
            "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200\n"
            "S4_BRIDGE_STATE=config_missing\n"
            "S4_AUTH_STATE=identity_unavailable\n"
            "MEDIA_WEB_RUNTIME_READY=0\n"
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("S4 bridge state: `config_missing`", proof)
        self.assertIn("S4 auth state: `identity_unavailable`", proof)

    def test_s4_diagnostic_comment_never_exposes_remote_or_secret_material(self) -> None:
        smoke_log = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=http_non_200",
                "S4_BRIDGE_STATE=schema_missing",
                "S4_AUTH_STATE=identity_unavailable",
                "REMOTE_BODY={secret}",
                "HEADER=Authorization: Bearer secret",
                "PATH=/home/private",
                "COOKIE=session=secret",
                "HASH=" + "f" * 64,
                "MEDIA_WEB_RUNTIME_READY=0",
                "",
            )
        )
        completed, _, _, proof = self._run_reconcile(smoke_log)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("S4 bridge state: `schema_missing`", proof)
        self.assertIn("S4 auth state: `identity_unavailable`", proof)
        for leaked in ("{secret}", "Bearer secret", "/home/private", "session=secret", "f" * 64):
            self.assertNotIn(leaked, proof)

    def test_s4_bridge_states_have_safe_target_env_guidance(self) -> None:
        docs = (ROOT / "docs/PILOT-MEDIA-READINESS.md").read_text(encoding="utf-8")
        for state in (
            "runtime_unavailable",
            "config_missing",
            "schema_missing",
            "identity_unavailable",
            "ready_for_web_probe",
        ):
            self.assertIn(f"`{state}`", docs)
        for state in ("identity_unavailable", "ready"):
            self.assertIn(f"`{state}`", docs)
        self.assertIn("No autoriza migraciones automáticas", docs)
        self.assertIn("No autoriza crear identidades automáticamente", docs)
        self.assertIn("no autoriza publicación externa", docs)

    def test_workflow_dispatch_emits_same_markers_as_push(self) -> None:
        self.assertIn("  push:\n    branches: [main]\n  workflow_dispatch:", WORKFLOW)
        self.assertEqual(1, WORKFLOW.count("run: bash scripts/production-smoke.sh >production-smoke.log 2>&1"))
        reconcile = self._reconcile_script()
        self.assertNotIn("github.event_name", reconcile)
        self.assertIn("^S4_BRIDGE_STATE=", reconcile)
        self.assertIn("^S4_AUTH_STATE=", reconcile)

        completed = self._run_parser(self._payload())
        self.assertEqual(0, completed.returncode, completed.stderr)
        emitted = completed.stdout.splitlines()
        for marker in (
            "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
            "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
            "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=ready",
            "MEDIA_WEB_RUNTIME_READY=1",
        ):
            self.assertIn(marker, emitted)

    def test_smoke_emits_allowlisted_diagnostic_classifications_from_mocked_curl(self) -> None:
        valid_body = json.dumps(self._payload())
        cases = (
            ("000", "", "endpoint_unreachable"),
            ("503", "upstream unavailable", "http_non_200"),
            ("200", "not-json", "contract_invalid"),
            ("200", valid_body, "observed"),
        )
        for status, body, expected in cases:
            with self.subTest(status=status, expected=expected):
                completed = self._run_readiness_function(status, body)
                self.assertEqual(0, completed.returncode, completed.stderr)
                lines = completed.stdout.splitlines()
                self.assertEqual(
                    1,
                    sum(
                        line.startswith("MEDIA_WEB_RUNTIME_DIAGNOSTIC=")
                        for line in lines
                    ),
                )
                self.assertIn(f"MEDIA_WEB_RUNTIME_DIAGNOSTIC={expected}", lines)

    def test_blocked_evidence_is_idempotent_per_sha_and_run(self) -> None:
        smoke_log = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed",
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=not_ready",
                "MEDIA_WEB_RUNTIME_READY=0",
                "",
            )
        )
        first, first_calls, _, first_proof = self._run_reconcile(smoke_log)
        second, second_calls, _, second_proof = self._run_reconcile(
            smoke_log,
            existing_comments=first_proof,
        )
        self.assertEqual(0, first.returncode, first.stderr)
        self.assertEqual(0, second.returncode, second.stderr)
        self.assertEqual(1, first_calls.count("issue comment 307"))
        self.assertNotIn("issue comment 307", second_calls)
        self.assertEqual(first_proof, second_proof)

    def test_all_ready_web_runtime_closes_readiness_issue_with_allowlisted_evidence(self) -> None:
        ready = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed",
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=ready",
                "MEDIA_WEB_RUNTIME_READY=1",
                "",
            )
        )
        blocked = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed",
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=not_ready",
                "MEDIA_WEB_RUNTIME_READY=0",
                "",
            )
        )
        duplicate_subcheck = ready + "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready\n"
        cases = (
            (ready, True),
            (blocked, False),
            ("", False),
            (ready + "MEDIA_WEB_RUNTIME_READY=1\n", False),
            ("MEDIA_WEB_RUNTIME_READY=1\n", False),
            (duplicate_subcheck, False),
        )
        for smoke_log, should_close in cases:
            with self.subTest(smoke_log=smoke_log):
                completed, calls, summary, proof = self._run_reconcile(smoke_log)
                self.assertEqual(0, completed.returncode, completed.stderr)
                self.assertEqual(should_close, "issue close 307" in calls)
                if should_close:
                    self.assertIn("Exact deployed SHA: `" + "a" * 40 + "`", proof)
                    self.assertIn("Evidence scope: `web_runtime`", proof)
                    self.assertIn("decoder: `ready`", proof)
                    self.assertIn("temporary_storage: `ready`", proof)
                    self.assertIn("private_vault: `ready`", proof)

    def test_reconcile_invocations_use_isolated_evidence_paths(self) -> None:
        ready = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed",
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=ready",
                "MEDIA_WEB_RUNTIME_READY=1",
                "",
            )
        )
        first, first_calls, _, first_proof = self._run_reconcile(ready)
        second, second_calls, _, second_proof = self._run_reconcile(ready)
        self.assertEqual(0, first.returncode, first.stderr)
        self.assertEqual(0, second.returncode, second.stderr)
        self.assertEqual(first_proof, second_proof)
        self.assertIn("Evidence scope: `web_runtime`", first_proof)

        first_path = next(
            line.rsplit(" --body-file ", 1)[1]
            for line in first_calls.splitlines()
            if " --body-file " in line
        )
        second_path = next(
            line.rsplit(" --body-file ", 1)[1]
            for line in second_calls.splitlines()
            if " --body-file " in line
        )
        self.assertNotEqual(first_path, second_path)
        self.assertIn("grindflow-media-web-runtime.md", first_path)
        self.assertIn("grindflow-media-web-runtime.md", second_path)

    def test_release_identity_is_synchronized_without_making_s3_a_requirement(self) -> None:
        release = PACKAGE["version"]
        self.assertIn(f"'number' => '{release}'", VERSION)
        self.assertEqual(release, LOCK["version"])
        self.assertEqual(release, LOCK["packages"][""]["version"])
        target = re.search(
            r"\*\*Versión de código declarada:\*\* V(?P<version>[0-9]+\.[0-9]+\.[0-9]+)",
            README,
        )
        self.assertIsNotNone(target)
        self.assertEqual(release, target.group("version"))
        self.assertIn("Quick upload remains available.", WORKFLOW)

    @staticmethod
    def _payload() -> dict[str, object]:
        return {
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

    @classmethod
    def _run_parser(cls, payload: object) -> subprocess.CompletedProcess[str]:
        parser = cls._parser()
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
    def _parser(cls) -> str:
        block = cls._readiness_function()
        marker = 'if parsed="$(python3 - "$media_readiness_body" <<\'PY\'\n'
        start = block.index(marker) + len(marker)
        end = block.index("\nPY\n", start)
        return textwrap.dedent(block[start:end])

    @classmethod
    def _run_readiness_function(
        cls,
        status: str,
        body: str,
    ) -> subprocess.CompletedProcess[str]:
        function = cls._readiness_function()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            media_body = root / "media-readiness.json"
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cookie_jar={str(root / "cookies.txt")!r}
                media_readiness_body={str(media_body)!r}
                BASE_URL='https://example.invalid'
                MOCK_STATUS={status!r}
                MOCK_BODY={body!r}

                curl_common() {{
                  printf '%s' "$MOCK_BODY" > "$media_readiness_body"
                  printf '%s' "$MOCK_STATUS"
                }}

                {function}
                check_media_web_runtime_readiness
                """
            )
            return subprocess.run(
                ["bash", "-c", script],
                text=True,
                capture_output=True,
                check=False,
            )

    @staticmethod
    def _reconcile_script() -> str:
        start = WORKFLOW.index("      - name: Reconcile media web-runtime readiness")
        end = WORKFLOW.index("      - name: Upload short-lived smoke diagnostics", start)
        step = WORKFLOW[start:end]
        marker = "        run: |\n"
        script_start = step.index(marker) + len(marker)
        return textwrap.dedent(step[script_start:])

    @classmethod
    def _run_reconcile(
        cls,
        smoke_log: str,
        existing_comments: str = "",
    ) -> tuple[subprocess.CompletedProcess[str], str, str, str]:
        script = cls._reconcile_script()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bin_dir = root / "bin"
            bin_dir.mkdir()
            gh_log = root / "gh.log"
            fake_gh = bin_dir / "gh"
            fake_gh.write_text(
                "#!/bin/sh\n"
                "printf '%s\\n' \"$*\" >> \"$GH_LOG\"\n"
                "if [ \"$1 $2\" = \"issue view\" ]; then\n"
                "  case \" $* \" in\n"
                "    *\" --json comments \"*) printf '%s\\n' \"${GH_EXISTING_COMMENTS:-}\" ;;\n"
                "    *) printf 'OPEN\\n' ;;\n"
                "  esac\n"
                "fi\n",
                encoding="utf-8",
            )
            fake_gh.chmod(0o755)
            (root / "production-smoke.log").write_text(smoke_log, encoding="utf-8")
            summary = root / "summary.md"
            evidence = root / "grindflow-media-web-runtime.md"
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{bin_dir}:{env['PATH']}",
                    "GH_LOG": str(gh_log),
                    "GH_EXISTING_COMMENTS": existing_comments,
                    "GITHUB_SHA": "a" * 40,
                    "GITHUB_STEP_SUMMARY": str(summary),
                    "GITHUB_SERVER_URL": "https://github.example",
                    "GITHUB_REPOSITORY": "pl0n3r/GrindFlow",
                    "GITHUB_RUN_ID": "12345",
                    "MEDIA_READINESS_ISSUE": "307",
                    "MEDIA_WEB_RUNTIME_EVIDENCE_PATH": str(evidence),
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
            summary_text = summary.read_text(encoding="utf-8") if summary.exists() else ""
            proof = evidence.read_text(encoding="utf-8") if evidence.exists() else ""
            return completed, calls, summary_text, proof


if __name__ == "__main__":
    unittest.main()
