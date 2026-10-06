import json
import os
import re
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MATERIALIZER = (
    ROOT / "symfony/src/Infrastructure/Storage/VaultPhotoSafetyMaterializer.php"
).read_text(encoding="utf-8")
CONTROLLER = (
    ROOT / "symfony/src/Http/Controller/ScheduleDraftController.php"
).read_text(encoding="utf-8")
SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
DOCS = (ROOT / "docs/PILOT-MEDIA-READINESS.md").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")


class S4PrivateVaultReadinessTests(unittest.TestCase):
    STATES = {
        "root_unavailable",
        "missing",
        "unreadable",
        "permissions_unavailable",
        "permissions_not_private",
        "ready",
    }

    def test_private_vault_states_are_bounded_and_fail_closed(self) -> None:
        start = MATERIALIZER.index("public function privateVaultReadinessState")
        end = MATERIALIZER.index("private function assertMemoryAvailable", start)
        method = MATERIALIZER[start:end]
        returns = set(re.findall(r"return '([^']+)';", method))
        self.assertEqual(self.STATES, returns)
        self.assertIn("$vaultRoot === '' || is_link($vaultRoot)", method)
        self.assertIn("!is_dir($vaultRoot)", method)
        self.assertIn("!is_readable($vaultRoot)", method)
        self.assertIn("@fileperms($vaultRoot)", method)
        self.assertIn("($mode & 0077) !== 0", method)
        self.assertIn(
            "'private_vault' => $this->privateVaultReadinessState($vaultRoot) === 'ready'",
            MATERIALIZER,
        )
        for forbidden in ("mkdir(", "chmod(", "unlink(", "rename("):
            self.assertNotIn(forbidden, method)

    def test_endpoint_exposes_only_allowlisted_private_vault_state(self) -> None:
        start = CONTROLLER.index("public function mediaReadiness(")
        end = CONTROLLER.index("/** Read a bounded tenant-scoped agenda", start)
        method = CONTROLLER[start:end]
        response = method[method.index("return $this->privateJson") :]
        self.assertIn("$vaultState = $photoSafety->privateVaultReadinessState($vaultRoot);", method)
        self.assertIn("'diagnostics' => [", response)
        self.assertIn("'private_vault' => $vaultState", response)
        self.assertNotIn("$vaultRoot", response)
        self.assertNotIn("fileperms", response)
        self.assertNotIn("077", response)

    def test_smoke_emits_only_valid_unique_private_vault_state(self) -> None:
        valid = self._payload("permissions_not_private")
        completed = self._run_readiness_function(valid)
        self.assertEqual(0, completed.returncode, completed.stderr)
        lines = completed.stdout.splitlines()
        self.assertEqual(
            1,
            lines.count("S4_PRIVATE_VAULT_STATE=permissions_not_private"),
        )
        self.assertIn("MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed", lines)
        self.assertIn("MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=not_ready", lines)

        for payload in (
            self._payload("unexpected"),
            self._payload(None),
        ):
            with self.subTest(payload=payload):
                rejected = self._run_readiness_function(payload)
                self.assertEqual(0, rejected.returncode, rejected.stderr)
                self.assertIn(
                    "MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid",
                    rejected.stdout.splitlines(),
                )
                self.assertNotIn("S4_PRIVATE_VAULT_STATE=", rejected.stdout)

        self.assertIn('[[ "${#matches[@]}" != "1" ]]', WORKFLOW)
        self.assertIn(
            "S4_PRIVATE_VAULT_STATE=(root_unavailable|missing|unreadable|permissions_unavailable|permissions_not_private|ready)",
            WORKFLOW,
        )

    def test_reconcile_publishes_secret_free_private_vault_state(self) -> None:
        smoke_log = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed",
                "S4_PRIVATE_VAULT_STATE=permissions_not_private",
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=not_ready",
                "MEDIA_WEB_RUNTIME_READY=0",
                "PATH=/home/private/vault",
                "MODE=0755",
                "SECRET=do-not-publish",
                "",
            )
        )
        completed, calls, proof = self._run_reconcile(smoke_log)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("issue comment 307", calls)
        self.assertIn("Private Vault state: `permissions_not_private`", proof)
        self.assertIn("- private_vault: `not_ready`", proof)
        for leaked in ("/home/private/vault", "0755", "do-not-publish"):
            self.assertNotIn(leaked, proof)

    def test_operational_closeout_requires_exact_main_private_vault_classification(self) -> None:
        self.assertEqual("0.1.214", PACKAGE["version"])
        self.assertEqual("0.1.214", LOCK["version"])
        self.assertEqual("0.1.214", LOCK["packages"][""]["version"])
        self.assertIn("'number' => '0.1.214'", VERSION)
        self.assertIn("V0.1.214", README)
        self.assertIn("S4_PRIVATE_VAULT_STATE", DOCS)
        self.assertIn("exact-main", DOCS)
        self.assertIn("no autoriza", DOCS.lower())
        self.assertIn("S4_PRIVATE_VAULT_STATE=", SMOKE)
        self.assertIn("Private Vault state:", WORKFLOW)

    @classmethod
    def _payload(cls, state: str | None) -> dict[str, object]:
        diagnostics = {} if state is None else {"private_vault": state}
        return {
            "data": {
                "contract": "media-pilot-readiness-v1",
                "status": "not_ready",
                "checks": {
                    "decoder": "ready",
                    "temporary_storage": "ready",
                    "private_vault": "not_ready",
                },
                "diagnostics": diagnostics,
                "evidence_scope": "web_runtime",
                "ci_equivalent": False,
            }
        }

    @staticmethod
    def _readiness_function() -> str:
        start = SMOKE.index("check_media_web_runtime_readiness() {")
        end = SMOKE.index("# One anonymous GET after a rejected POST", start)
        return SMOKE[start:end]

    @classmethod
    def _run_readiness_function(
        cls,
        payload: dict[str, object],
    ) -> subprocess.CompletedProcess[str]:
        function = cls._readiness_function()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            media_body = root / "media-readiness.json"
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                cookie_jar={str(root / "cookies.txt")!r}
                s4_cookie_jar={str(root / "s4-cookies.txt")!r}
                media_readiness_body={str(media_body)!r}
                BASE_URL='https://example.invalid'
                MOCK_BODY={json.dumps(json.dumps(payload))}

                curl_common() {{
                  printf '%s' "$MOCK_BODY" > "$media_readiness_body"
                  printf '200'
                }}

                {function}
                check_media_web_runtime_readiness s4
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
    ) -> tuple[subprocess.CompletedProcess[str], str, str]:
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
                "    *\" --json comments \"*) printf '\\n' ;;\n"
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
            proof = evidence.read_text(encoding="utf-8") if evidence.exists() else ""
            return completed, calls, proof


if __name__ == "__main__":
    unittest.main()
