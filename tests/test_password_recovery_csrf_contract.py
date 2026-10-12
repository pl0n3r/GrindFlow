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

    def php_method(self, name: str) -> str:
        """Extrae únicamente el cuerpo del método solicitado (sin mezclar asserts)."""
        pattern = (
            r"(?ms)^    public function " + re.escape(name)
            + r"\(\): void\n    \{\n(?P<body>.*?)^    \}\n"
        )
        matches = list(re.finditer(pattern, self.integration))
        self.assertEqual(1, len(matches), f"PHPUnit method missing/duplicated: {name}")
        return matches[0].group("body")

    def test_forgot_password_without_valid_csrf_is_403_and_sends_no_mail(self):
        body = self.php_method("testForgotPasswordWithoutValidCsrfReturns403AndDoesNotSendMail")
        self.assertIn("'POST', '/forgot-password'", body)
        self.assertIn("'grindflow_password_recovery_request'", self.controller)
        self.assertRegex(body, r"assertResponseStatusCodeSame\(403\)")
        self.assertIn("SELECT COUNT(*) FROM gf_password_recovery_outbox", body)
        self.assertIn("SELECT COUNT(*) FROM gf_password_reset_tokens", body)
        self.assertIn("self::assertSame([], $mailer->messages())", body)
        self.assertIn("['_csrf_token' => 'synthetic-invalid-csrf-token']", body)

    def test_recover_password_without_valid_csrf_is_403_and_keeps_password(self):
        body = self.php_method("testRecoverPasswordWithoutValidCsrfReturns403AndKeepsPasswordAndToken")
        self.assertIn("'POST', '/recover-password'", body)
        self.assertIn("'grindflow_password_recovery_reset'", self.controller)
        self.assertIn("self::assertSame($initialHash", body)
        self.assertIn("SELECT token_hash FROM gf_password_reset_tokens", body)
        self.assertIn("password_verify($originalPassword, $initialHash)", body)
        self.assertIn("self::assertResponseStatusCodeSame(403)", body)
        self.assertIn("['_csrf_token' => 'synthetic-invalid-csrf-token']", body)

    def test_csrf_rejection_is_uniform_and_does_not_enumerate_accounts(self):
        body = self.php_method("testForgotCsrfRejectionDoesNotEnumerateAccounts")
        self.assertIn("'missing-'.$email", body)
        self.assertIn("self::assertSame($normalizeCsrf($known), $normalizeCsrf($unknown))", body)
        self.assertIn('name="_csrf_token" value="', body)
        self.assertIn("La respuesta es la misma exista o no una cuenta activa.", body)
        self.assertIn("self::assertSame([], $mailer->messages())", body)
        self.assertIn("['_csrf_token' => 'synthetic-invalid-csrf-token']", body)
        self.assertGreaterEqual(
            len(re.findall(r"assertResponseStatusCodeSame\(403\)", body)), 2,
        )


if __name__ == "__main__":
    unittest.main()
