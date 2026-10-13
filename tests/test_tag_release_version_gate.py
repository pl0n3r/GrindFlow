"""GrindFlow #464: regresiones offline del gate posmerge, sin tags reales."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "scripts/tag-release-version-gate.py"
SPEC = importlib.util.spec_from_file_location("tag_release_gate", SOURCE)
assert SPEC and SPEC.loader
GATE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GATE)
WORKFLOW = ROOT / ".github/workflows/tag-release.yml"


class TagReleaseGateTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.command("init", "-q", "--initial-branch=main")
        self.command("config", "user.email", "test@example.invalid")
        self.command("config", "user.name", "QA")
        self.version("0.1.219")
        self.base = self.commit("initial")

    def command(self, *args):
        result = subprocess.run(["git", *args], cwd=self.repo, capture_output=True, text=True, check=True)
        return result.stdout.strip()

    def version(self, value):
        path = self.repo / "config/version.php"
        path.parent.mkdir(exist_ok=True)
        path.write_text("<?php\nreturn [\n 'number' => '" + value + "',\n];\n", encoding="utf-8")

    def commit(self, message):
        self.command("add", "-A")
        self.command("commit", "-qm", message)
        return self.command("rev-parse", "HEAD")

    def docs(self, name="README.md"):
        (self.repo / name).write_text("docs", encoding="utf-8")
        return self.commit("docs")

    def tagged_base(self, *, annotated=True):
        args = ["tag"] + (["-a", "v0.1.219", "-m", "real"] if annotated else ["v0.1.219"])
        self.command(*args)

    def test_same_version_with_annotated_ancestor_tag_skips_release(self):
        self.tagged_base()
        first = self.docs()
        self.assertIs(GATE.decide_release(self.repo, self.base, first), False)
        second = self.docs("NOTES.md")
        self.assertIs(GATE.decide_release(self.repo, first, second), False)
        # Un diff de runtime o manifests sin bump no es una edición documental.
        (self.repo / "src").mkdir()
        (self.repo / "src/App.php").write_text("<?php echo 1;", encoding="utf-8")
        runtime = self.commit("runtime without bump")
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, second, runtime)
        (self.repo / "package.json").write_text('{"version":"0.1.220"}', encoding="utf-8")
        manifest = self.commit("manifest without bump")
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, runtime, manifest)

    def _assert_document_prefix_gate(self, initial):
        path = self.repo / "readme/guide.md"
        path.parent.mkdir(parents=True)
        path.write_text("safe reference", encoding="utf-8")
        previous = self.commit("readme markdown without bump")
        self.assertIs(GATE.decide_release(self.repo, initial, previous), False)

        # Un nombre dentro de docs/readme no hace seguro un manifest.
        # Los workflows son código ejecutable aunque se llamen README.
        for relative in (
            "readme/project.json", "docs/manifest.yml",
            "readme/build.sh", ".github/workflows/readme-contract.yml",
        ):
            with self.subTest(path=relative):
                target = self.repo / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text("unsafe without version bump", encoding="utf-8")
                changed = self.commit("manifest or workflow without bump")
                with self.assertRaises(GATE.GuardError):
                    GATE.decide_release(self.repo, previous, changed)
                previous = changed

    def test_document_prefix_never_hides_manifest_or_workflow_without_bump(self):
        self.tagged_base()
        self._assert_document_prefix_gate(self.base)

    def test_changed_version_requires_factory_release(self):
        self.tagged_base()
        self.version("0.1.220")
        changed = self.commit("new version")
        self.assertIs(GATE.decide_release(self.repo, self.base, changed), True)
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("uses: pl0n3r/factory/.github/workflows/release.yml@v1", workflow)

    def _assert_first_installation_rejects_unsafe_skip(self, previous):
        # El primer push que instala el preflight también cambia código/CI.
        # Sin nueva identidad de versión debe fallar cerrado, nunca skip.
        for relative in (
            ".github/workflows/tag-release.yml",
            "scripts/tag-release-version-gate.py",
        ):
            path = self.repo / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("bootstrap executable contract", encoding="utf-8")
        installed = self.commit("install release preflight without bump")
        with self.assertRaisesRegex(
            GATE.GuardError, "Cambio ejecutable sin incremento de versión"
        ):
            GATE.decide_release(self.repo, previous, installed)
        return installed

    def test_first_preflight_installation_requires_version_bump(self):
        self.tagged_base()
        first = self.docs()
        self.assertIs(GATE.decide_release(self.repo, self.base, first), False)
        installed = self._assert_first_installation_rejects_unsafe_skip(first)
        # Un bump posterior sigue pidiendo el reusable de release normal;
        # no se añade ninguna excepción para silencios del primer push.
        self.version("0.1.220")
        bumped = self.commit("new version after preflight")
        self.assertIs(GATE.decide_release(self.repo, installed, bumped), True)

    def test_invalid_history_or_missing_release_identity_fails_closed(self):
        first = self.docs()
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, self.base, first)  # sin tag
        self.command("tag", "v0.1.219")
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, self.base, first)  # tag lightweight
        self.command("tag", "-d", "v0.1.219")
        self.tagged_base()
        for before, after in (
            ("0" * 40, first), (self.base, "0" * 40),
            ("g" * 40, first), (self.base, self.base),
            (first, self.base), ("f" * 40, first),
        ):
            with self.subTest(before=before[:8], after=after[:8]):
                with self.assertRaises(GATE.GuardError):
                    GATE.decide_release(self.repo, before, after)
        # AC-03 también ejecuta el negativo de manifests/workflows sin bump.
        # fast sigue roto por #460; aceptación debe probar el guard por separado.
        self._assert_document_prefix_gate(first)
        # Nombres del diff -z con control inicial no son el README autorizado.
        # Estos casos pertenecen al AC-03, que debe fallar cerrado.
        previous = self.command("rev-parse", "HEAD")
        for unsafe_name in (
            "\nREADME.md", "\rREADME.md", "\tREADME.md", " README.md",
            # A directory with a leading space must not be normalized to docs/.
            # This is the original -z/strip regression, even for a doc suffix.
            " docs/hook.php", " docs/guide.md",
        ):
            target = self.repo / unsafe_name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("unsafe filename", encoding="utf-8")
            changed = self.commit("ambiguous filename must fail closed")
            with self.subTest(unsafe_path=repr(unsafe_name)):
                with self.assertRaises(GATE.GuardError):
                    GATE.decide_release(self.repo, previous, changed)
            previous = changed
        # AC-03 debe certificar el primer push que instala el gate.
        previous = self._assert_first_installation_rejects_unsafe_skip(previous)
        self.version("0.1.218")
        down = self.commit("downgrade")
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, first, down)
        with self.assertRaises(GATE.GuardError):
            GATE.version_from_text("<?php\n'number'=>'0.1.219',\n'number'=>'0.1.220',")
        with self.assertRaises(GATE.GuardError):
            GATE.version_from_text("<?php\n'number'=>'unknown',")

    def test_workflow_job_and_permission_guards(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("on:\n  push:\n    branches: [main]", workflow)
        self.assertIn("  current-main:\n", workflow)
        self.assertIn("  stale-event:\n", workflow)
        self.assertIn("  release-change:\n", workflow)
        self.assertIn("    needs: current-main\n", workflow)
        self.assertIn("    needs: [current-main, release-change]\n", workflow)
        gate = workflow.split("  release-change:\n", 1)[1].split("  release:\n", 1)[0]
        self.assertIn("contents: read", gate)
        self.assertNotIn("contents: write", gate)
        self.assertIn("persist-credentials: false", gate)
        self.assertIn("fetch-depth: 0", gate)
        self.assertIn("python3 scripts/tag-release-version-gate.py", gate)
        self.assertIn("--before", gate)
        self.assertIn("--after", gate)
        self.assertIn("--output", gate)
        release = workflow.split("  release:\n", 1)[1]
        self.assertIn("contents: write", release)
        self.assertIn("needs.release-change.outputs.publish == 'true'", release)
        self.assertIn("uses: pl0n3r/factory/.github/workflows/release.yml@v1", release)
        self.assertIn("version_source: config/version.php", release)
        self.assertIn("version_format: php-array", release)
        self.assertNotIn("workflow_dispatch:", workflow)


if __name__ == "__main__":
    unittest.main()
