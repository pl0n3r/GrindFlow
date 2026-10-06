from __future__ import annotations

import json
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = (
    ROOT / "app/Http/Controllers/Operations/ProductionSmokeBootstrapController.php"
).read_text(encoding="utf-8")
PROVISIONER = (
    ROOT / "app/Support/Deployment/S4SmokeIdentityProvisioner.php"
).read_text(encoding="utf-8")
COMMAND = (
    ROOT / "symfony/src/Identity/Application/ProvisionSmokeIdentityCommand.php"
).read_text(encoding="utf-8")
COMMAND_TEST = (
    ROOT / "symfony/tests/php/ProvisionSmokeIdentityCommandTest.php"
).read_text(encoding="utf-8")
BOOTSTRAP_TEST = (
    ROOT / "tests/Feature/ProductionSmokeBootstrapTest.php"
).read_text(encoding="utf-8")
PROVISIONER_TEST = (
    ROOT / "tests/Unit/S4SmokeIdentityProvisionerTest.php"
).read_text(encoding="utf-8")
SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
GRINDFLOW_CONFIG = (ROOT / "config/grindflow.php").read_text(encoding="utf-8")
ENV_EXAMPLE = (ROOT / ".env.example").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")


class S4EphemeralIdentityRotationTests(unittest.TestCase):
    def test_bootstrap_reconciles_both_stacks_fail_closed(self) -> None:
        laravel = "Artisan::call('grindflow:provision-smoke-user')"
        s4 = "$s4Provisioner->reconcile($password)"
        self.assertIn(laravel, CONTROLLER)
        self.assertIn(s4, CONTROLLER)
        self.assertLess(CONTROLLER.index(laravel), CONTROLLER.index(s4))
        self.assertIn("$failureStage = 'provision-s4';", CONTROLLER)
        self.assertIn("S4SmokeIdentityProvisioner::FAILURE_CODES", CONTROLLER)
        self.assertIn("'X-GrindFlow-Smoke-Failure-Stage'", CONTROLLER)
        self.assertIn("'X-GrindFlow-Smoke-Failure-Code'", CONTROLLER)

    def test_s4_provisioner_never_places_secret_in_argv_or_public_output(self) -> None:
        start = PROVISIONER.index("new Process(")
        end = PROVISIONER.index(");", start)
        process_ctor = PROVISIONER[start:end]
        command_part = process_ctor.split("$root,", 1)[0]
        self.assertNotIn("$password", command_part)
        self.assertIn(
            "['GRINDFLOW_S4_SMOKE_PASSWORD' => $password]",
            process_ctor,
        )
        self.assertNotIn("PHP_BINARY", PROVISIONER)
        self.assertIn("'grindflow.s4_smoke.php_cli_binary'", PROVISIONER)
        self.assertIn("'/opt/alt/php85/usr/bin/php'", GRINDFLOW_CONFIG)
        self.assertIn(
            'GRINDFLOW_S4_PHP_CLI_BINARY="/opt/alt/php85/usr/bin/php"',
            ENV_EXAMPLE,
        )
        self.assertIn("! is_executable($php)", PROVISIONER)
        self.assertIn(
            "test_configured_php_cli_binary_is_used_without_constructor_override",
            PROVISIONER_TEST,
        )
        self.assertIn("'--env=prod'", PROVISIONER)
        self.assertIn("'s4-output-invalid'", PROVISIONER)
        self.assertNotIn("Log::", PROVISIONER)
        self.assertIn(
            "test_secret_travels_only_in_child_environment_and_public_result_is_allowlisted",
            PROVISIONER_TEST,
        )

    def test_reserved_identity_rotates_only_password_hash(self) -> None:
        marker = "$db->update("
        start = COMMAND.index(marker)
        end = COMMAND.index("return 'rotated';", start)
        rotation = COMMAND[start:end]
        self.assertIn("'gf_identity_users'", rotation)
        self.assertIn("['password_hash' => $hasher->hash($secret)]", rotation)
        self.assertIn("$affected !== 1", rotation)
        self.assertNotIn("'name' =>", rotation)
        self.assertNotIn("'platform_role' =>", rotation)
        self.assertNotIn("'is_active' =>", rotation)
        self.assertNotIn("'updated_at' =>", rotation)
        self.assertIn("existingStateHasValidStructure", COMMAND)
        self.assertIn("'identity_conflict'", COMMAND)
        self.assertIn("'rotated'", COMMAND_TEST)
        self.assertIn("password_verify($rotatedSecret", COMMAND_TEST)

    def test_php_regression_contract_is_executable(self) -> None:
        self.assertIn(
            "test_verified_oidc_bootstrap_reconciles_the_synthetic_account_without_exposing_secret",
            BOOTSTRAP_TEST,
        )
        self.assertIn(
            "X-GrindFlow-Smoke-Failure-Stage', 'provision-s4",
            BOOTSTRAP_TEST,
        )
        self.assertIn("S4SmokeIdentityProvisionerTest", PROVISIONER_TEST)
        self.assertIn("ProvisionSmokeIdentityCommandTest", COMMAND_TEST)
        self.assertIn(
            "tests/test_s4_ephemeral_identity_rotation.py",
            PACKAGE["scripts"]["test"],
        )

    def test_release_identity_is_synchronized_and_suite_is_canonical(self) -> None:
        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertGreaterEqual(
            tuple(int(part) for part in release.split(".")),
            (0, 1, 210),
        )
        self.assertEqual(release, PACKAGE["version"])
        self.assertEqual(release, LOCK["version"])
        self.assertEqual(release, LOCK["packages"][""]["version"])
        self.assertIn(f"V{release}", README)

    def test_operational_closeout_requires_exact_main_past_login_redirect(self) -> None:
        self.assertIn("/internal/production-smoke/bootstrap", WORKFLOW)
        self.assertIn("S4_POST_BRIDGE_CONTRACT_STAGE=login_redirect", SMOKE)
        self.assertIn("S4_LOGIN_REDIRECT_CLASS=", SMOKE)
        self.assertIn("grindflow:s4:provision-smoke-identity", PROVISIONER)
        self.assertIn("rotated", PROVISIONER)
        self.assertIn("name: GrindFlow Production Smoke", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
