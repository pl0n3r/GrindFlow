"""Synthetic read-only tests for the manual Hostinger router inspector."""
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/s4-router-diagnostics.sh"


class S4RouterDiagnosticsTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        subprocess.run(["git", "init", "-q"], cwd=self.root, check=True)
        subprocess.run(["git", "config", "user.email", "synthetic@example.test"], cwd=self.root, check=True)
        subprocess.run(["git", "config", "user.name", "Fixture"], cwd=self.root, check=True)
        route_file = self.root / "symfony/config/routes.yaml"
        route_file.parent.mkdir(parents=True)
        route_file.write_text("controllers: []\n", encoding="utf8")
        controller = self.root / "symfony/src/Http/Controller/LibraryCollectionsController.php"
        controller.parent.mkdir(parents=True)
        controller.write_text("<?php // fixture\n", encoding="utf8")
        subprocess.run(["git", "add", "."], cwd=self.root, check=True)
        subprocess.run(["git", "commit", "-qm", "fixture"], cwd=self.root, check=True)
        self.sha = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=self.root, text=True).strip()

    def run_inspection(self, sha: str | None, *args: str):
        env = os.environ.copy()
        env.pop("EXPECTED_SHA", None)
        if sha is not None:
            env["EXPECTED_SHA"] = sha
        return subprocess.run(["bash", str(SCRIPT), *args], cwd=self.root, env=env,
                              text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              timeout=15, check=False)

    def test_read_only_sanitized_states(self):
        before = subprocess.check_output(["git", "status", "--porcelain"], cwd=self.root, text=True)
        out = self.run_inspection(self.sha, "--read-only")
        self.assertEqual(out.returncode, 0, out.stderr)
        self.assertIn("S4_DIAG_CHECKOUT=MATCH", out.stdout)
        self.assertIn("S4_DIAG_ROUTE_IMPORT=MATCH", out.stdout)
        self.assertIn("S4_DIAG_CONTROLLER_COLLECTIONS=MATCH", out.stdout)
        self.assertIn("S4_DIAG_CONTROLLER_PLANS=MISSING", out.stdout)
        self.assertIn("S4_DIAG_CACHE=MISSING", out.stdout)
        self.assertIn("S4_DIAG_READ_ONLY=MATCH", out.stdout)
        self.assertNotIn(str(self.root), out.stdout + out.stderr)
        self.assertNotIn(self.sha, out.stdout + out.stderr)
        after = subprocess.check_output(["git", "status", "--porcelain"], cwd=self.root, text=True)
        self.assertEqual(before, after)

    def test_rejects_wrong_sha_and_arguments(self):
        self.assertEqual(self.run_inspection("0" * 40, "--read-only").returncode, 1)
        self.assertEqual(self.run_inspection(None, "--read-only").returncode, 2)
        self.assertEqual(self.run_inspection(self.sha, "--change-cache").returncode, 2)

    def test_detects_worktree_drift_without_modifying_it(self):
        file = self.root / "symfony/config/routes.yaml"
        file.write_text("controllers: [missing]\n", encoding="utf8")
        content_before = file.read_bytes()
        result = self.run_inspection(self.sha, "--read-only")
        self.assertEqual(result.returncode, 0)
        self.assertIn("S4_DIAG_ROUTE_IMPORT=DRIFT", result.stdout)
        self.assertEqual(content_before, file.read_bytes())


    def test_each_diagnostic_value_is_in_the_approved_four_categories(self):
        """Every output line is categorical, even on negative paths."""
        import re

        for case in (
            (self.sha, ("--read-only",)),
            ("0" * 40, ("--read-only",)),
            (None, ("--read-only",)),
            (self.sha, ("--not-allowed",)),
        ):
            with self.subTest(case=case):
                result = self.run_inspection(case[0], *case[1])
                for line in result.stdout.splitlines():
                    self.assertRegex(
                        line, r"^S4_DIAG_[A-Z_]+=(MATCH|DRIFT|MISSING|UNKNOWN)$"
                    )
                self.assertNotIn(self.sha, result.stdout + result.stderr)
                self.assertNotIn(str(self.root), result.stdout + result.stderr)

    def test_missing_route_literals_are_unknown_not_runtime_failure(self):
        """Compiled Symfony routes may omit literal names after optimization."""
        cache = self.root / "symfony/var/cache/prod"
        cache.mkdir(parents=True)
        (cache / "cached.php").write_text("<?php // optimized routes\n", encoding="utf8")
        out = self.run_inspection(self.sha, "--read-only")
        self.assertEqual(out.returncode, 0)
        self.assertIn("S4_DIAG_CACHE=MATCH", out.stdout)
        self.assertIn("S4_DIAG_CACHE_ROUTE_COLLECTIONS=UNKNOWN", out.stdout)


if __name__ == "__main__":
    unittest.main()
