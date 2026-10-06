from __future__ import annotations

from pathlib import Path
import json
import unittest


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))


class ProductionSmokeEphemeralCredentialsTests(unittest.TestCase):
    def test_workflow_has_no_persistent_password_secret_dependency(self) -> None:
        self.assertNotIn("secrets.PRODUCTION_E2E_PASSWORD", WORKFLOW)
        self.assertNotIn("Repository secret PRODUCTION_E2E_PASSWORD", WORKFLOW)
        self.assertNotIn("Fail unconfigured authenticated production smoke", WORKFLOW)
        self.assertNotIn("steps.credentials.outputs.configured", WORKFLOW)
        self.assertNotIn("CONFIG_ISSUE_TITLE", WORKFLOW)
        self.assertIn("Generate ephemeral synthetic credential", WORKFLOW)

    def test_ephemeral_password_is_generated_masked_and_kept_job_local(self) -> None:
        start = WORKFLOW.index("      - id: credentials")
        end = WORKFLOW.index("      - id: deployed_checkout")
        block = WORKFLOW[start:end]

        self.assertIn('password="$(openssl rand -hex 32)"', block)
        self.assertIn('[[ "$password" =~ ^[0-9a-f]{64}$ ]] || {', block)
        self.assertIn('echo "::add-mask::$password"', block)
        self.assertIn('echo "E2E_USER_PASSWORD=$password" >> "$GITHUB_ENV"', block)
        self.assertLess(
            block.index('echo "::add-mask::$password"'),
            block.index('echo "E2E_USER_PASSWORD=$password" >> "$GITHUB_ENV"'),
        )
        self.assertNotIn("upload-artifact", block)
        self.assertNotIn("GITHUB_OUTPUT", block)

    def test_oidc_bootstrap_and_smoke_share_ephemeral_password_after_exact_sha_guard(self) -> None:
        exact_guard = WORKFLOW.index("Wait for exact deployed checkout before production write")
        bootstrap = WORKFLOW.index("Reconcile synthetic smoke identity through GitHub OIDC")
        smoke = WORKFLOW.index("Authenticated production smoke · Symfony /s4 readiness")
        self.assertLess(exact_guard, bootstrap)
        self.assertLess(bootstrap, smoke)
        self.assertIn('GRINDFLOW_SMOKE_PASSWORD="$E2E_USER_PASSWORD"', WORKFLOW)
        self.assertIn('"X-GrindFlow-Expected-Sha: $GITHUB_SHA"', WORKFLOW)
        self.assertIn('bash scripts/production-smoke.sh >production-smoke.log 2>&1', WORKFLOW)

    def test_failure_reporting_remains_secret_free_and_fail_closed(self) -> None:
        self.assertIn("No request bodies, credentials, OIDC tokens or remote responses are included.", WORKFLOW)
        self.assertIn("The diagnostic payload is kept out of the public issue body.", WORKFLOW)
        self.assertNotIn('echo "$E2E_USER_PASSWORD"', WORKFLOW)
        self.assertNotIn("set -x", WORKFLOW)
        self.assertIn("Fail workflow after publishing diagnostics", WORKFLOW)

    def test_operational_closeout_requires_exact_main_smoke_success(self) -> None:
        recovery = WORKFLOW.index("Publish production recovery")
        close_notice = WORKFLOW.index("Closing the ephemeral-credential blocker.")
        self.assertLess(recovery, close_notice)
        self.assertIn("if: steps.smoke.outcome == 'success'", WORKFLOW)
        self.assertIn("EPHEMERAL_CREDENTIAL_ISSUE: '369'", WORKFLOW)
        self.assertIn('gh issue close "$EPHEMERAL_CREDENTIAL_ISSUE"', WORKFLOW)
        self.assertIn("Production Smoke success on exact-main commit $GITHUB_SHA", WORKFLOW)
        test_command = PACKAGE["scripts"]["test"]
        self.assertIn("tests/test_production_smoke_ephemeral_credentials.py", test_command)


if __name__ == "__main__":
    unittest.main()
