from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[1]
MATERIALIZER = (
    ROOT / "symfony/src/Infrastructure/Storage/VaultPhotoSafetyMaterializer.php"
).read_text(encoding="utf-8")
CONTROLLER = (
    ROOT / "symfony/src/Http/Controller/ScheduleDraftController.php"
).read_text(encoding="utf-8")
SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")
DOCS = (ROOT / "docs/PILOT-MEDIA-READINESS.md").read_text(encoding="utf-8")

ALLOWED = (
    "root_unavailable",
    "missing",
    "unreadable",
    "permissions_unavailable",
    "permissions_not_private",
    "ready",
)


class S4PrivateVaultReadinessTests(unittest.TestCase):
    def test_private_vault_states_are_bounded_and_fail_closed(self) -> None:
        method = MATERIALIZER.split(
            "public function privateVaultReadinessState", 1
        )[1].split("private function temporaryStorageReady", 1)[0]
        for state in ALLOWED:
            self.assertIn(f"'{state}'", method)
        self.assertEqual(6, sum(method.count(f"'{state}'") for state in ALLOWED))
        self.assertIn("is_link($vaultRoot)", method)
        self.assertIn("!is_dir($vaultRoot)", method)
        self.assertIn("!is_readable($vaultRoot)", method)
        self.assertIn("@fileperms($vaultRoot)", method)
        self.assertIn("($mode & 0077) !== 0", method)
        self.assertIn(
            "$this->privateVaultReadinessState($vaultRoot) === 'ready'",
            MATERIALIZER,
        )

    def test_endpoint_exposes_only_allowlisted_private_vault_state(self) -> None:
        start = CONTROLLER.index("public function mediaReadiness(")
        end = CONTROLLER.index("/** Read a bounded tenant-scoped agenda", start)
        endpoint = CONTROLLER[start:end]
        self.assertIn("$vaultState = $photoSafety->privateVaultReadinessState($vaultRoot);", endpoint)
        self.assertIn("'diagnostics' => [", endpoint)
        self.assertIn("'private_vault' => $vaultState", endpoint)
        for forbidden in ("realpath", "fileperms", "mode", "uid", "gid", "vaultRoot =>"):
            self.assertNotIn(forbidden, endpoint)
        self.assertNotIn("'path'", endpoint)

    def test_smoke_emits_only_valid_unique_private_vault_state(self) -> None:
        for state in ALLOWED:
            with self.subTest(state=state):
                payload = self._s4_payload(state)
                completed = self._run_parser(json.dumps(payload))
                self.assertEqual(0, completed.returncode, completed.stderr)
                self.assertEqual(
                    1,
                    sum(
                        line.startswith("S4_PRIVATE_VAULT_STATE=")
                        for line in completed.stdout.splitlines()
                    ),
                )
                self.assertIn(
                    f"S4_PRIVATE_VAULT_STATE={state}",
                    completed.stdout.splitlines(),
                )

        invalid = self._s4_payload("ready")
        invalid["data"]["diagnostics"]["private_vault"] = "leak-me"
        completed = self._run_parser(json.dumps(invalid))
        self.assertNotEqual(0, completed.returncode)
        self.assertEqual("", completed.stdout)

        duplicate = (
            '{"data":{"contract":"media-pilot-readiness-v1","status":"ready",'
            '"checks":{"decoder":"ready","temporary_storage":"ready","private_vault":"ready"},'
            '"diagnostics":{"private_vault":"ready","private_vault":"missing"},'
            '"evidence_scope":"web_runtime","ci_equivalent":false}}'
        )
        completed = self._run_parser(duplicate)
        self.assertNotEqual(0, completed.returncode)
        self.assertEqual("", completed.stdout)

        missing = self._s4_payload("ready")
        del missing["data"]["diagnostics"]
        completed = self._run_parser(json.dumps(missing))
        self.assertNotEqual(0, completed.returncode)
        self.assertEqual("", completed.stdout)

    def test_reconcile_publishes_secret_free_private_vault_state(self) -> None:
        log = "\n".join(
            (
                "MEDIA_WEB_RUNTIME_DIAGNOSTIC=observed",
                "MEDIA_WEB_RUNTIME_CHECK_DECODER=ready",
                "MEDIA_WEB_RUNTIME_CHECK_TEMPORARY_STORAGE=ready",
                "MEDIA_WEB_RUNTIME_CHECK_PRIVATE_VAULT=not_ready",
                "S4_PRIVATE_VAULT_STATE=permissions_not_private",
                "MEDIA_WEB_RUNTIME_READY=0",
                "PATH=/home/private/vault",
                "MODE=0777",
                "SECRET=do-not-copy",
                "",
            )
        )
        completed, calls, proof = self._run_reconcile(log)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("issue comment 307 --body-file", calls)
        self.assertIn("private_vault: `not_ready`", proof)
        self.assertIn("Private Vault state: `permissions_not_private`", proof)
        self.assertIn("Exact deployed SHA: `" + ("a" * 40) + "`", proof)
        self.assertIn("actions/runs/12345", proof)
        for leaked in ("/home/private/vault", "0777", "do-not-copy"):
            self.assertNotIn(leaked, proof)

        invalid_logs = (
            log.replace(
                "S4_PRIVATE_VAULT_STATE=permissions_not_private\n",
                "",
            ),
            log.replace(
                "S4_PRIVATE_VAULT_STATE=permissions_not_private",
                "S4_PRIVATE_VAULT_STATE=leak-me",
            ),
            log.replace(
                "S4_PRIVATE_VAULT_STATE=permissions_not_private",
                "S4_PRIVATE_VAULT_STATE=missing\nS4_PRIVATE_VAULT_STATE=unreadable",
            ),
        )
        for invalid_log in invalid_logs:
            with self.subTest(invalid_log=invalid_log):
                completed, _, proof = self._run_reconcile(invalid_log)
                self.assertEqual(0, completed.returncode, completed.stderr)
                self.assertIn("Cause: `contract_invalid`", proof)
                self.assertNotIn("Private Vault state:", proof)
                self.assertNotIn("leak-me", proof)

    def test_release_v0214_is_synchronized_and_validate_is_canonical(self) -> None:
        import re

        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        self.assertEqual("0.1.214", match.group(1))
        self.assertEqual("0.1.214", PACKAGE["version"])
        self.assertEqual("0.1.214", LOCK["version"])
        self.assertEqual("0.1.214", LOCK["packages"][""]["version"])
        self.assertIn("V0.1.214", README)
        self.assertIn("tests/test_s4_private_vault_readiness.py", PACKAGE["scripts"]["test"])

    def test_operational_closeout_requires_exact_main_private_vault_classification(self) -> None:
        reconcile = self._reconcile_script()
        self.assertIn("GITHUB_SHA", reconcile)
        self.assertIn("GITHUB_RUN_ID", reconcile)
        self.assertIn("S4_PRIVATE_VAULT_STATE=", SMOKE)
        self.assertIn("private_vault_state", reconcile)
        self.assertIn("Production Smoke", DOCS)
        self.assertIn("no autoriza escritura automática", DOCS)
        self.assertIn("BLOCKED_TARGET_ENV", DOCS)

    @staticmethod
    def _s4_payload(state: str) -> dict[str, object]:
        ready = state == "ready"
        return {
            "data": {
                "contract": "media-pilot-readiness-v1",
                "status": "ready" if ready else "not_ready",
                "checks": {
                    "decoder": "ready",
                    "temporary_storage": "ready",
                    "private_vault": "ready" if ready else "not_ready",
                },
                "diagnostics": {"private_vault": state},
                "evidence_scope": "web_runtime",
                "ci_equivalent": False,
            }
        }

    @staticmethod
    def _parser() -> str:
        start = SMOKE.index('if parsed="$(python3 - "$media_readiness_body" "$runtime" <<\'PY\'\n')
        start += len('if parsed="$(python3 - "$media_readiness_body" "$runtime" <<\'PY\'\n')
        end = SMOKE.index("\nPY\n", start)
        return textwrap.dedent(SMOKE[start:end])

    @classmethod
    def _run_parser(cls, raw_json: str) -> subprocess.CompletedProcess[str]:
        with tempfile.NamedTemporaryFile("w", encoding="utf-8") as handle:
            handle.write(raw_json)
            handle.flush()
            return subprocess.run(
                ["python3", "-", handle.name, "s4"],
                input=cls._parser(),
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
        return textwrap.dedent(step[step.index(marker) + len(marker) :])

    @classmethod
    def _run_reconcile(
        cls, smoke_log: str
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
                "    *\" --json comments \"*) printf '' ;;\n"
                "    *) printf 'OPEN\\n' ;;\n"
                "  esac\n"
                "fi\n",
                encoding="utf-8",
            )
            fake_gh.chmod(0o755)
            (root / "production-smoke.log").write_text(smoke_log, encoding="utf-8")
            evidence = root / "evidence.md"
            summary = root / "summary.md"
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{bin_dir}:{env['PATH']}",
                    "GH_LOG": str(gh_log),
                    "GITHUB_SHA": "a" * 40,
                    "GITHUB_RUN_ID": "12345",
                    "GITHUB_SERVER_URL": "https://github.example",
                    "GITHUB_REPOSITORY": "pl0n3r/GrindFlow",
                    "GITHUB_STEP_SUMMARY": str(summary),
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
