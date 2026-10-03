from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
GUARD = ROOT / "scripts/current-main-event-guard.sh"
OBSERVER = ROOT / ".github/workflows/production-deploy-observer.yml"
SMOKE = ROOT / ".github/workflows/production-smoke.yml"
TAG = ROOT / ".github/workflows/tag-release.yml"
DEPLOY = ROOT / ".github/workflows/deploy-factory.yml"
DOCS = ROOT / "docs/DEPLOY-HOSTINGER.md"
PACKAGE = ROOT / "package.json"


class CurrentMainEventGuardTests(unittest.TestCase):
    def run_guard(self, event_sha: str, remote_sha: str):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            fake_git = tmp_path / "git"
            fake_git.write_text(
                "#!/bin/sh\n"
                "printf '%s\\trefs/heads/main\\n' \"$REMOTE_SHA\"\n",
                encoding="utf-8",
            )
            fake_git.chmod(0o755)
            output = tmp_path / "output"
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{tmp}:{env.get('PATH', '')}",
                    "REMOTE_SHA": remote_sha,
                    "GITHUB_EVENT_NAME": "push",
                    "GITHUB_SHA": event_sha,
                    "GITHUB_REPOSITORY": "pl0n3r/GrindFlow",
                    "DEFAULT_BRANCH": "main",
                    "GITHUB_OUTPUT": str(output),
                }
            )
            result = subprocess.run(
                ["bash", str(GUARD)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
            values = {}
            if output.exists():
                for line in output.read_text(encoding="utf-8").splitlines():
                    key, value = line.split("=", 1)
                    values[key] = value
            return result, values

    def test_guard_rejects_stale_and_invalid_remote_head(self):
        current = "a" * 40
        stale = "b" * 40

        result, values = self.run_guard(current, current)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values["current"], "true")
        self.assertEqual(values["stale"], "false")

        result, values = self.run_guard(stale, current)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values["current"], "false")
        self.assertEqual(values["stale"], "true")

        result, _ = self.run_guard(current, "not-a-sha")
        self.assertNotEqual(result.returncode, 0)

    def test_production_observer_and_smoke_gate_before_io(self):
        for path, job in ((OBSERVER, "observe"), (SMOKE, "production-smoke")):
            text = path.read_text(encoding="utf-8")
            self.assertIn("current-main:", text)
            self.assertIn("bash scripts/current-main-event-guard.sh", text)
            marker = f"  {job}:"
            body = text[text.index(marker):]
            self.assertIn("needs: current-main", body)
            self.assertIn("needs.current-main.outputs.current == 'true'", body)

    def test_tag_release_gates_stale_push(self):
        text = TAG.read_text(encoding="utf-8")
        self.assertIn("current-main:", text)
        release = text[text.index("  release:"):]
        self.assertIn("needs: current-main", release)
        self.assertIn("needs.current-main.outputs.current == 'true'", release)

    def test_factory_deploy_requires_current_main_and_explicit_flag(self):
        text = DEPLOY.read_text(encoding="utf-8")
        self.assertIn("push:", text)
        self.assertIn("branches: [main]", text)
        self.assertIn("bash scripts/current-main-event-guard.sh", text)
        deploy = text[text.index("  deploy:"):]
        self.assertIn("needs: current-main", deploy)
        self.assertIn("needs.current-main.outputs.current == 'true'", deploy)
        self.assertIn("vars.FACTORY_DEPLOY_ENABLED == 'true'", deploy)

    def test_hostinger_runbook_keeps_external_cutover_explicit(self):
        text = DOCS.read_text(encoding="utf-8")
        self.assertIn("desactivar el auto-redeploy Git/hPanel antes de habilitar", text)
        self.assertIn("nunca mantener dos autoridades de deploy", text)
        self.assertIn("no puede demostrar por sí mismo", text)

    def test_regression_is_wired_into_npm_test(self):
        text = PACKAGE.read_text(encoding="utf-8")
        self.assertIn(
            "python3 -m unittest tests/test_current_main_event_guard.py",
            text,
        )


if __name__ == "__main__":
    unittest.main()
