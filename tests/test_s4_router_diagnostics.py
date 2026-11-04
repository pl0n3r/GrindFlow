"""Synthetic read-only tests for the manual Hostinger router inspector."""
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/s4-router-diagnostics.sh"
WORKFLOW = Path(__file__).resolve().parents[1] / ".github/workflows/s4-router-diagnostics.yml"


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


    def test_decimal_ssh_port_boundaries_from_workflow(self):
        """Exercise the literal workflow validation without SSH or secrets."""
        source = WORKFLOW.read_text(encoding="utf8").splitlines()
        start = next((index for index, line in enumerate(source)
                      if '[[ "$SSH_PORT" =~ ^[0-9]{1,5}$ ]]' in line), None)
        self.assertIsNotNone(start, "The workflow must validate port characters")
        end = next((index for index in range(start, len(source))
                    if "(( SSH_PORT >= 1 && SSH_PORT <= 65535 ))" in source[index]), None)
        self.assertIsNotNone(end, "The workflow must enforce port bounds")
        commands = "\n".join(line.removeprefix("          ") for line in source[start:end + 1])
        script = 'set -euo pipefail\n' + commands + '\nprintf "%s\\n" "$SSH_PORT"\n'
        for value, expected in (("1", "1"), ("00001", "1"), ("080", "80"),
                                ("09", "9"), ("65535", "65535"), ("0", None),
                                ("65536", None), ("00000", None), ("09x", None),
                                ("-1", None), ("", None), ("000001", None)):
            with self.subTest(port=value):
                result = subprocess.run(["bash", "-c", script], env={**os.environ, "SSH_PORT": value},
                                        text=True, capture_output=True, timeout=5, check=False)
                self.assertEqual(result.returncode == 0, expected is not None, result.stderr)
                if expected is not None:
                    self.assertEqual(result.stdout.strip(), expected)
                else:
                    self.assertEqual(result.stdout, "")

    def test_compiled_route_cache_match_only_in_router_file(self):
        """A synthetic router literal matches; unrelated files and absent routes do not."""
        cache = self.root / "symfony/var/cache/prod"
        cache.mkdir(parents=True)
        route_literal = "/api/admin/library/collections"
        (cache / "cached.php").write_text(f"<?php // {route_literal}\n", encoding="utf8")
        out = self.run_inspection(self.sha, "--read-only")
        self.assertEqual(out.returncode, 0, out.stderr)
        self.assertIn("S4_DIAG_CACHE_ROUTE_COLLECTIONS=UNKNOWN", out.stdout)

        router_file = cache / "routes.php"
        router_file.write_text(f"<?php return ['{route_literal}' => []];\n", encoding="utf8")
        out = self.run_inspection(self.sha, "--read-only")
        self.assertEqual(out.returncode, 0, out.stderr)
        self.assertIn("S4_DIAG_CACHE_ROUTE_COLLECTIONS=MATCH", out.stdout)
        self.assertIn("S4_DIAG_CACHE_ROUTE_PLANS=UNKNOWN", out.stdout)

        router_file.write_text("<?php // other fixture route\n", encoding="utf8")
        out = self.run_inspection(self.sha, "--read-only")
        self.assertEqual(out.returncode, 0, out.stderr)
        self.assertIn("S4_DIAG_CACHE_ROUTE_COLLECTIONS=UNKNOWN", out.stdout)



    def test_ssh_failure_exit_categories_are_sanitized(self):
        """Run the actual workflow's SSH step with a synthetic local ssh stub."""
        source = WORKFLOW.read_text(encoding="utf8").splitlines()
        step = source.index("      - name: Inspect Hostinger without production writes")
        run = next(i for i in range(step + 1, len(source))
                   if source[i] == "        run: |")
        stop = next(i for i in range(run + 1, len(source))
                    if source[i].startswith("      - name: "))
        shell = "\n".join(line[10:] if line.startswith("          ") else line
                          for line in source[run + 1:stop])
        self.assertIn("StrictHostKeyChecking=yes", shell)
        self.assertIn("BatchMode=yes", shell)
        self.assertIn("-- --read-only", shell)

        bindir = self.root / "synthetic-bin"
        bindir.mkdir()
        fake_ssh = bindir / "ssh"
        fake_ssh.write_text(
            "#!/bin/bash\n"
            "printf '%s\\n' \"${MOCK_SSH_STDOUT:-UNSAFE_SYNTHETIC_STDOUT}\"\n"
            "printf '%s\\n' 'UNSAFE_SYNTHETIC_STDERR' >&2\n"
            "exit \"${MOCK_SSH_EXIT}\"\n", encoding="utf8"
        )
        fake_ssh.chmod(0o700)
        scripts = self.root / "scripts"
        scripts.mkdir()
        (scripts / "s4-router-diagnostics.sh").write_text(
            SCRIPT.read_text(encoding="utf8"), encoding="utf8"
        )
        for exit_code, expected, output in (
            (255, "TRANSPORT_OR_REMOTE_255", "UNSAFE_SYNTHETIC_STDOUT"),
            (1, "REMOTE_EXIT_1_EMPTY_STDOUT", ""),
            (1, "REMOTE_EXIT_1", "UNSAFE_SYNTHETIC_STDOUT"),
            (1, "CHECKOUT_DRIFT", "S4_DIAG_CHECKOUT=DRIFT"),
            (1, "CHECKOUT_DRIFT_EXTRA_STDOUT",
             "S4_DIAG_CHECKOUT=DRIFT\nUNSAFE_SYNTHETIC_STDOUT"),
            (1, "REMOTE_EXIT_1", "UNSAFE_SYNTHETIC_STDOUT\nS4_DIAG_CHECKOUT=DRIFT"),
            (1, "REMOTE_EXIT_1", "S4_DIAG_CHECKOUT=DRIFT_FAKE"),
            (1, "REMOTE_EXIT_1", "S4_DIAG_CHECKOUT=DRIFT\rUNSAFE_SYNTHETIC_STDOUT"),
            (1, "HELPER_STARTED_EXIT_1", "S4_DIAG_CHECKOUT=MATCH"),
            (1, "HELPER_STARTED_EXIT_1",
             "S4_DIAG_CHECKOUT=MATCH\nUNSAFE_SYNTHETIC_STDOUT"),
            (1, "REMOTE_EXIT_1", "UNSAFE_SYNTHETIC_STDOUT\nS4_DIAG_CHECKOUT=MATCH"),
            (1, "REMOTE_EXIT_1", "S4_DIAG_CHECKOUT=MATCH_FAKE"),
            (2, "REMOTE_EXIT_2", "UNSAFE_SYNTHETIC_STDOUT"),
            (17, "REMOTE_EXIT_OTHER", "UNSAFE_SYNTHETIC_STDOUT"),
        ):
            with self.subTest(exit_code=exit_code, output=output):
                env = {
                    **os.environ,
                    "PATH": str(bindir) + os.pathsep + os.environ.get("PATH", ""),
                    "MOCK_SSH_EXIT": str(exit_code),
                    "MOCK_SSH_STDOUT": output,
                    "EXPECTED_SHA": self.sha,
                    "SSH_HOST": "synthetic.invalid",
                    "SSH_USER": "synthetic",
                    "SSH_PORT": "22",
                    "GIT_ROOT": "/synthetic/checkout",
                    "DEPLOY_SSH_KEY": "synthetic-private-key",
                    "HOSTINGER_KNOWN_HOSTS": "synthetic-known-hosts",
                    "GITHUB_STEP_SUMMARY": str(self.root / "summary"),
                }
                result = subprocess.run(
                    ["bash", "-c", shell], cwd=self.root, env=env,
                    text=True, capture_output=True, timeout=10, check=False
                )
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertIn(f"failed ({expected}); details withheld.", result.stdout)
                public = result.stdout + result.stderr
                self.assertNotIn("UNSAFE_SYNTHETIC", public)
                self.assertNotIn("S4_DIAG_CHECKOUT=DRIFT", public)
                self.assertNotIn("S4_DIAG_CHECKOUT=MATCH", public)
                self.assertNotIn("synthetic-private-key", public)
                self.assertNotIn("synthetic-known-hosts", public)
                self.assertNotIn("synthetic.invalid", public)
                self.assertNotIn("/synthetic/checkout", public)

if __name__ == "__main__":
    unittest.main()
