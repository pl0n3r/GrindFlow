from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"
HISTORICAL = ROOT / "docs/MIGRATION-LARAVEL.md"
VERSION = ROOT / "config/version.php"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"

MIGRATION_IDS = ("GF-MIG-001", "GF-MIG-002", "GF-MIG-003", "GF-MIG-004")
FIRST_CURRENT_HEADING = "### GF-FR-006B — Tenant-scoped daily Traffic CSV"


class RequirementsLaravelMigrationCompactionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.requirements = REQUIREMENTS.read_text(encoding="utf-8")
        cls.historical = HISTORICAL.read_text(encoding="utf-8")

    def test_gf_mig_requirements_live_only_in_historical_document(self):
        for requirement_id in MIGRATION_IDS:
            with self.subTest(requirement_id=requirement_id):
                self.assertNotRegex(
                    self.requirements,
                    rf"(?m)^### {re.escape(requirement_id)}\b",
                )
                self.assertEqual(
                    len(
                        re.findall(
                            rf"^### {re.escape(requirement_id)}\b",
                            self.historical,
                            flags=re.MULTILINE,
                        )
                    ),
                    1,
                )

    def test_requirements_links_historical_laravel_migration(self):
        self.assertIn(
            "[`MIGRATION-LARAVEL.md`](MIGRATION-LARAVEL.md#requisitos-históricos-gf-mig-001004)",
            self.requirements,
        )
        self.assertIn("## Migration requirements", self.requirements)

    def test_current_functional_requirements_remain_in_requirements(self):
        self.assertIn(FIRST_CURRENT_HEADING, self.requirements)
        current = self.requirements.split(FIRST_CURRENT_HEADING, 1)[1]
        self.assertIn("**Status:** implemented", current)
        self.assertIn("### GF-FR-006C — Tracked-link lifecycle", current)
        self.assertNotIn(FIRST_CURRENT_HEADING, self.historical)

    def test_migration_ids_are_preserved_once(self):
        definitions = re.findall(
            r"^### (GF-MIG-00[1-4])\b",
            self.requirements + "\n" + self.historical,
            flags=re.MULTILINE,
        )
        self.assertEqual(sorted(definitions), list(MIGRATION_IDS))

    def test_release_identity_matches_across_manifests(self):
        version_text = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        lock = json.loads(LOCK.read_text(encoding="utf-8"))

        match = re.search(r"'number'\s*=>\s*'([^']+)'", version_text)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertRegex(release, r"^\d+\.\d+\.\d+$")
        self.assertEqual(package["version"], release)
        self.assertEqual(lock["version"], release)
        self.assertEqual(lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
