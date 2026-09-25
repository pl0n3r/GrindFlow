"""Contrato del caller Factory Release v1 y paridad de versiones GrindFlow.

Factory es la dueña de la lógica de tags anotados, carreras e idempotencia:
el proyecto no copia ni relaja sus comprobaciones.
"""

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CANONICAL_CALLER = """name: GrindFlow Tag Release

on:
  push:
    branches: [main]

permissions:
  contents: read

concurrency:
  group: grindflow-tag-release-${{ github.ref }}
  queue: max
  cancel-in-progress: false

jobs:
  release:
    name: Publish GitHub Release
    if: github.repository == 'pl0n3r/GrindFlow' && github.ref == 'refs/heads/main'
    permissions:
      contents: write
    uses: pl0n3r/factory/.github/workflows/release.yml@v1
    with:
      version_source: config/version.php
      version_format: php-array
      version_key: number
"""
SEMVER = re.compile(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)")


def release_version(php: str, package: str, lock: str) -> str:
    match = re.search(r"""['"]number['"]\s*=>\s*['"]([^'"]+)['"]""", php)
    if match is None or SEMVER.fullmatch(match.group(1)) is None:
        raise ValueError("config/version.php: number debe ser SemVer estricto")
    try:
        npm, npm_lock = json.loads(package), json.loads(lock)
    except json.JSONDecodeError as exc:
        raise ValueError("metadatos npm inválidos") from exc
    version = match.group(1)
    if any(item != version for item in (
        npm.get("version"),
        npm_lock.get("version"),
        npm_lock.get("packages", {}).get("", {}).get("version"),
    )):
        raise ValueError("version.php, package.json y package-lock.json divergen")
    return version


def validate_caller(source: str) -> None:
    """Comprueba manifiesto efectivo íntegro; ignora coincidencias en comentarios."""
    if source != CANONICAL_CALLER:
        raise ValueError("caller de release diferente del aprobado")


class ReleaseAdoptionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.caller = (ROOT / ".github/workflows/tag-release.yml").read_text(encoding="utf-8")
        cls.php = (ROOT / "config/version.php").read_text(encoding="utf-8")
        cls.package = (ROOT / "package.json").read_text(encoding="utf-8")
        cls.lock = (ROOT / "package-lock.json").read_text(encoding="utf-8")

    def test_caller_is_push_only_with_job_scoped_write_and_approved_ref(self):
        validate_caller(self.caller)

    def test_untrusted_events_secrets_permissions_ref_inputs_and_jobs_fail(self):
        mutations = (
            ("    branches: [main]\n\npermissions:", "    branches: [main]\n  workflow_dispatch:\n\npermissions:"),
            ("  push:\n", "  pull_request_target:\n  push:\n"),
            ("permissions:\n  contents: read", "permissions:\n  contents: write"),
            ("    permissions:\n      contents: write\n", ""),
            ("    with:\n", "    secrets:  inherit\n    with:\n"),
            ("    uses: pl0n3r/factory/.github/workflows/release.yml@v1\n",
             "    # pl0n3r/factory/.github/workflows/release.yml@v1\n"
             "    uses: pl0n3r/factory/.github/workflows/release.yml@v2\n"),
            ("release.yml@v1", "release.yml@main"),
            ("      version_key: number\n", "      version_key: version\n"),
            ("      version_format: php-array\n", "      version_format: php-const\n"),
            ("      version_source: config/version.php\n", "      version_source: config/version.json\n"),
            ("      contents: write\n", "      contents: write\n      issues: write\n"),
            ("  queue: max\n", ""),
        )
        for old, new in mutations:
            with self.subTest(old=old, new=new):
                candidate = self.caller.replace(old, new, 1)
                self.assertNotEqual(candidate, self.caller)
                with self.assertRaises(ValueError):
                    validate_caller(candidate)
        with self.assertRaises(ValueError):
            validate_caller(self.caller + "  unexpected:\n    runs-on: ubuntu-latest\n")

    def test_product_version_and_npm_lock_match(self):
        php_version = re.search(r"""['"]number['"]\s*=>\s*['"]([^'"]+)['"]""", self.php)
        self.assertIsNotNone(php_version)
        expected = php_version.group(1)
        self.assertIsNotNone(SEMVER.fullmatch(expected))
        self.assertEqual(release_version(self.php, self.package, self.lock), expected)

    def test_invalid_semver_missing_key_and_npm_drift_fail(self):
        version = release_version(self.php, self.package, self.lock)
        major, minor, patch = version.split(".")
        canonical_number = f"'number' => '{version}'"
        invalid_php = (
            self.php.replace(canonical_number, f"'number' => '0{major}.{minor}.{patch}'", 1),
            self.php.replace("'number'", "'version'", 1),
            self.php.replace(canonical_number, "'number' => 'invalid'", 1),
        )
        for candidate in invalid_php:
            with self.subTest(php=candidate):
                self.assertNotEqual(candidate, self.php)
                with self.assertRaises(ValueError):
                    release_version(candidate, self.package, self.lock)

        drift = version + "-drift"
        changed_npm = json.loads(self.package)
        changed_npm["version"] = drift
        changed_lock_top = json.loads(self.lock)
        changed_lock_top["version"] = drift
        changed_lock_root = json.loads(self.lock)
        changed_lock_root["packages"][""]["version"] = drift
        candidates = (
            (json.dumps(changed_npm), self.lock),
            (self.package, json.dumps(changed_lock_top)),
            (self.package, json.dumps(changed_lock_root)),
        )
        for package, lock in candidates:
            with self.subTest(package=package[:70], lock=lock[:70]):
                with self.assertRaises(ValueError):
                    release_version(self.php, package, lock)

    def test_grindflow_ci_runs_release_contract_without_removing_validate(self):
        ci = (ROOT / ".github/workflows/grindflow-ci.yml").read_text(encoding="utf-8")
        self.assertIn("python3 -m unittest tests/test_release_adoption.py", ci)
        self.assertIn("name: validate", ci)


if __name__ == "__main__":
    unittest.main()
