from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


class PasswordRecoveryAcceptanceTests(unittest.TestCase):
    def test_reset_security_contract(self) -> None:
        controller = (ROOT / "symfony/src/Http/Controller/PasswordRecoveryController.php").read_text()
        migration = (ROOT / "symfony/migrations/Version20260926030500.php").read_text()
        self.assertIn("hash('sha256', $token)", controller)
        self.assertIn("random_bytes(32)", controller)
        self.assertIn("time() + 3600", controller)
        self.assertIn("FOR UPDATE", controller)
        self.assertIn("gf_password_reset_tokens", migration)
        self.assertNotIn("token VARCHAR", migration)

    def test_authenticated_change_and_session_invalidation(self) -> None:
        controller = (ROOT / "symfony/src/Http/Controller/AccountSecurityController.php").read_text()
        self.assertIn("$request->getSession()->invalidate()", controller)
        self.assertIn("password_changed", controller)
        self.assertIn("PasswordPolicy", controller)

    def test_mailer_is_server_side_and_token_safe(self) -> None:
        notifier = (ROOT / "symfony/src/Infrastructure/Mail/NativePasswordRecoveryNotifier.php").read_text()
        template = (ROOT / "symfony/templates/identity/recover-password.html.twig").read_text()
        self.assertIn("GRINDFLOW_MAIL_FROM", (ROOT / "symfony/config/services.yaml").read_text())
        self.assertIn("/recover-password#token=", notifier)
        self.assertIn("no-referrer", template)
        self.assertNotRegex(template, re.compile(r"\?token="))

    def test_privacy_inventory_covers_password_reset_and_mail_provider(self) -> None:
        inventory = (ROOT / "datos.yml").read_text()
        self.assertIn("password_reset_token_hash", inventory)
        self.assertIn("password_reset_expires_at", inventory)
        self.assertIn("security_event", inventory)

    def test_behavior_suite_exists(self) -> None:
        suite = (ROOT / "symfony/tests/php/PasswordRecoveryTest.php").read_text()
        self.assertIn("testRecoveryIsNonEnumeratingHashedOneUseAndChangesPassword", suite)
        self.assertIn("testReissueInvalidatesPreviousTokenAndCommonPasswordIsRejected", suite)


if __name__ == "__main__":
    unittest.main()
