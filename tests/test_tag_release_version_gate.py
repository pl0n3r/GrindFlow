"""GrindFlow #451: executable offline release identity and permission contract."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/tag-release-version-gate.py"
WORKFLOW = ROOT / ".github/workflows/tag-release.yml"
ADOPTION = ROOT / "tests/test_release_adoption.py"
spec = importlib.util.spec_from_file_location("grindflow_tag_release_gate", SCRIPT)
assert spec and spec.loader
GATE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(GATE)


class TagReleaseGateTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.repo = Path(temp.name)
        self.git("init", "-q", "--initial-branch=main")
        self.git("config", "user.email", "test@example.invalid")
        self.git("config", "user.name", "Offline QA")
        self.version("0.1.219")
        self.base = self.commit("initial product version")

    def git(self, *args):
        run = subprocess.run(
            ["git", *args], cwd=self.repo, capture_output=True,
            text=True, check=True,
        )
        return run.stdout.strip()

    def write(self, path, content):
        file = self.repo / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(content, encoding="utf-8")

    def version(self, number, date="2026-10-09"):
        self.write(
            "config/version.php",
            "<?php\nreturn [\n  'number' => '" + number +
            "',\n  'released_at' => '" + date + "',\n];\n",
        )

    def commit(self, msg):
        self.git("add", "-A")
        self.git("commit", "-qm", msg)
        return self.git("rev-parse", "HEAD")

    def annotate(self, name="v0.1.219", *, sha=None):
        self.git("tag", "-a", name, "-m", "immutable identity", *( [sha] if sha else []))

    def change(self, path, value="new bytes"):
        self.write(path, value)
        return self.commit(f"modify {path}")

    def test_same_version_with_annotated_ancestor_tag_skips_release(self):
        self.annotate()
        docs = self.change("README.md")
        self.assertIs(GATE.decide_release(self.repo, self.base, docs), False)
        runtime = self.change("src/Runtime.php", "<?php echo 'runtime changed';")
        self.assertIs(GATE.decide_release(self.repo, docs, runtime), False)
        manifest = self.change("package.json", '{"name":"grindflow","version":"0.1.219"}')
        self.assertIs(GATE.decide_release(self.repo, runtime, manifest), False)
        workflow = self.change(".github/workflows/ci.yml", "name: New CI")
        self.assertIs(GATE.decide_release(self.repo, manifest, workflow), False)
        self.assertEqual(self.git("tag", "--list"), "v0.1.219")

    def test_changed_version_requires_factory_release(self):
        self.annotate()
        self.version("0.1.220")
        head = self.commit("deliberate patch")
        self.assertIs(GATE.decide_release(self.repo, self.base, head), True)
        source = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("uses: pl0n3r/factory/.github/workflows/release.yml@v1", source)
        self.assertIn("needs.release-change.outputs.publish == 'true'", source)
        # Factory must never recycle an already existing release identity.
        self.annotate("v0.1.220", sha=head)
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, self.base, head)
        for next_version in ("0.1.221", "0.1.218", "0.3.0", "0.2.1", "1.0.0"):
            with self.subTest(version=next_version):
                self.version(next_version)
                changed = self.commit("invalid release jump")
                with self.assertRaises(GATE.GuardError):
                    GATE.decide_release(self.repo, self.base, changed)
        # A consecutive pre-1.0 minor milestone is an approved new release.
        self.git("tag", "-d", "v0.1.220")
        self.version("0.2.0")
        next_minor = self.commit("deliberate minor")
        self.assertIs(GATE.decide_release(self.repo, self.base, next_minor), True)

    def test_invalid_history_or_missing_release_identity_fails_closed(self):
        docs = self.change("README.md", "new docs")
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, self.base, docs)
        self.git("tag", "v0.1.219")  # lightweight tag is insufficient
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, self.base, docs)
        self.git("tag", "-d", "v0.1.219")
        self.annotate()
        for before, after in (
            ("0" * 40, docs), (self.base, "0" * 40),
            ("g" * 40, docs), (self.base, self.base),
            (docs, self.base), ("f" * 40, docs),
        ):
            with self.subTest(before=before[:9], after=after[:9]):
                with self.assertRaises(GATE.GuardError):
                    GATE.decide_release(self.repo, before, after)
        for text in (
            "<?php return ['number'=>'0.1.219', 'number'=>'0.1.219'];",
            "<?php return ['number'=>'invalid', 'released_at'=>'2026-10-09'];",
            "<?php return ['number'=>'0.01.219', 'released_at'=>'2026-10-09'];",
            "<?php return ['number'=>'0.1.219', 'released_at'=>'2026-02-30'];",
            "<?php return ['number'=>'0.1.219'];",
            "<?php return ['number'=>'1.0.0', 'released_at'=>'2026-10-09'];",
        ):
            with self.subTest(source=text):
                with self.assertRaises(GATE.GuardError):
                    GATE.version_from_text(text)
        # Both whitespace and control characters in NUL-delimited Git paths
        # are rejected rather than stripped into apparently safe identities.
        prev = docs
        for bad_path in ("\nREADME.md", "\rREADME.md", "\tREADME.md", " README.md"):
            with self.subTest(name=repr(bad_path)):
                head = self.change(bad_path, "untrusted path")
                with self.assertRaises(GATE.GuardError):
                    GATE.decide_release(self.repo, prev, head)
                prev = head
        # A tag created on a newer commit is not evidence for the older base.
        self.git("tag", "-d", "v0.1.219")
        self.annotate(sha=prev)
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, self.base, docs)
        self.git("tag", "-d", "v0.1.219")
        self.annotate(sha=self.base)
        self.git("checkout", "-q", "--detach", self.base)
        with self.assertRaises(GATE.GuardError):
            GATE.decide_release(self.repo, self.base, docs)

    def test_workflow_job_and_permission_guards(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        adopted = ADOPTION.read_text(encoding="utf-8")
        expected = adopted.split('CANONICAL_POSTMERGE_CALLER = r"""', 1)[1].split('"""', 1)[0]
        self.assertEqual(source, expected)  # no mutable bypass of canonical adoption
        self.assertIn("on:\n  push:\n    branches: [main]\n", source)
        self.assertNotIn("workflow_dispatch:", source)
        self.assertIn("  current-main:\n", source)
        self.assertIn("  stale-event:\n", source)
        self.assertIn("  release-change:\n", source)
        self.assertIn("    needs: [current-main, release-change]\n", source)
        change_job = source.split("  release-change:\n", 1)[1].split("  release:\n", 1)[0]
        self.assertIn("contents: read", change_job)
        self.assertNotIn("contents: write", change_job)
        self.assertIn("persist-credentials: false", change_job)
        self.assertIn("fetch-depth: 0", change_job)
        self.assertIn("python3 scripts/tag-release-version-gate.py", change_job)
        self.assertIn("--before", change_job)
        self.assertIn("--after", change_job)
        self.assertIn("--output", change_job)
        release_job = source.split("  release:\n", 1)[1]
        self.assertIn("contents: write", release_job)
        self.assertIn("needs.release-change.outputs.publish == 'true'", release_job)
        self.assertIn("pl0n3r/factory/.github/workflows/release.yml@v1", release_job)

    def test_first_install_and_consecutive_non_bump_pushes(self):
        self.annotate()
        before_tags = self.git("show-ref", "--tags")
        for path in (
            ".github/workflows/tag-release.yml",
            "scripts/tag-release-version-gate.py",
            "tests/test_tag_release_version_gate.py",
        ):
            self.write(path, "installed code or workflow")
        installed = self.commit("first install of guarded release caller")
        self.assertIs(GATE.decide_release(self.repo, self.base, installed), False)
        runtime = self.change("app/Http/Controllers/Runtime.php", "<?php echo 'updated';")
        self.assertIs(GATE.decide_release(self.repo, installed, runtime), False)
        tests_only = self.change("tests/release-sample.txt", "test-only update")
        self.assertIs(GATE.decide_release(self.repo, runtime, tests_only), False)
        self.assertEqual(self.git("show-ref", "--tags"), before_tags)
        self.assertEqual(self.git("tag", "--list"), "v0.1.219")
        self.assertEqual(GATE.changed_paths(self.repo, installed, runtime),
                         ("app/Http/Controllers/Runtime.php",))
        # CLI writes only a boolean to a synthetic Actions output, not a ref.
        # Recreate the guard outside this synthetic Git tree for actual CLI.
        outfile = self.repo / "decision.out"
        result = subprocess.run(
            [
                sys.executable, str(SCRIPT), "--before", installed,
                "--after", runtime, "--output", str(outfile),
            ], cwd=self.repo, text=True, capture_output=True, check=False,
        )
        # HEAD now points to tests_only, so stale runtime is denied.
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(outfile.exists())
        result = subprocess.run(
            [
                sys.executable, str(SCRIPT), "--before", runtime,
                "--after", tests_only, "--output", str(outfile),
            ], cwd=self.repo, text=True, capture_output=True, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(outfile.read_text(encoding="utf-8"), "publish=false\n")
        self.assertEqual(self.git("show-ref", "--tags"), before_tags)


if __name__ == "__main__":
    unittest.main()
