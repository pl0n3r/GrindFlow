from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class StaffOpsAcceptanceTest(unittest.TestCase):
    def test_authentication_and_replay_contract(self):
        auth = (ROOT / "symfony/src/Ops/Security/StaffOpsRequestAuthenticator.php").read_text()
        config = (ROOT / "symfony/src/Ops/Security/StaffOpsConfiguration.php").read_text()
        migration = (ROOT / "symfony/migrations/Version20260930024500.php").read_text()
        tests = (ROOT / "symfony/tests/php/StaffOpsRequestAuthenticatorTest.php").read_text()
        self.assertIn("canonicalRequest", auth)
        self.assertIn("hash_equals", auth)
        self.assertIn("NONCE_TTL = 600", auth)
        self.assertIn("rateLimited", auth)
        self.assertIn("throw new StaffOpsAuthException(429", auth)
        self.assertIn("GRINDFLOW_OPS_KEYS_JSON", (ROOT / "symfony/config/services.yaml").read_text())
        self.assertIn("secretFor", config)
        self.assertIn("ix_gf_ops_rate_limits_window", migration)
        self.assertIn("testRateLimitRejectsOnlyAfterTransactionalNonceConsumption", tests)

    def test_routes_and_subject_boundary_contract(self):
        loader = (ROOT / "symfony/src/Ops/Routing/StaffOpsRouteLoader.php").read_text()
        controller = (ROOT / "symfony/src/Ops/Http/StaffOpsController.php").read_text()
        tests = (ROOT / "symfony/tests/php/StaffOpsRequestAuthenticatorTest.php").read_text()
        self.assertIn("!$this->configuration->enabled()", loader)
        for path in (
            "/ops/staff",
            "/ops/staff/{id}/suspend",
            "/ops/staff/{id}/reactivate",
            "/ops/staff/{id}/role",
            "/ops/staff/{id}/password-reset",
            "/ops/summary",
        ):
            self.assertIn(path, loader)
        self.assertIn("private const STAFF_ROLES = ['admin', 'editor']", controller)
        self.assertIn("platform_role IN ('admin','editor')", controller)
        self.assertNotIn("platform_role IN ('admin','editor','studio'", controller)
        self.assertIn("testRoutesAreAbsentWhenConfigurationIsDisabled", tests)

    def test_idempotency_contract(self):
        source = (ROOT / "symfony/src/Ops/Security/StaffOpsIdempotency.php").read_text()
        migration = (ROOT / "symfony/migrations/Version20260930024500.php").read_text()
        self.assertIn("RETENTION_SECONDS = 86400", source)
        self.assertIn("Idempotency-Key", source)
        self.assertIn("request_fingerprint", source)
        self.assertIn("idempotency_conflict", source)
        self.assertIn("response_json", source)
        self.assertIn("gf_ops_idempotency", migration)

    def test_operations_audit_and_handoff_contract(self):
        controller = (ROOT / "symfony/src/Ops/Http/StaffOpsController.php").read_text()
        migration = (ROOT / "symfony/migrations/Version20260930024500.php").read_text()
        inventory = (ROOT / "datos.yml").read_text()
        for action in (
            "search_staff",
            "invite_staff",
            "suspend_staff",
            "reactivate_staff",
            "change_staff_role",
            "request_staff_password_reset",
            "staff_summary",
        ):
            self.assertIn(action, controller)
        self.assertIn("gf_password_recovery_outbox", controller)
        self.assertIn("gf_ops_audit", controller)
        self.assertIn("session_generation = session_generation + 1", controller)
        self.assertIn("gf_ops_staff_login_failures", migration)
        for treatment in ("staff_identity", "staff_contact", "staff_access_metadata"):
            self.assertIn(treatment, inventory)


if __name__ == "__main__":
    unittest.main()
