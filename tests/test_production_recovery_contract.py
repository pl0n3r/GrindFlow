from __future__ import annotations

import base64
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SECRETSTREAM = ROOT / "scripts/recovery-secretstream.php"
WORKFLOW = ROOT / ".github/workflows/production-backup.yml"
CI = ROOT / ".github/workflows/grindflow-ci.yml"
BACKUP = ROOT / "scripts/run-production-backup.sh"
RESTORE = ROOT / "scripts/symfony-disposable-restore-drill.sh"
EVIDENCE = ROOT / "app/Support/Operations/VerifiedBackupEvidence.php"
RUNBOOK = ROOT / "docs/PRODUCTION-RECOVERY.md"
S4 = ROOT / "public/s4.php"


class ProductionRecoveryContractTests(unittest.TestCase):
    @staticmethod
    def _key(byte: int = 7) -> str:
        return base64.b64encode(bytes([byte]) * 32).decode("ascii")

    @staticmethod
    def _php(*args: str, key: str | None = None) -> subprocess.CompletedProcess[str]:
        env = os.environ.copy()
        if key is not None:
            env["GF_RECOVERY_KEY_B64"] = key
        else:
            env.pop("GF_RECOVERY_KEY_B64", None)
        return subprocess.run(
            ["php", str(SECRETSTREAM), *args],
            cwd=ROOT,
            env=env,
            text=True,
            capture_output=True,
            check=False,
        )

    def test_backup_binds_database_and_vault_evidence(self) -> None:
        script = BACKUP.read_text(encoding="utf-8")
        self.assertIn("operations/database-backups", script)
        self.assertGreaterEqual(script.count("MigrationReadiness::class"), 2)
        self.assertIn("recovery migration state changed", script)
        self.assertIn("operations:record-db-backup", script)
        self.assertIn("grindflow:vault:", RESTORE.read_text(encoding="utf-8"))
        self.assertIn("migration_fingerprint", EVIDENCE.read_text(encoding="utf-8"))

    def test_encryption_is_fail_closed_and_plaintext_is_not_retained(self) -> None:
        probe = subprocess.run(
            [
                "php",
                "-r",
                "exit(extension_loaded('sodium')"
                " && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')"
                " && function_exists('sodium_crypto_secretstream_xchacha20poly1305_push')"
                " && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')"
                " && function_exists('sodium_crypto_secretstream_xchacha20poly1305_pull') ? 0 : 3);",
            ],
            cwd=ROOT,
            check=False,
        )
        self.assertEqual(0, probe.returncode, "CI PHP must provide libsodium secretstream")

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            plain = root / "plain.tar"
            encrypted = root / "bundle.gfrec"
            restored = root / "restored.tar"
            plain.write_bytes((b"GrindFlow recovery fixture\n" * 70000) + b"final")
            os.chmod(plain, 0o600)

            missing_key = self._php("encrypt", str(plain), str(encrypted))
            self.assertNotEqual(0, missing_key.returncode)
            self.assertFalse(encrypted.exists())

            encrypted_ok = self._php(
                "encrypt", str(plain), str(encrypted), key=self._key()
            )
            self.assertEqual(0, encrypted_ok.returncode, encrypted_ok.stderr)
            self.assertEqual(0o600, encrypted.stat().st_mode & 0o777)
            self.assertNotIn(b"GrindFlow recovery fixture", encrypted.read_bytes())

            decrypted = self._php(
                "decrypt", str(encrypted), str(restored), key=self._key()
            )
            self.assertEqual(0, decrypted.returncode, decrypted.stderr)
            self.assertEqual(plain.read_bytes(), restored.read_bytes())

            tampered = root / "tampered.gfrec"
            damaged = bytearray(encrypted.read_bytes())
            damaged[-8] ^= 0x01
            tampered.write_bytes(damaged)
            os.chmod(tampered, 0o600)
            rejected = self._php(
                "decrypt", str(tampered), str(root / "tampered.tar"), key=self._key()
            )
            self.assertNotEqual(0, rejected.returncode)
            self.assertFalse((root / "tampered.tar").exists())

            truncated = root / "truncated.gfrec"
            truncated.write_bytes(encrypted.read_bytes()[:-12])
            os.chmod(truncated, 0o600)
            rejected = self._php(
                "decrypt", str(truncated), str(root / "truncated.tar"), key=self._key()
            )
            self.assertNotEqual(0, rejected.returncode)
            self.assertFalse((root / "truncated.tar").exists())

            wrong_key = self._php(
                "decrypt", str(encrypted), str(root / "wrong.tar"), key=self._key(8)
            )
            self.assertNotEqual(0, wrong_key.returncode)
            self.assertFalse((root / "wrong.tar").exists())

    def test_receipt_binds_ciphertext_db_fingerprint_vault_manifest_and_release(self) -> None:
        evidence = (
            (ROOT / "app/Support/Operations/VerifiedRecoveryEvidence.php")
            .read_text(encoding="utf-8")
            if (ROOT / "app/Support/Operations/VerifiedRecoveryEvidence.php").exists()
            else ""
        )
        for signal in (
            "ciphertext_sha256",
            "migration_fingerprint",
            "vault_index_sha256",
            "release_version",
            "release_sha",
        ):
            self.assertIn(signal, evidence)

    def test_disposable_restore_uses_encrypted_production_format(self) -> None:
        restore = RESTORE.read_text(encoding="utf-8")
        self.assertIn("recovery-secretstream.php", restore)
        self.assertIn("decrypt", restore)
        self.assertIn("verify-restore", restore)
        self.assertIn("data-schema-structure-parity.py", restore)
        ci = CI.read_text(encoding="utf-8")
        self.assertIn("symfony-post-restore-tenant-guard.sh", ci)

    def test_backup_workflow_never_migrates_or_publishes_real_backup_artifacts(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        backup = BACKUP.read_text(encoding="utf-8")
        self.assertNotIn("actions/upload-artifact", workflow)
        self.assertNotIn("artisan migrate", backup)
        self.assertNotIn("/admin/system/migrations", backup)
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn(
            "github.actor == github.repository_owner && github.triggering_actor == github.repository_owner",
            workflow,
        )

    def test_recovery_bundle_requires_cross_runtime_quiescence(self) -> None:
        backup = BACKUP.read_text(encoding="utf-8")
        s4 = S4.read_text(encoding="utf-8")

        down = '"$php_bin" artisan down --render=errors::503 --retry=60 --no-interaction'
        dump = '"$dump_bin" \\\n'
        stage = '"$php_bin" symfony/bin/console grindflow:vault:stage'

        self.assertIn('RECOVERY_BACKUP_ENABLED="${RECOVERY_BACKUP_ENABLED:-false}"', backup)
        self.assertIn('if [[ "$recovery_enabled" == "true" ]]; then', backup)
        self.assertIn("storage/framework/down", backup)
        self.assertIn("storage/framework/maintenance.php", backup)
        self.assertIn(down, backup)
        self.assertIn('maintenance_enabled=1', backup)
        self.assertIn('if [[ "$maintenance_enabled" == "1" ]]; then', backup)
        self.assertIn('"$php_bin" artisan up --no-interaction', backup)
        self.assertIn("--confirm-writes-stopped", backup)
        self.assertLess(backup.index(down), backup.index(dump))
        self.assertLess(backup.index(down), backup.index(stage))

        self.assertIn(
            "$maintenance = dirname(__DIR__).'/storage/framework/maintenance.php'",
            s4,
        )
        self.assertIn("require $maintenance;", s4)

    def test_recovery_runbook_preserves_authority_and_safe_evidence(self) -> None:
        text = RUNBOOK.read_text(encoding="utf-8") if RUNBOOK.exists() else ""
        for signal in (
            "restore destructivo",
            "GF_RECOVERY_KEY_B64",
            "ciphertext",
            "Vault",
            "MariaDB",
            "entorno descartable",
        ):
            self.assertIn(signal, text)


if __name__ == "__main__":
    unittest.main()
