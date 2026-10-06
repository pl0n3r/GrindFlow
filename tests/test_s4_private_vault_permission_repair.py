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
DOCS = (ROOT / "docs/PILOT-MEDIA-READINESS.md").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")


class S4PrivateVaultPermissionRepairTests(unittest.TestCase):
    def test_repair_is_bounded_to_private_permission_tightening(self) -> None:
        start = VAULT.index("public function tightenPrivatePermissions()")
        end = VAULT.index("/** Create only the final private directory", start)
        block = VAULT[start:end]

        self.assertNotIn("$this->rootOverride === ''", block)
        self.assertIn("$root = $this->root()", block)
        self.assertIn("!is_dir($root)", block)
        self.assertIn("is_link($root)", block)
        self.assertIn("!is_readable($root)", block)
        self.assertIn("!is_writable($root)", block)
        self.assertIn("!is_executable($root)", block)
        self.assertIn("@fileperms($root)", block)
        self.assertIn("($mode & 0077) === 0", block)
        self.assertIn("@chmod($root, 0700)", block)
        self.assertIn("return 'tightened';", block)
        for forbidden in ("mkdir(", "rename(", "unlink(", "chown(", "chgrp("):
            self.assertNotIn(forbidden, block)

    def test_symfony_command_contract_is_idempotent_and_fail_closed(self) -> None:
        self.assertIn(
            "grindflow:s4:repair-private-vault-permissions",
            COMMAND,
        )
        self.assertIn(
            "testTightenPrivatePermissionsNarrowsExistingDefaultAndExternalRoots",
            VAULT_TEST,
        )
        self.assertIn(
            "testCommandTightensThenBecomesIdempotentWithoutExposingPathOrMode",
            COMMAND_TEST,
        )
        self.assertIn("testCommandFailsClosedForMissingOrSymlinkedRoot", COMMAND_TEST)
        self.assertIn("$previousPermissions = $mode & 0777", VAULT)
        self.assertIn("@chmod($root, $previousPermissions)", VAULT)
        self.assertIn("'tightened'", COMMAND)
        self.assertIn("'already_private'", COMMAND)
        self.assertNotIn("getMessage()", COMMAND)
        self.assertNotIn("$root", COMMAND)

    def test_oidc_bootstrap_runs_secret_free_vault_repair_after_s4_identity(self) -> None:
        verify = CONTROLLER.index("$claims = $verifier->verify($token, $expectedSha)")
        owner_gate = CONTROLLER.index("($claims['event_name'] ?? null) !== 'workflow_dispatch'")
        actor_gate = CONTROLLER.index("(string) ($claims['actor_id'] ?? '') !== '64439547'")
        policy = CONTROLLER.index("$writePolicy->assertAutonomousWriteAllowed(")
        provision = CONTROLLER.index("$s4Provisioner->reconcile($password)")
        condition = CONTROLLER.index("if ($repairRequested)", provision)
        repair = CONTROLLER.index("$vaultRepairer->repair()", condition)

        self.assertLess(verify, owner_gate)
        self.assertLess(owner_gate, actor_gate)
        self.assertLess(actor_gate, policy)
        self.assertLess(policy, repair)
        self.assertLess(provision, repair)
        self.assertIn("$request->json('repair_private_vault_permissions', false)", CONTROLLER)
        self.assertIn("! is_bool($repairRequested)", CONTROLLER)
        self.assertIn("ProductionWritePolicy", CONTROLLER)
        self.assertIn("S4PrivateVaultPermissionRepairer::PUBLIC_FAILURE_CODES", CONTROLLER)
        self.assertIn(
            "test_private_vault_permission_repair_requires_owner_workflow_dispatch",
            BOOTSTRAP_TEST,
        )
        self.assertIn(
            "test_private_vault_permission_repair_obeys_production_write_policy",
            BOOTSTRAP_TEST,
        )
        self.assertNotIn("GRINDFLOW_VAULT_ROOT", CONTROLLER)

    def test_child_process_transport_is_shell_free_and_output_allowlisted(self) -> None:
        start = REPAIRER.index("new Process(")
        end = REPAIRER.index(");", start)
        constructor = REPAIRER[start:end]

        self.assertIn("grindflow.s4_smoke.php_cli_binary", REPAIRER)
        self.assertIn("! is_file($php)", REPAIRER)
        self.assertIn("! is_executable($php)", REPAIRER)
        self.assertIn("'grindflow:s4:repair-private-vault-permissions'", constructor)
        self.assertIn("'--env=prod'", constructor)
        self.assertIn("'--no-interaction'", constructor)
        self.assertIn("'--no-ansi'", constructor)
        self.assertNotIn("GRINDFLOW_VAULT_ROOT", constructor)
        self.assertNotIn("shell", constructor.lower())
        self.assertIn("SUCCESS_CODES", REPAIRER)
        self.assertIn("PUBLIC_FAILURE_CODES", REPAIRER)
        self.assertIn("'output_invalid'", REPAIRER)
        self.assertNotIn("getErrorOutput()", REPAIRER)
        self.assertIn(
            "test_child_process_is_shell_free_and_returns_only_allowlisted_code",
            REPAIRER_TEST,
        )
        self.assertIn("self::assertCount(5, $argv)", REPAIRER_TEST)

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

    def test_operational_closeout_requires_explicit_manual_authority(self) -> None:
        self.assertIn("workflow_dispatch:", WORKFLOW)
        self.assertIn("repair_private_vault_permissions:", WORKFLOW)
        self.assertIn("type: boolean", WORKFLOW)
        self.assertIn("default: false", WORKFLOW)
        self.assertIn("GRINDFLOW_REPAIR_PRIVATE_VAULT_PERMISSIONS", WORKFLOW)
        self.assertIn('"repair_private_vault_permissions"', WORKFLOW)
        self.assertIn("event_name", CONTROLLER)
        self.assertIn("actor_id", CONTROLLER)
        self.assertIn("ProductionWritePolicy", CONTROLLER)
        self.assertIn("autorización específica", DOCS.lower())
        self.assertIn("exact-main", DOCS)
        self.assertNotIn("repair_private_vault_permissions: true", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
