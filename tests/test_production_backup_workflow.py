from __future__ import annotations

from pathlib import Path
import os
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/run-production-backup.sh"
WORKFLOW = ROOT / ".github/workflows/production-backup.yml"
CI = ROOT / ".github/workflows/grindflow-ci.yml"
S4 = ROOT / "public/s4.php"
CONSOLE = ROOT / "routes/console.php"


class ProductionBackupWorkflowTests(unittest.TestCase):
    def test_script_fails_closed_and_validates_remote_preconditions(self):
        script = SCRIPT.read_text(encoding="utf-8")

        for signal in (
            "StrictHostKeyChecking=yes",
            "UserKnownHostsFile=",
            '[[ -L "$current" ]]',
            'case "$release" in',
            'release="$(readlink -f "$current")"',
            'php_bin="$8"',
            '-f vendor/autoload.php',
            "database dump utility is unavailable",
            "pending migration count changed",
            '[[ "$fingerprint" =~ ^[0-9a-f]{64}$ ]]',
            '[[ "$HOSTINGER_RELEASE_ROOT" =~ ^(/[A-Za-z0-9._-]+)+$ ]]',
        ):
            self.assertIn(signal, script)

        self.assertGreaterEqual(script.count('require "vendor/autoload.php";'), 2)

        base_env = {
            **os.environ,
            "HOSTINGER_SSH_HOST": "example.test",
            "HOSTINGER_SSH_USER": "deploy",
            "HOSTINGER_SSH_PORT": "22",
            "SSH_KEY_PATH": "/tmp/grindflow-test-key",
            "KNOWN_HOSTS_PATH": "/tmp/grindflow-test-known-hosts",
            "EXPECTED_PENDING": "1",
        }
        for unsafe_root in (
            "/home/deploy/domain with space",
            "/home/deploy/domain;touch-pwned",
            "/home/deploy/domain$HOME",
        ):
            result = subprocess.run(
                ["/bin/bash", str(SCRIPT)],
                env={**base_env, "HOSTINGER_RELEASE_ROOT": unsafe_root},
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(result.returncode, 2, unsafe_root)
            self.assertIn("invalid release root", result.stderr)

    def test_dump_is_private_atomic_and_gzip_verified(self):
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("storage/app/private/operations/database-backups", script)
        self.assertIn("umask 077", script)
        self.assertIn("gzip -t", script)
        self.assertIn('chmod 600 "$tmp_archive"', script)
        self.assertIn('ln -- "$tmp_archive" "$archive_path"', script)
        self.assertNotIn('mv -f "$tmp_archive" "$archive_path"', script)
        self.assertNotIn("actions/upload-artifact", script)

    def test_remote_backup_behavior_is_unique_verified_and_cleans_failed_receipt(self):
        script = SCRIPT.read_text(encoding="utf-8")
        remote = script.split("<<'REMOTE'\n", 1)[1].rsplit("\nREMOTE", 1)[0]
        fingerprint = "a" * 64
        receipt = "b" * 64

        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            root = tmp_path / "release-root"
            release = root / "releases" / "r1"
            (release / "bootstrap").mkdir(parents=True)
            (release / "vendor").mkdir()
            for relative in ("artisan", "bootstrap/app.php", "vendor/autoload.php"):
                (release / relative).write_text("fixture\n", encoding="utf-8")
            (root / "current").symlink_to(release, target_is_directory=True)

            fake_bin = tmp_path / "bin"
            fake_bin.mkdir()
            fake_php = fake_bin / "php"
            fake_php.write_text(
                textwrap.dedent(
                    """\
                    #!/usr/bin/env bash
                    set -euo pipefail
                    if [[ "${1:-}" == "-r" ]]; then
                      code="${2:-}"
                      shift 2
                      if [[ "$code" == *"MigrationReadiness::class"* ]]; then
                        printf '%s|%s' "${FAKE_PENDING:-1}" "$FAKE_FINGERPRINT"
                        exit 0
                      fi
                      if [[ "$code" == *"database.connections."* ]]; then
                        credentials="$1"
                        database_file="$2"
                        printf '[client]\\nhost="localhost"\\nport=3306\\nuser="fixture"\\npassword="fixture"\\n' > "$credentials"
                        printf 'grindflow_test' > "$database_file"
                        chmod 600 "$credentials" "$database_file"
                        exit 0
                      fi
                    fi
                    if [[ "${1:-}" == "artisan" && "${2:-}" == "operations:record-db-backup" ]]; then
                      [[ "${FAKE_RECEIPT_FAIL:-0}" != "1" ]] || exit 75
                      printf '%s\\n' "$FAKE_RECEIPT"
                      exit 0
                    fi
                    exit 76
                    """
                ),
                encoding="utf-8",
            )
            fake_php.chmod(0o755)

            fake_dump = fake_bin / "mariadb-dump"
            fake_dump.write_text(
                "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\\n' '-- deterministic fixture' 'CREATE TABLE fixture (id INT);'\n",
                encoding="utf-8",
            )
            fake_dump.chmod(0o755)

            env = {
                **os.environ,
                "PATH": f"{fake_bin}{os.pathsep}{os.environ.get('PATH', '')}",
                "FAKE_FINGERPRINT": fingerprint,
                "FAKE_RECEIPT": receipt,
            }

            def run_remote(*, fail_receipt: bool = False):
                attempt_env = {**env, "FAKE_RECEIPT_FAIL": "1" if fail_receipt else "0"}
                return subprocess.run(
                    [
                        "/bin/bash",
                        "-s",
                        "--",
                        str(root),
                        "1",
                        "false",
                        "false",
                        "false",
                        "",
                        "",
                        str(fake_php),
                    ],
                    input=remote,
                    env=attempt_env,
                    text=True,
                    capture_output=True,
                    check=False,
                )

            first = run_remote()
            second = run_remote()
            self.assertEqual(first.returncode, 0, first.stderr)
            self.assertEqual(second.returncode, 0, second.stderr)

            def archive_from(result):
                values = dict(line.split("=", 1) for line in result.stdout.splitlines())
                self.assertEqual(
                    set(values),
                    {"MIGRATION_FINGERPRINT", "BACKUP_ARCHIVE"},
                )
                self.assertEqual(values["MIGRATION_FINGERPRINT"], fingerprint)
                self.assertNotIn(receipt, result.stdout)
                return values["BACKUP_ARCHIVE"]

            first_archive = archive_from(first)
            second_archive = archive_from(second)
            self.assertNotEqual(first_archive, second_archive)

            backup_dir = release / "storage/app/private/operations/database-backups"
            before_failure = sorted(path.name for path in backup_dir.glob("*.sql.gz"))
            self.assertEqual(len(before_failure), 2)
            for name in before_failure:
                archive = backup_dir / name
                self.assertEqual(archive.stat().st_mode & 0o777, 0o600)
                gzip_test = subprocess.run(["gzip", "-t", str(archive)], check=False)
                self.assertEqual(gzip_test.returncode, 0)

            failed = run_remote(fail_receipt=True)
            self.assertNotEqual(failed.returncode, 0)
            after_failure = sorted(path.name for path in backup_dir.glob("*.sql.gz"))
            self.assertEqual(after_failure, before_failure)
            self.assertEqual(list(backup_dir.glob(".tmp-*.sql.gz")), [])

    def test_database_credentials_never_leave_remote_host(self):
        script = SCRIPT.read_text(encoding="utf-8")

        remote_start = script.index("<<'REMOTE'")
        remote = script[remote_start:]
        self.assertIn('config("database.connections.".$default)', remote)
        self.assertIn('--defaults-extra-file="$credentials"', remote)
        self.assertIn("$escaped = strtr($value", remote)
        self.assertIn('chr(8) => "\\\\b"', remote)
        self.assertNotIn("json_encode(", remote)
        self.assertNotIn("str_replace(", remote)
        self.assertIn('chmod 600 "$credentials" "$database_file"', remote)
        self.assertNotIn("DB_PASSWORD", script)
        self.assertNotIn("set -x", script)
        self.assertNotIn('return """', script)
        self.assertNotIn("cat .env", script)

    def test_recovery_mode_uses_cross_runtime_maintenance_gate(self):
        script = SCRIPT.read_text(encoding="utf-8")
        workflow = WORKFLOW.read_text(encoding="utf-8")
        s4 = S4.read_text(encoding="utf-8")
        console = CONSOLE.read_text(encoding="utf-8")

        self.assertIn("include_recovery_bundle:", workflow)
        self.assertIn("confirm_recovery_write_freeze:", workflow)
        self.assertIn("confirm_recovery_symfony_cron_disabled:", workflow)
        self.assertIn("PRODUCTION_RECOVERY_KEY_B64", workflow)
        self.assertIn('artisan down --render=errors::503 --retry=60 --no-interaction', script)
        self.assertIn('artisan up --no-interaction', script)
        self.assertIn("storage/framework/maintenance.php", script)
        self.assertIn("wait_for_recovery_quiescence", script)
        self.assertIn(
            "write-capable process did not quiesce before recovery snapshot",
            script,
        )
        self.assertIn("recovery quiescence could not be verified", script)
        self.assertIn("pgrep is unavailable; recovery quiescence cannot be verified", script)
        self.assertIn("queue:(work|listen)", script)
        self.assertIn("schedule:(work|run)", script)
        self.assertIn("grindflow:password-recovery:deliver", script)
        self.assertIn("RECOVERY_SYMFONY_CRON_DISABLED_CONFIRMED", script)
        self.assertIn("storage/framework/maintenance.php", s4)
        self.assertNotIn("evenInMaintenanceMode", console)
        self.assertLess(
            script.index('artisan down --render=errors::503'),
            script.index('grindflow:vault:stage'),
        )
        self.assertLess(
            script.index('grindflow:vault:verify-stage'),
            script.index('artisan up --no-interaction', script.index('recovery_committed=1')),
        )

    def test_recovery_rejects_standard_worker_before_snapshot(self):
        script = SCRIPT.read_text(encoding="utf-8")
        remote = script.split("<<'REMOTE'\n", 1)[1].rsplit("\nREMOTE", 1)[0]

        start = remote.index("wait_for_recovery_quiescence() {")
        end = remote.index("\n}\n\nroot=", start) + len("\n}")
        guard = remote[start:end]

        call = "wait_for_recovery_quiescence 12 5 || exit 28"
        self.assertLess(
            remote.index(call),
            remote.index('\n"$dump_bin" \\\n'),
        )
        self.assertLess(
            remote.index(call),
            remote.index("grindflow:vault:stage"),
        )

        with tempfile.TemporaryDirectory() as tmp:
            fake_bin = Path(tmp) / "bin"
            fake_bin.mkdir()
            fake_pgrep = fake_bin / "pgrep"
            fake_pgrep.write_text(
                "#!/usr/bin/env bash\nprintf '%s\\n' '123 php artisan queue:work redis'\nexit 0\n",
                encoding="utf-8",
            )
            fake_pgrep.chmod(0o755)
            completed = subprocess.run(
                ["/bin/bash", "-c", guard + "\nwait_for_recovery_quiescence 1 0"],
                env={
                    **os.environ,
                    "PATH": f"{fake_bin}{os.pathsep}{os.environ.get('PATH', '')}",
                },
                text=True,
                capture_output=True,
                check=False,
            )

        self.assertNotEqual(0, completed.returncode)
        self.assertIn(
            "write-capable process did not quiesce before recovery snapshot",
            completed.stderr,
        )

        pgrep_error = (
            "set -euo pipefail\n"
            "pgrep() { return 2; }\n"
            "sleep() { :; }\n"
            + guard
            + "\nif wait_for_recovery_quiescence 1 0; then exit 9; fi\n"
        )
        completed = subprocess.run(
            ["bash", "-c", pgrep_error],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("recovery quiescence could not be verified", completed.stderr)

        symfony_cron_active = (
            "set -euo pipefail\n"
            "pgrep() { "
            "case \"$*\" in *grindflow:password-recovery:deliver*) return 0 ;; "
            "*) return 2 ;; esac; }\n"
            "sleep() { :; }\n"
            + guard
            + "\nif wait_for_recovery_quiescence 1 0; then exit 9; fi\n"
        )
        completed = subprocess.run(
            ["bash", "-c", symfony_cron_active],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn(
            "write-capable process did not quiesce before recovery snapshot",
            completed.stderr,
        )

    def test_recovery_quiescence_fails_closed_when_pgrep_errors(self):
        script = SCRIPT.read_text(encoding="utf-8")
        remote = script.split("<<'REMOTE'\n", 1)[1].rsplit("\nREMOTE", 1)[0]

        start = remote.index("wait_for_recovery_quiescence() {")
        end = remote.index("\n}\n\nroot=", start) + len("\n}")
        guard = remote[start:end]

        with tempfile.TemporaryDirectory() as tmp:
            fake_bin = Path(tmp) / "bin"
            fake_bin.mkdir()
            fake_pgrep = fake_bin / "pgrep"
            fake_pgrep.write_text(
                "#!/usr/bin/env bash\nexit 2\n",
                encoding="utf-8",
            )
            fake_pgrep.chmod(0o755)
            completed = subprocess.run(
                ["/bin/bash", "-c", guard + "\nwait_for_recovery_quiescence 1 0"],
                env={
                    **os.environ,
                    "PATH": f"{fake_bin}{os.pathsep}{os.environ.get('PATH', '')}",
                },
                text=True,
                capture_output=True,
                check=False,
            )

        self.assertNotEqual(0, completed.returncode)
        self.assertIn(
            "recovery quiescence could not be verified",
            completed.stderr,
        )

    def test_recovery_quiescence_covers_symfony_password_recovery_cron(self):
        script = SCRIPT.read_text(encoding="utf-8")
        workflow = WORKFLOW.read_text(encoding="utf-8")

        self.assertIn("grindflow:password-recovery:deliver", script)
        self.assertIn("RECOVERY_SYMFONY_CRON_DISABLED_CONFIRMED", script)
        self.assertIn("confirm_recovery_symfony_cron_disabled:", workflow)
        self.assertIn(
            "Symfony password-recovery cron disablement was not explicitly confirmed",
            script,
        )

    def test_receipt_uses_canonical_verified_backup_command_without_leaving_host(self):
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("operations:record-db-backup", script)
        self.assertIn('[[ "$receipt" =~ ^[0-9a-f]{64}$ ]]', script)
        self.assertNotIn("BACKUP_RECEIPT=", script)
        self.assertIn("MIGRATION_FINGERPRINT=", script)
        self.assertIn("BACKUP_ARCHIVE=", script)

    def test_recovery_requires_explicit_write_freeze_confirmation(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("confirm_recovery_write_freeze:", workflow)
        self.assertIn("RECOVERY_WRITES_STOPPED_CONFIRMED", workflow)
        self.assertIn(
            "Encrypted recovery requires explicit write-freeze confirmation",
            workflow,
        )
        self.assertIn(
            "recovery write freeze was not explicitly confirmed",
            script,
        )
        self.assertIn('artisan down --render=errors::503 --retry=60 --no-interaction', script)
        self.assertIn("storage/framework/maintenance.php", script)
        self.assertIn('artisan up --no-interaction', script)
        self.assertIn('grindflow:vault:audit', script)
        self.assertIn('--expect="$manifest_sha"', script)

    def test_normal_mode_accepts_exact_zero_pending_and_rejects_malformed_counts(self):
        script = SCRIPT.read_text(encoding="utf-8")
        base_env = {
            **os.environ,
            "HOSTINGER_SSH_HOST": "example.test",
            "HOSTINGER_SSH_USER": "deploy",
            "HOSTINGER_SSH_PORT": "22",
            "HOSTINGER_RELEASE_ROOT": "/home/deploy/domain",
            "SSH_KEY_PATH": "/nonexistent/grindflow-test-key",
            "KNOWN_HOSTS_PATH": "/nonexistent/grindflow-test-known-hosts",
        }

        def run_local(pending: str):
            return subprocess.run(
                ["/bin/bash", str(SCRIPT)],
                env={**base_env, "EXPECTED_PENDING": pending},
                text=True,
                capture_output=True,
                check=False,
            )

        # Zero and positive counts pass the count guard and stop at the next
        # local precondition (missing key), before any SSH is attempted.
        for valid in ("0", "1", "12"):
            result = run_local(valid)
            self.assertEqual(result.returncode, 2, valid)
            self.assertIn("SSH private key is unavailable", result.stderr, valid)
            self.assertNotIn("invalid expected pending count", result.stderr, valid)

        for invalid in ("abc", "-1", "00", "01", "1.5", " 1", "1 "):
            result = run_local(invalid)
            self.assertEqual(result.returncode, 2, invalid)
            self.assertIn("invalid expected pending count", result.stderr, invalid)

        empty = run_local("")
        self.assertNotEqual(empty.returncode, 0)
        self.assertIn("EXPECTED_PENDING is required", empty.stderr)

        # Exact comparison and fingerprint validation stay in the remote step.
        self.assertIn('[[ "$pending_count" == "$expected_pending" ]]', script)
        self.assertIn('[[ "$fingerprint" =~ ^[0-9a-f]{64}$ ]]', script)

    def test_remote_zero_pending_runs_only_on_exact_snapshot_match(self):
        script = SCRIPT.read_text(encoding="utf-8")
        remote = script.split("<<'REMOTE'\n", 1)[1].rsplit("\nREMOTE", 1)[0]
        fingerprint = "c" * 64

        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp).resolve()
            root = tmp_path / "release-root"
            release = root / "releases" / "r1"
            (release / "bootstrap").mkdir(parents=True)
            (release / "vendor").mkdir()
            for relative in ("artisan", "bootstrap/app.php", "vendor/autoload.php"):
                (release / relative).write_text("fixture\n", encoding="utf-8")
            (root / "current").symlink_to(release, target_is_directory=True)

            fake_bin = tmp_path / "bin"
            fake_bin.mkdir()
            fake_php = fake_bin / "php"
            fake_php.write_text(
                textwrap.dedent(
                    """\
                    #!/usr/bin/env bash
                    set -euo pipefail
                    if [[ "${1:-}" == "-r" ]]; then
                      code="${2:-}"
                      shift 2
                      if [[ "$code" == *"MigrationReadiness::class"* ]]; then
                        printf '%s|%s' "$FAKE_PENDING" "$FAKE_FINGERPRINT"
                        exit 0
                      fi
                      if [[ "$code" == *"database.connections."* ]]; then
                        printf '[client]\\nhost="localhost"\\n' > "$1"
                        printf 'grindflow_test' > "$2"
                        chmod 600 "$1" "$2"
                        exit 0
                      fi
                    fi
                    if [[ "${1:-}" == "artisan" && "${2:-}" == "operations:record-db-backup" ]]; then
                      printf '%s\\n' "$(printf 'd%.0s' {1..64})"
                      exit 0
                    fi
                    exit 76
                    """
                ),
                encoding="utf-8",
            )
            fake_php.chmod(0o755)
            fake_dump = fake_bin / "mariadb-dump"
            fake_dump.write_text(
                "#!/usr/bin/env bash\nprintf '%s\\n' '-- fixture'\n", encoding="utf-8"
            )
            fake_dump.chmod(0o755)

            def run_remote(expected: str, actual: str, fp: str = fingerprint):
                return subprocess.run(
                    ["/bin/bash", "-s", "--", str(root), expected, "false", "false", "false", "", "", str(fake_php)],
                    input=remote,
                    env={
                        **os.environ,
                        "PATH": f"{fake_bin}{os.pathsep}{os.environ.get('PATH', '')}",
                        "FAKE_PENDING": actual,
                        "FAKE_FINGERPRINT": fp,
                    },
                    text=True,
                    capture_output=True,
                    check=False,
                )

            ok = run_remote("0", "0")
            self.assertEqual(ok.returncode, 0, ok.stderr)
            self.assertIn(f"MIGRATION_FINGERPRINT={fingerprint}", ok.stdout)

            mismatch = run_remote("0", "2")
            self.assertEqual(mismatch.returncode, 26)
            self.assertIn("pending migration count changed", mismatch.stderr)
            mismatch = run_remote("1", "0")
            self.assertEqual(mismatch.returncode, 26)

            lost = run_remote("0", "0", fp="")
            self.assertEqual(lost.returncode, 25)
            self.assertIn("migration fingerprint is invalid", lost.stderr)
            lost = run_remote("0", "0", fp="XYZ")
            self.assertEqual(lost.returncode, 25)

    def test_scheduled_workflow_accepts_zero_but_stays_off(self):
        workflow = (ROOT / ".github/workflows/production-backup-scheduled.yml").read_text(encoding="utf-8")

        self.assertIn('[[ "$EXPECTED_PENDING" =~ ^(0|[1-9][0-9]*)$ ]]', workflow)
        self.assertNotIn("canonical script rejects zero", workflow)
        self.assertIn("vars.GRINDFLOW_DAILY_BACKUP_ENABLED == 'true'", workflow)
        self.assertNotRegex(workflow, r"(?m)^\s*schedule:")
        self.assertNotRegex(workflow, r"(?m)^\s+- cron:")
        script = SCRIPT.read_text(encoding="utf-8")
        self.assertIn('RECOVERY_BACKUP_ENABLED="${RECOVERY_BACKUP_ENABLED:-false}"', script)
        self.assertIn("StrictHostKeyChecking=yes", script)

    def test_workflow_is_manual_owner_only_and_never_runs_migrations(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn(
            "github.actor == github.repository_owner && github.triggering_actor == github.repository_owner",
            workflow,
        )
        self.assertNotIn("production-backup-receipt-", workflow)
        self.assertNotIn("actions/upload-artifact", workflow)
        self.assertNotIn("backup_receipt", workflow.lower())
        self.assertIn("run-production-backup.sh", workflow)
        job_header = workflow.split("    steps:", 1)[0]
        self.assertNotIn("secrets.DEPLOY_SSH_KEY", job_header)
        self.assertNotIn("secrets.HOSTINGER_KNOWN_HOSTS", job_header)
        self.assertNotIn("runner.temp", job_header)
        self.assertIn("Install strict SSH material", workflow)
        self.assertIn('SSH_KEY_PATH="$RUNNER_TEMP/grindflow-deploy-key"', workflow)
        self.assertIn('KNOWN_HOSTS_PATH="$RUNNER_TEMP/grindflow-known-hosts"', workflow)
        self.assertIn('"$GITHUB_ENV"', workflow)
        self.assertNotIn("DEPLOY_SSH_KEY", script)
        self.assertNotIn("HOSTINGER_KNOWN_HOSTS", script)
        self.assertNotIn("production-migration.yml", workflow)
        self.assertNotIn("artisan migrate", script)
        self.assertNotIn("/admin/system/migrations", script)
        self.assertNotIn("curl ", script)

        ci = CI.read_text(encoding="utf-8")
        invocation = "[[ -f tests/test_production_backup_workflow.py ]] && python3 -m unittest tests/test_production_backup_workflow.py"
        self.assertEqual(ci.count(invocation), 1)


if __name__ == "__main__":
    unittest.main()
