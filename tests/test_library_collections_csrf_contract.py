"""Factory #444: offline executable-contract guard for Symfony Library CSRF tests.

PHPUnit owns behavioral execution against a disposable test database; these
unittest checks guard the promised method names, HTTP assertions and routes.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "symfony" / "tests" / "php" / "LibraryCollectionsCsrfTest.php"


class LibraryCollectionsCsrfContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = PHP.read_text(encoding="utf-8")

    def method(self, name: str) -> str:
        match = re.search(r"\bpublic function " + re.escape(name) + r"\(\): void", self.source)
        self.assertIsNotNone(match, f"PHPUnit must execute {name}")
        start = match.start()
        next_method = re.search(r"\n    (?:public|private|protected) function ", self.source[match.end():])
        end = match.end() + next_method.start() if next_method else len(self.source)
        return self.source[start:end]

    def test_missing_or_invalid_csrf_is_rejected_on_every_write_route(self):
        source = self.method("testMissingOrInvalidCsrfIsRejectedOnEveryWriteRoute")
        self.assertIn("foreach ($this->writeRoutes()", source)
        self.assertIn("['HTTP_X_CSRF_TOKEN' => 'synthetic-invalid-csrf']", source)
        self.assertIn("self::assertResponseStatusCodeSame(403)", source)
        self.assertIn("'invalid_csrf'", source)
        for route in (
            "/api/admin/library/collections",
            "/assets/'.$asset",
            "/variants/'.$variant",
        ):
            self.assertIn(route, self.source)
        self.assertIn("'POST'", self.source)
        self.assertIn("'PUT'", self.source)

    def test_rejected_write_does_not_change_state(self):
        source = self.method("testRejectedWriteDoesNotChangeState")
        for table in (
            "gf_vault_collections",
            "gf_vault_collection_assets",
            "gf_vault_asset_variants",
        ):
            self.assertIn(table, self.source)
        self.assertIn("$before", source)
        self.assertIn("$after", source)
        self.assertIn("self::assertSame($before, $after", source)
        self.assertIn("self::assertResponseStatusCodeSame(403)", source)

    def test_role_without_content_prepare_gets_library_write_forbidden(self):
        source = self.method("testRoleWithoutContentPrepareGetsLibraryWriteForbidden")
        self.assertIn("$this->withIdentity('model'", source)
        self.assertIn("'library_write_forbidden'", source)
        self.assertIn("self::assertResponseStatusCodeSame(403)", source)
        self.assertIn("foreach ($this->writeRoutes()", source)
        positive = self.method("testValidCsrfTokenAllowsCollectionCreation")
        self.assertIn("['data']['csrf']", positive)
        self.assertIn("['HTTP_X_CSRF_TOKEN' => $csrf]", positive)
        self.assertIn("self::assertResponseStatusCodeSame(201)", positive)


if __name__ == "__main__":
    unittest.main()
