"""Execute real Symfony HTTP denials on a disposable MariaDB instance.

This acceptance intentionally does not substitute source-only assertions for
WebTestCase execution. Run after Symfony Composer install and migrations in a
CI job with DATABASE_URL pointed at an isolated database.
"""
from __future__ import annotations

from functools import lru_cache
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SYMFONY = ROOT / "symfony"

HTTP_CASES = {
    "Vault": (
        "VaultTest.php",
        "testPrivatePhotoUploadListDownloadAndCrossTenantGuards",
    ),
    "Composer": (
        "ScheduleDraftTest.php",
        "testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish",
    ),
}


@lru_cache(maxsize=1)
def _execute_web_tests() -> dict[str, str]:
    php = shutil.which("php")
    runner = SYMFONY / "vendor" / "bin" / "simple-phpunit"
    database_url = os.environ.get("DATABASE_URL", "")
    if php is None or not runner.is_file() or not database_url:
        raise AssertionError(
            "PHP CLI, Symfony vendor/bin/simple-phpunit and an isolated DATABASE_URL "
            "are required; do not claim an HTTP pass from static source inspection"
        )
    if os.environ.get("APP_ENV") != "test":
        raise AssertionError("APP_ENV=test is required for disposable HTTP tests")

    evidence: dict[str, str] = {}
    for module, (_, method) in HTTP_CASES.items():
        result = subprocess.run(
            [php, str(runner), "-c", "phpunit.xml.dist", "--filter", method],
            cwd=SYMFONY,
            text=True,
            capture_output=True,
            timeout=180,
            check=False,
        )
        output = result.stdout + "\n" + result.stderr
        if result.returncode != 0:
            raise AssertionError(
                f"{module} WebTestCase failed (exit={result.returncode}): "
                + output[-1400:]
            )
        # Success with zero tests is a false positive, not execution evidence.
        plain_output = re.sub(r"\x1b\[[0-9;]*m", "", output)
        if not re.search(r"(?m)^OK\s*\(\s*1\s+test\b", plain_output):
            raise AssertionError(
                f"{module} WebTestCase did not report exactly one passed test: "
                + output[-800:]
            )
        evidence[module] = method
    return evidence


def _method_source(path: Path, method: str) -> str:
    source = path.read_text(encoding="utf-8")
    match = re.search(r"\bpublic\s+function\s+" + re.escape(method) + r"\s*\(", source)
    if match is None or "extends WebTestCase" not in source:
        raise AssertionError(f"Missing genuine Symfony WebTestCase: {method}")
    next_method = re.search(
        r"\bpublic\s+function\s+test[A-Za-z0-9_]+\s*\(",
        source[match.end():],
    )
    end = match.end() + next_method.start() if next_method else len(source)
    return source[match.start():end]


class MvpSymfonyHttpNegativeTests(unittest.TestCase):
    def test_vault_and_composer_negative_routes_execute(self) -> None:
        evidence = _execute_web_tests()
        self.assertEqual(set(evidence), {"Vault", "Composer"})
        for module, (_, method) in HTTP_CASES.items():
            self.assertEqual(evidence[module], method)

    def test_tenant_role_denials_use_disposable_fixtures(self) -> None:
        # First execute the real HTTP suites, then verify which tenant/role
        # denials those suites exercise; structural checks alone are not AC-02.
        self.assertEqual(len(_execute_web_tests()), 2)
        for module, (filename, method) in HTTP_CASES.items():
            with self.subTest(module=module):
                path = SYMFONY / "tests" / "php" / filename
                case = _method_source(path, method)
                self.assertIn("Uuid::v7()", case)
                self.assertIn("gf_identity_users", case)
                self.assertIn("gf_identity_memberships", case)
                self.assertIn("'organization_id'", case)
                self.assertIn("'role'", case)
                self.assertIn("foreign", case.lower())
                self.assertIn("$client->request(", case)
                for expected in (401, 403, 404):
                    self.assertIn(
                        f"self::assertResponseStatusCodeSame({expected})", case,
                        f"{module}: missing HTTP {expected} denial",
                    )
        vault_source = _method_source(
            SYMFONY / "tests" / "php" / HTTP_CASES["Vault"][0],
            HTTP_CASES["Vault"][1],
        )
        self.assertIn("finally {", vault_source)
        self.assertIn("$db->delete('gf_identity_users'", vault_source)
        composer = (SYMFONY / "tests" / "php" / HTTP_CASES["Composer"][0]).read_text(
            encoding="utf-8"
        )
        self.assertIn("implements FacebookPageTransport", composer)
        self.assertIn("SchedulePhotoTransport", composer)


if __name__ == "__main__":
    unittest.main()
