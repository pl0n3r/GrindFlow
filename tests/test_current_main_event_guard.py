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
    def run_guard(self, event_sha: str, remote_sha: str, event_name: str = "push"):
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
                    "GITHUB_EVENT_NAME": event_name,
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

    def test_guard_accepts_workflow_dispatch_and_rejects_other_events(self):
        current = "c" * 40

        result, values = self.run_guard(current, current, "workflow_dispatch")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(values["current"], "true")

        result, _ = self.run_guard(current, current, "pull_request")
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

    def _release_profile_fixtures(self):
        """El perfil anterior y el posmerge se comprueban en ambos HEAD."""
        actual = TAG.read_text(encoding="utf-8")
        if "\n  release-change:\n" in actual:
            modern = actual
            prefix, tail = actual.split("\n  release-change:\n", 1)
            _, release = tail.split("\n  release:\n", 1)
            legacy = (
                prefix + "\n  release:\n" + release
                .replace("    needs: [current-main, release-change]\n",
                         "    needs: current-main\n", 1)
                .replace(" && needs.release-change.outputs.publish == 'true'", "", 1)
            )
        else:
            legacy = actual
            prefix, release = actual.split("\n  release:\n", 1)
            new_release = (
                release
                .replace("    needs: current-main\n",
                         "    needs: [current-main, release-change]\n", 1)
                .replace("needs.current-main.outputs.current == 'true'",
                         "needs.current-main.outputs.current == 'true'"
                         " && needs.release-change.outputs.publish == 'true'", 1)
            )
            gate = (
                "\n  release-change:\n"
                "    needs: current-main\n"
                "    permissions:\n"
                "      contents: read\n"
                "    outputs:\n"
                "      publish: " + "$" + "{{ steps.decision.outputs.publish }}" + "\n"
                "    steps:\n"
                "      - id: decision\n"
                "        run: python3 scripts/tag-release-version-gate.py --before A --after B\n"
            )
            modern = prefix + gate + "\n  release:\n" + new_release
        return legacy, modern, actual

    def _assert_tag_release_contract(self, source):
        self.assertIn("\n  current-main:\n", source)
        self.assertIn("\n  release:\n", source)
        release = source.split("\n  release:\n", 1)[1]
        self.assertIn("needs.current-main.outputs.current == 'true'", release)
        self.assertIn("uses: pl0n3r/factory/.github/workflows/release.yml@v1", release)
        self.assertIn("    permissions:\n      contents: write\n", release)
        marker = "\n  release-change:\n"
        if marker in source:
            self.assertEqual(source.count(marker), 1)
            gate = source.split(marker, 1)[1].split("\n  release:\n", 1)[0]
            self.assertIn("    needs: current-main\n", gate)
            self.assertIn("    permissions:\n      contents: read\n", gate)
            self.assertIn("      publish: " + "$" + "{{ steps.decision.outputs.publish }}", gate)
            self.assertIn("python3 scripts/tag-release-version-gate.py", gate)
            self.assertIn("    needs: [current-main, release-change]\n", release)
            self.assertIn("needs.release-change.outputs.publish == 'true'", release)
        else:
            self.assertIn("    needs: current-main\n", release)
            self.assertNotIn("needs.release-change.outputs.publish", release)

    def test_tag_release_gates_stale_push(self):
        legacy, modern, actual = self._release_profile_fixtures()
        for name, source in (("legacy", legacy), ("postmerge", modern),
                             ("actual", actual)):
            with self.subTest(profile=name):
                self._assert_tag_release_contract(source)

    def test_tag_release_rejects_weakened_dependencies(self):
        legacy, modern, _ = self._release_profile_fixtures()
        for name, original, mutated in (
            ("legacy_without_current_main", legacy,
             legacy.replace("    needs: current-main\n",
                            "    needs: stale-event\n", 1)),
            ("legacy_without_stale_guard", legacy,
             legacy.replace("needs.current-main.outputs.current == 'true'",
                            "true", 1)),
            ("legacy_unpinned_reusable", legacy,
             legacy.replace("release.yml@v1", "release.yml@main", 1)),
            ("postmerge_without_release_gate", modern,
             modern.replace("    needs: [current-main, release-change]\n",
                            "    needs: current-main\n", 1)),
            ("postmerge_without_current_main", modern,
             modern.replace("    needs: [current-main, release-change]\n",
                            "    needs: release-change\n", 1)),
            ("postmerge_without_publish_guard", modern,
             modern.replace(" && needs.release-change.outputs.publish == 'true'",
                            "", 1)),
            ("postmerge_without_stale_guard", modern,
             modern.replace("needs.current-main.outputs.current == 'true'",
                            "true", 1)),
            ("postmerge_unpinned_reusable", modern,
             modern.replace("release.yml@v1", "release.yml@main", 1)),
            ("postmerge_escalated_gate", modern,
             modern.replace("\n  release-change:\n"
                            "    needs: current-main\n"
                            "    permissions:\n"
                            "      contents: read\n",
                            "\n  release-change:\n"
                            "    needs: current-main\n"
                            "    permissions:\n"
                            "      contents: write\n", 1)),
        ):
            with self.subTest(profile=name):
                self.assertNotEqual(original, mutated)
                with self.assertRaises(AssertionError):
                    self._assert_tag_release_contract(mutated)

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
        verify = text.index("verificar por canal privado")
        disable = text.index("desactivar el auto-redeploy Git/hPanel")
        enable = text.index("habilitar `FACTORY_DEPLOY_ENABLED=true`")
        manual = text.index("ejecutar una prueba manual del deploy Factory")
        self.assertLess(verify, disable)
        self.assertLess(disable, enable)
        self.assertLess(enable, manual)
        self.assertIn("nunca mantener dos", text)
        self.assertIn("autoridades de deploy", text)
        self.assertIn("no puede demostrar por sí mismo que el paso 2 ocurrió", text)

    def test_regression_is_wired_into_npm_test(self):
        text = PACKAGE.read_text(encoding="utf-8")
        self.assertIn(
            "python3 -m unittest tests/test_current_main_event_guard.py",
            text,
        )


if __name__ == "__main__":
    unittest.main()
