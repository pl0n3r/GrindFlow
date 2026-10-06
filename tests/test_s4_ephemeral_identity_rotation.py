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
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")


class S4EphemeralIdentityRotationTests(unittest.TestCase):
    def test_bootstrap_reconciles_both_stacks_fail_closed(self) -> None:
        self.assertIn(
            "use App\\Support\\Deployment\\S4SmokeIdentityProvisioner;",
            CONTROLLER,
        )
        self.assertIn(
            "S4SmokeIdentityProvisioner $s4Provisioner",
            CONTROLLER,
        )
        laravel = CONTROLLER.index(
            "Artisan::call('grindflow:provision-smoke-user')"
        )
        s4 = CONTROLLER.index("$s4Provisioner->reconcile($password)")
        self.assertLess(laravel, s4)
        self.assertIn("$failureStage = 'provision-s4';", CONTROLLER)
        self.assertIn(
            "'S4 synthetic smoke identity reconciliation failed.' => "
            "'provision-s4-failed'",
            CONTROLLER,
        )
        self.assertIn(
            "S4SmokeIdentityProvisioner::FAILURE_CODES",
            CONTROLLER,
        )
        self.assertIn(
            "X-GrindFlow-Smoke-Failure-Stage",
            CONTROLLER,
        )
        self.assertIn(
            "X-GrindFlow-Smoke-Failure-Code",
            CONTROLLER,
        )

    def test_s4_provisioner_never_places_secret_in_argv_or_public_output(self) -> None:
        process_start = PROVISIONER.index("new Process(")
        process_end = PROVISIONER.index(");", process_start)
        process_block = PROVISIONER[process_start:process_end]
        command_end = process_block.index("],\n            $root")
        command_argv = process_block[:command_end]

        self.assertIn("$php,", command_argv)
        self.assertIn("$console,", command_argv)
        self.assertIn(
            "'grindflow:s4:provision-smoke-identity'",
            command_argv,
        )
        self.assertNotIn("$password", command_argv)
        self.assertIn(
            "['GRINDFLOW_S4_SMOKE_PASSWORD' => $password]",
            process_block,
        )
        self.assertIn("$this->phpBinary ?? PHP_BINARY", PROVISIONER)
        self.assertNotIn("shell_exec(", PROVISIONER)
        self.assertNotIn("passthru(", PROVISIONER)
        self.assertNotIn("system(", PROVISIONER)
        self.assertNotIn("proc_open(", PROVISIONER)
        self.assertIn("str_contains($stdout, $password)", PROVISIONER)
        self.assertIn("str_contains($stderr, $password)", PROVISIONER)
        self.assertNotIn("Log::", PROVISIONER)

    def test_reserved_identity_rotates_only_password_hash(self) -> None:
        existing = COMMAND[
            COMMAND.index("if ($user !== false && $organization !== false)"):
            COMMAND.index("$hasher = $this->hashers", COMMAND.index("if ($user !== false"))
        ]
        self.assertIn("existingStateHasValidStructure", existing)

        rotation = COMMAND[
            COMMAND.index("$hasher = $this->hashers", COMMAND.index("if ($user !== false")):
            COMMAND.index("$hasher = $this->hashers", COMMAND.index("if ($user !== false")) + 1400
        ]
        self.assertIn(
            "['password_hash' => $hasher->hash($secret)]",
            rotation,
        )
        self.assertIn("'id' => (string) $user['id']", rotation)
        self.assertIn("'email' => self::EMAIL", rotation)
        self.assertIn("return 'rotated';", rotation)
        self.assertNotIn("'platform_role' =>", rotation)
        self.assertNotIn("'is_active' =>", rotation)

        self.assertIn(
            "(string) ($user['name'] ?? '') !== self::DISPLAY_NAME",
            COMMAND,
        )
        self.assertIn(
            "(string) ($organization['name'] ?? '') !== self::ORGANIZATION_NAME",
            COMMAND,
        )
        self.assertIn("count($memberships) !== 1", COMMAND)
        self.assertIn("return 'identity_conflict';", COMMAND)

    def test_php_regression_contract_is_executable(self) -> None:
        self.assertIn(
            "self::assertSame('rotated', $this->payload($rotated)['code']);",
            COMMAND_TEST,
        )
        self.assertIn(
            "self::assertSame($afterRotation, $this->snapshot());",
            COMMAND_TEST,
        )
        self.assertIn(
            "test_verified_bootstrap_reports_allowlisted_s4_failure_without_reflecting_child_output",
            BOOTSTRAP_TEST,
        )
        self.assertIn(
            "->assertHeader('X-GrindFlow-Smoke-Failure-Stage', 'provision-s4')",
            BOOTSTRAP_TEST,
        )
        self.assertIn(
            "test_secret_is_available_only_through_child_environment_and_not_argv",
            PROVISIONER_TEST,
        )
        self.assertIn(
            "test_command_failures_are_reduced_to_allowlisted_codes",
            PROVISIONER_TEST,
        )
        self.assertIn(
            "tests/test_s4_ephemeral_identity_rotation.py",
            PACKAGE["scripts"]["test"],
        )

    def test_release_v0210_is_synchronized_and_validate_is_canonical(self) -> None:
        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        self.assertEqual("0.1.210", match.group(1))
        self.assertEqual("0.1.210", PACKAGE["version"])
        self.assertEqual("0.1.210", LOCK["version"])
        self.assertEqual("0.1.210", LOCK["packages"][""]["version"])
        self.assertIn("V0.1.210", README)
        self.assertIn("npm run test", PACKAGE["scripts"]["validate"])

    def test_operational_closeout_requires_exact_main_past_login_redirect(self) -> None:
        exact_guard = WORKFLOW.index(
            "Wait for exact deployed checkout before production write"
        )
        bootstrap = WORKFLOW.index(
            "Reconcile synthetic smoke identity through GitHub OIDC"
        )
        smoke = WORKFLOW.index(
            "Authenticated production smoke · Symfony /s4 readiness"
        )
        self.assertLess(exact_guard, bootstrap)
        self.assertLess(bootstrap, smoke)
        self.assertIn(
            'GRINDFLOW_SMOKE_PASSWORD="$E2E_USER_PASSWORD"',
            WORKFLOW,
        )
        self.assertIn(
            '"X-GrindFlow-Expected-Sha: $GITHUB_SHA"',
            WORKFLOW,
        )
        self.assertIn(
            "$s4Provisioner->reconcile($password)",
            CONTROLLER,
        )
        self.assertIn(
            "S4_LOGIN_REDIRECT_CLASS=returned_to_login",
            (ROOT / "tests/test_s4_login_redirect_diagnostics.py").read_text(
                encoding="utf-8"
            ),
        )


if __name__ == "__main__":
    unittest.main()
