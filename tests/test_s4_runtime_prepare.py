#!/usr/bin/env python3
"""Contracts for exact-SHA Symfony runtime preparation on the hPanel Git path."""
from __future__ import annotations

import os
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "prepare-s4-runtime.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "s4-runtime-prepare.yml"
DEPLOY_DOC = ROOT / "docs" / "DEPLOY-HOSTINGER.md"


class S4RuntimePrepareTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.script = SCRIPT.read_text(encoding="utf-8")
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.deploy_doc = DEPLOY_DOC.read_text(encoding="utf-8")

    def _write_executable(self, path: Path, content: str) -> None:
        path.write_text(content, encoding="utf-8")
        path.chmod(path.stat().st_mode | stat.S_IXUSR)

    def _fixture(
        self, *, previous_vendor: bool = False
    ) -> tuple[tempfile.TemporaryDirectory, Path, str, Path, Path]:
        tmp = tempfile.TemporaryDirectory()
        repo = Path(tmp.name)
        symfony = repo / "symfony"
        symfony.mkdir()
        (symfony / "composer.json").write_text('{"require":{}}\n', encoding="utf-8")
        (symfony / "composer.lock").write_text('{"packages":[]}\n', encoding="utf-8")
        (repo / ".env").write_text("APP_PHASE=construccion\n", encoding="utf-8")
        if previous_vendor:
            vendor = symfony / "vendor"
            vendor.mkdir()
            (vendor / "autoload.php").write_text("old-runtime\n", encoding="utf-8")

        subprocess.run(["git", "init", "-q"], cwd=repo, check=True)
        subprocess.run(
            ["git", "config", "user.email", "ci@grindflow.test"], cwd=repo, check=True
        )
        subprocess.run(
            ["git", "config", "user.name", "GrindFlow CI"], cwd=repo, check=True
        )
        subprocess.run(["git", "add", "."], cwd=repo, check=True)
        subprocess.run(["git", "commit", "-qm", "fixture"], cwd=repo, check=True)
        sha = subprocess.check_output(
            ["git", "rev-parse", "HEAD"], cwd=repo, text=True
        ).strip()

        bin_dir = repo / "fake-bin"
        bin_dir.mkdir()
        composer = bin_dir / "composer2"
        php = bin_dir / "php"
        self._write_executable(
            composer,
            "#!/usr/bin/env bash\n"
            "set -euo pipefail\n"
            '[[ "${FAKE_COMPOSER_FAIL:-0}" != 1 ]] || exit 42\n'
            'mkdir -p "$COMPOSER_VENDOR_DIR"\n'
            "printf '%s\\n' '<?php return true;' > "
            '"$COMPOSER_VENDOR_DIR/autoload.php"\n'
            'if [[ "${FAKE_COMPOSER_CHANGE_HEAD:-0}" == 1 ]]; then\n'
            '  printf "%s\\n" drift > runtime-drift.txt\n'
            '  git add runtime-drift.txt\n'
            '  git commit -qm "runtime drift"\n'
            'fi\n',
        )
        self._write_executable(
            php,
            "#!/usr/bin/env bash\n"
            "set -euo pipefail\n"
            'if [[ "$*" == *\'/symfony/vendor/autoload.php\'* ]]; then\n'
            '  if [[ "${FAKE_PHP_SIGNAL_PARENT:-}" == TERM ]]; then kill -TERM "$PPID"; exit 0; fi\n'
            '  if [[ "${FAKE_PHP_FAIL_FINAL:-0}" == 1 ]]; then exit 5; fi\n'
            'fi\n'
            "exit 0\n",
        )
        return tmp, repo, sha, composer, php

    def _run(
        self, repo: Path, sha: str, composer: Path, php: Path, **extra: str
    ) -> subprocess.CompletedProcess[str]:
        env = os.environ.copy()
        env.update(
            {
                "EXPECTED_SHA": sha,
                "COMPOSER_BIN": str(composer),
                "PHP_BIN": str(php),
                **extra,
            }
        )
        return subprocess.run(
            ["bash", str(SCRIPT)],
            cwd=repo,
            env=env,
            text=True,
            capture_output=True,
            timeout=30,
            check=False,
        )

    def test_prepare_is_exact_sha_locked_and_installs_only_locked_symfony_dependencies(
        self,
    ) -> None:
        syntax = subprocess.run(
            ["bash", "-n", str(SCRIPT)], text=True, capture_output=True, check=False
        )
        self.assertEqual(syntax.returncode, 0, syntax.stderr)
        for snippet in (
            'git -C "$root" rev-parse HEAD',
            'composer_lock="$root/symfony/composer.lock"',
            'COMPOSER_VENDOR_DIR="$stage"',
            "--no-dev --prefer-dist --no-interaction --optimize-autoloader",
            "--no-scripts --no-plugins",
        ):
            self.assertIn(snippet, self.script)
        self.assertNotIn("composer update", self.script)

        tmp, repo, sha, composer, php = self._fixture()
        try:
            result = self._run(repo, sha, composer, php)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(
                (repo / "symfony/vendor/autoload.php").read_text(encoding="utf-8"),
                "<?php return true;\n",
            )
            wrong = self._run(repo, "0" * 40, composer, php)
            self.assertNotEqual(wrong.returncode, 0)
            self.assertIn("checkout-sha-mismatch", wrong.stderr)
        finally:
            tmp.cleanup()

    def test_failed_prepare_preserves_previous_vendor_and_cleans_private_temporaries(
        self,
    ) -> None:
        tmp, repo, sha, composer, php = self._fixture(previous_vendor=True)
        try:
            result = self._run(
                repo, sha, composer, php, FAKE_PHP_FAIL_FINAL="1"
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(
                (repo / "symfony/vendor/autoload.php").read_text(encoding="utf-8"),
                "old-runtime\n",
            )
            self.assertEqual(list((repo / "symfony").glob(".vendor-stage-*")), [])
            self.assertEqual(list((repo / "symfony").glob(".vendor-backup-*")), [])
        finally:
            tmp.cleanup()

    def test_signal_during_promoted_runtime_rolls_back_and_never_reports_success(
        self,
    ) -> None:
        tmp, repo, sha, composer, php = self._fixture(previous_vendor=True)
        try:
            result = self._run(
                repo, sha, composer, php, FAKE_PHP_SIGNAL_PARENT="TERM"
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn("S4_RUNTIME_PREPARE_OK", result.stdout)
            self.assertEqual(
                (repo / "symfony/vendor/autoload.php").read_text(encoding="utf-8"),
                "old-runtime\n",
            )
            self.assertEqual(list((repo / "symfony").glob(".vendor-stage-*")), [])
            self.assertEqual(list((repo / "symfony").glob(".vendor-backup-*")), [])
        finally:
            tmp.cleanup()

    def test_prepare_aborts_before_vendor_promotion_if_checkout_changes_mid_run(
        self,
    ) -> None:
        tmp, repo, sha, composer, php = self._fixture(previous_vendor=True)
        try:
            result = self._run(
                repo, sha, composer, php, FAKE_COMPOSER_CHANGE_HEAD="1"
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("checkout-sha-changed-during-prepare", result.stderr)
            self.assertEqual(
                (repo / "symfony/vendor/autoload.php").read_text(encoding="utf-8"),
                "old-runtime\n",
            )
            self.assertEqual(list((repo / "symfony").glob(".vendor-stage-*")), [])
            self.assertEqual(list((repo / "symfony").glob(".vendor-backup-*")), [])
        finally:
            tmp.cleanup()

    def test_automation_requires_successful_exact_deploy_observer_and_construction_phase(
        self,
    ) -> None:
        for snippet in (
            'workflows: ["GrindFlow Deploy Observer"]',
            "github.event.workflow_run.conclusion == 'success'",
            "github.event.workflow_run.event == 'push'",
            "github.event.workflow_run.repository.full_name == github.repository",
            "github.event.workflow_run.head_sha",
            "git ls-remote --heads",
            "trusted_sha=$trusted_sha",
            "raw.githubusercontent.com/$GITHUB_REPOSITORY/$TRUSTED_SHA/",
            "steps.current.outputs.current == 'true'",
            "Reconfirm trusted current-main SHA after preparation",
            "checkout-sha-changed-during-prepare",
            "APP_PHASE",
            "phase-not-construction",
        ):
            self.assertIn(snippet, self.workflow + self.script)
        self.assertNotIn("actions/checkout@", self.workflow)

    def test_prepare_never_migrates_provisions_changes_secrets_or_enables_factory_cutover(
        self,
    ) -> None:
        runtime = (self.script + "\n" + self.workflow).lower()
        for forbidden in (
            "doctrine:migrations",
            "artisan migrate",
            "migrate --force",
            "provision-smoke-user",
            "factory_deploy_enabled=true",
            "workflow_dispatch",
        ):
            self.assertNotIn(forbidden, runtime)
        self.assertNotIn('>> "$root/.env"', self.script)
        self.assertNotIn("sed -i", self.script)
        self.assertIn("StrictHostKeyChecking=yes", self.workflow)

    def test_post_prepare_probe_is_read_only_and_preserves_non_runtime_blockers(
        self,
    ) -> None:
        self.assertIn("/s4/_bridge-readiness", self.workflow)
        self.assertIn("200:ready_for_web_probe", self.workflow)
        for state in ("config_missing", "schema_missing", "identity_unavailable"):
            self.assertIn(f"503:{state}", self.workflow)
        self.assertNotIn("-X POST", self.workflow)
        self.assertNotIn("--request POST", self.workflow)

    def test_docs_distinguish_exact_checkout_from_symfony_dependency_readiness(
        self,
    ) -> None:
        for statement in (
            "checkout Git exacto no demuestra que `symfony/vendor` esté preparado",
            "no cambia la autoridad de deploy",
            "no ejecuta migraciones Doctrine",
        ):
            self.assertIn(statement, self.deploy_doc)


if __name__ == "__main__":
    unittest.main()
