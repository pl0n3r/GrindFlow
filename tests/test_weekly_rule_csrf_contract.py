"""Executable source contract for synthetic Symfony weekly-rule CSRF regressions.

The PHP integration suite supplies HTTP/DB evidence; this file verifies the
specific test cases without borrowing assertions from an unrelated method.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP_SUITE = ROOT / "symfony/tests/php/WeeklyRuleCsrfTest.php"
CONTROLLER = ROOT / "symfony/src/Http/Controller/ContentRuleController.php"


class WeeklyRuleCsrfContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.integration = PHP_SUITE.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")

    def php_case(self, name: str) -> str:
        pattern = (
            r"(?ms)^    public function " + re.escape(name)
            + r"\(\): void\n    \{\n(?P<body>.*?)^    \}\n"
        )
        cases = list(re.finditer(pattern, self.integration))
        self.assertEqual(len(cases), 1, f"missing or duplicated PHP test {name}")
        return cases[0].group("body")

    def test_missing_or_invalid_csrf_is_rejected_with_403(self):
        case = self.php_case("testMissingOrInvalidCsrfIsRejectedWith403AndKeepsRule")
        self.assertIn("'editor'", case)
        self.assertIn("foreach ([null, 'synthetic-invalid-csrf']", case)
        self.assertIn("'PUT', '/api/admin/rules/weekly'", case)
        self.assertIn("self::assertResponseStatusCodeSame(403)", case)
        self.assertIn("self::assertSame('invalid_csrf'", case)
        self.assertIn("self::assertSame($initial", case)
        self.assertIn("gf_content_rules WHERE organization_id = ?", case)
        self.assertIn("if ($csrf !== null)", self.integration)
        self.assertIn("'HTTP_X_CSRF_TOKEN'", self.integration)
        self.assertIn("'grindflow_weekly_rule'", self.controller)

    def test_role_without_content_prepare_is_forbidden_before_csrf(self):
        case = self.php_case("testRoleWithoutContentPrepareIsForbiddenBeforeCsrf")
        self.assertIn("'model'", case)
        self.assertIn("'PUT', '/api/admin/rules/weekly'", case)
        self.assertIn("self::headers(null)", case)
        self.assertIn("self::assertResponseStatusCodeSame(403)", case)
        self.assertIn("rule_management_forbidden", case)
        self.assertIn("SELECT COUNT(*) FROM gf_content_rules", case)
        role_gate = "['content_prepare']"
        csrf_gate = "isCsrfTokenValid('grindflow_weekly_rule'"
        self.assertIn(role_gate, self.controller)
        self.assertIn(csrf_gate, self.controller)
        self.assertLess(self.controller.index(role_gate), self.controller.index(csrf_gate))

    def test_valid_csrf_passes_the_csrf_gate(self):
        case = self.php_case("testValidCsrfPassesTheCsrfGate")
        self.assertIn("'editor'", case)
        self.assertIn("CsrfTokenManagerInterface::class", case)
        self.assertIn("getToken('grindflow_weekly_rule')", case)
        self.assertIn("RequestStack::class", case)
        self.assertIn("$stack->push($request)", case)
        self.assertIn("$request->getSession()->save()", case)
        self.assertIn("$stack->pop()", case)
        self.assertIn("self::headers($csrf)", case)
        self.assertIn("'PUT', '/api/admin/rules/weekly'", case)
        self.assertIn("self::assertResponseIsSuccessful()", case)
        self.assertIn("self::assertSame('review_only'", case)
        self.assertIn("SELECT COUNT(*) FROM gf_content_rules", case)
        self.assertIn("self::assertSame(1", case)


if __name__ == "__main__":
    unittest.main()
