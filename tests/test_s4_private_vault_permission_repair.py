from __future__ import annotations

import json
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]
VAULT = (
    ROOT / "symfony/src/Infrastructure/Storage/PrivateVaultDirectory.php"
).read_text(encoding="utf-8")
COMMAND = (
    ROOT / "symfony/src/Infrastructure/Storage/RepairPrivateVaultPermissionsCommand.php"
).read_text(encoding="utf-8")
VAULT_TEST = (
    ROOT / "symfony/tests/php/PrivateVaultDirectoryTest.php"
).read_text(encoding="utf-8")
COMMAND_TEST = (
    ROOT / "symfony/tests/php/RepairPrivateVaultPermissionsCommandTest.php"
).read_text(encoding="utf-8")
REPAIRER = (
    ROOT / "app/Support/Deployment/S4PrivateVaultPermissionRepairer.php"
).read_text(encoding="utf-8")
CONTROLLER = (
    ROOT / "app/Http/Controllers/Operations/ProductionSmokeBootstrapController.php"
).read_text(encoding="utf-8")
BOOTSTRAP_TEST = (
    ROOT / "tests/Feature/ProductionSmokeBootstrapTest.php"
).read_text(encoding="utf-8")
REPAIRER_TEST = (
    ROOT / "tests/Unit/S4PrivateVaultPermissionRepairerTest.php"
).read_text(encoding="utf-8")
WORKFLOW = (
    ROOT / ".github/workflows/production-smoke.yml"
).read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")


class S4PrivateVaultPermissionRepairTests(unittest.TestCase):
    def test_repair_is_bounded_to_private_permission_tightening(self) -> None:
        start = VAULT.index("public function tightenPrivatePermissions()")
        end = VAULT.index("/** Create only the final private directory", start)
        block = VAULT[start:end]

        self.assertIn("$this->rootOverride === ''", block)
        self.assertIn("!is_dir($root)", block)
        self.assertIn("is_link($root)", block)
        self.assertIn("@fileperms($root)", block)
        self.assertIn("($mode & 0077) === 0", block)
        self.assertIn("@chmod($root, 0700)", block)
        self.assertIn("return 'tightened';", block)
        self.assertNotIn("mkdir(", block)
        self.assertNotIn("rename(", block)
        self.assertNotIn("unlink(", block)
        self.assertNotIn("chown(", block)
        self.assertNotIn("chgrp(", block)

    def test_symfony_command_contract_is_idempotent_and_fail_closed(self) -> None:
        self.assertIn(
            "grindflow:s4:repair-private-vault-permissions",
            COMMAND,
        )
        self.assertIn("testTightenPrivatePermissionsOnlyNarrowsExistingExternalRoot", VAULT_TEST)
        self.assertIn("testCommandTightensThenBecomesIdempotentWithoutExposingPathOrMode", COMMAND_TEST)
        self.assertIn("testCommandFailsClosedForMissingOrSymlinkedRoot", COMMAND_TEST)
        self.assertIn("'tightened'", COMMAND)
        self.assertIn("'already_private'", COMMAND)
        self.assertNotIn("getMessage()", COMMAND)
        self.assertNotIn("$root", COMMAND)

    def test_oidc_bootstrap_runs_secret_free_vault_repair_after_s4_identity(self) -> None:
        provision = CONTROLLER.index("$s4Provisioner->reconcile($password)")
        stage = CONTROLLER.index("$failureStage = 'repair-s4-vault-permissions';")
        repair = CONTROLLER.index("$vaultRepairer->repair()")
        self.assertLess(provision, stage)
        self.assertLess(stage, repair)
        self.assertIn("S4PrivateVaultPermissionRepairer::FAILURE_CODES", CONTROLLER)
        self.assertIn(
            "test_verified_bootstrap_repairs_private_vault_after_s4_identity",
            BOOTSTRAP_TEST,
        )
        self.assertIn(
            "test_verified_bootstrap_reports_only_allowlisted_vault_repair_failure",
            BOOTSTRAP_TEST,
        )
        self.assertNotIn("GRINDFLOW_VAULT_ROOT", CONTROLLER)

    def test_child_process_transport_is_shell_free_and_output_allowlisted(self) -> None:
        start = REPAIRER.index("new Process(")
        end = REPAIRER.index(");", start)
        constructor = REPAIRER[start:end]

        self.assertIn("PHP_BINARY", REPAIRER)
        self.assertIn("'grindflow:s4:repair-private-vault-permissions'", constructor)
        self.assertIn("'--env=prod'", constructor)
        self.assertNotIn("GRINDFLOW_VAULT_ROOT", constructor)
        self.assertNotIn("shell", constructor.lower())
        self.assertIn("SUCCESS_CODES", REPAIRER)
        self.assertIn("FAILURE_CODES", REPAIRER)
        self.assertIn("'output_invalid'", REPAIRER)
        self.assertNotIn("getErrorOutput()", REPAIRER)
        self.assertIn(
            "test_child_process_is_shell_free_and_returns_only_allowlisted_code",
            REPAIRER_TEST,
        )

    def test_release_v0215_is_synchronized_and_validate_is_canonical(self) -> None:
        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        self.assertEqual("0.1.215", match.group(1))
        self.assertEqual("0.1.215", PACKAGE["version"])
        self.assertEqual("0.1.215", LOCK["version"])
        self.assertEqual("0.1.215", LOCK["packages"][""]["version"])
        self.assertIn("V0.1.215", README)
        self.assertIn(
            "tests/test_s4_private_vault_permission_repair.py",
            PACKAGE["scripts"]["test"],
        )

    def test_operational_closeout_requires_exact_main_private_vault_ready(self) -> None:
        self.assertIn("name: GrindFlow Production Smoke", WORKFLOW)
        self.assertIn("S4_PRIVATE_VAULT_STATE", WORKFLOW)
        self.assertIn("private_vault", WORKFLOW)
        self.assertIn("Wait for exact deployed checkout before production write", WORKFLOW)
        self.assertIn("X-GrindFlow-Expected-Sha", WORKFLOW)
        self.assertIn("repair-s4-vault-permissions", CONTROLLER)


if __name__ == "__main__":
    unittest.main()
