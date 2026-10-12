"""Contrato de regresiones Symfony CSRF con cuentas y tokens sintéticos.

La suite PHPUnit ejecuta las peticiones HTTP y comprueba la DB descartable.
Estos tests validan la presencia del contrato sin simular resultados PHP.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "symfony/tests/php/PasswordRecoveryCsrfTest.php"
CONTROLLER = ROOT / "symfony/src/Http/Controller/PasswordRecoveryController.php"


class PasswordRecoveryCsrfContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.integration = PHP_TEST.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")

    def test_forgot_password_without_valid_csrf_is_403_and_sends_no_mail(self):
        self.assertIn("testForgotPasswordWithoutValidCsrfReturns403AndDoesNotSendMail", self.integration)
        self.assertIn("'POST', '/forgot-password'", self.integration)
        self.assertIn("'grindflow_password_recovery_request'", self.controller)
        self.assertRegex(self.integration, r"assertResponseStatusCodeSame\(403\)")
        self.assertIn("SELECT COUNT(*) FROM gf_password_recovery_outbox", self.integration)
        self.assertIn("SELECT COUNT(*) FROM gf_password_reset_tokens", self.integration)
        self.assertIn("self::assertSame([], $mailer->messages())", self.integration)
        self.assertIn("['_csrf_token' => 'synthetic-invalid-csrf-token']", self.integration)

    def test_recover_password_without_valid_csrf_is_403_and_keeps_password(self):
        self.assertIn("testRecoverPasswordWithoutValidCsrfReturns403AndKeepsPasswordAndToken", self.integration)
        self.assertIn("'POST', '/recover-password'", self.integration)
        self.assertIn("'grindflow_password_recovery_reset'", self.controller)
        self.assertIn("self::assertSame($initialHash", self.integration)
        self.assertIn("SELECT token_hash FROM gf_password_reset_tokens", self.integration)
        self.assertIn("password_verify($originalPassword, $initialHash)", self.integration)
        self.assertIn("self::assertResponseStatusCodeSame(403)", self.integration)

    def test_csrf_rejection_is_uniform_and_does_not_enumerate_accounts(self):
        self.assertIn("testForgotCsrfRejectionDoesNotEnumerateAccounts", self.integration)
        self.assertIn("'missing-'.$email", self.integration)
        self.assertIn("self::assertSame($known, $unknown)", self.integration)
        self.assertIn("La respuesta es la misma exista o no una cuenta activa.", self.integration)
        self.assertIn("self::assertSame([], $mailer->messages())", self.integration)
        self.assertGreaterEqual(
            len(re.findall(r"assertResponseStatusCodeSame\(403\)", self.integration)), 4,
        )


if __name__ == "__main__":
    unittest.main()
