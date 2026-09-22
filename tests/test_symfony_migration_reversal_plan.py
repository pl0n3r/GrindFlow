"""Tests for automatic Symfony migration reversal planning."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "symfony-migration-reversal-plan.py"
SPEC = importlib.util.spec_from_file_location("symfony_migration_reversal_plan", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("unable to load Symfony migration reversal planner")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class SymfonyMigrationReversalPlanTest(unittest.TestCase):
    def test_discovers_all_repository_migrations_newest_first(self) -> None:
        plan = MODULE.build_plan()
        files = sorted((ROOT / "symfony" / "migrations").glob("Version*.php"))
        expected = sorted((path.name for path in files), reverse=True)

        self.assertEqual(len(expected), plan["count"])
        self.assertEqual(expected, [row["file"] for row in plan["migrations"]])
        self.assertTrue(plan["source_only"])
        self.assertFalse(plan["database_contacted"])
        self.assertEqual("down", plan["direction"])

    def test_classes_match_filenames(self) -> None:
        plan = MODULE.build_plan()
        for row in plan["migrations"]:
            version = row["file"].removeprefix("Version").removesuffix(".php")
            self.assertEqual(version, row["version"])
            self.assertEqual(
                f"GrindFlow\\Migrations\\Version{version}",
                row["class"],
            )

    def test_rejects_unexpected_migration_filename(self) -> None:
        with tempfile.TemporaryDirectory() as folder:
            directory = Path(folder)
            (directory / "Version20260922120000.php").write_text("<?php", encoding="utf-8")
            (directory / "VersionNEXT.php").write_text("<?php", encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "unsupported migration filename"):
                MODULE.build_plan(directory)

    def test_rejects_empty_migration_directory(self) -> None:
        with tempfile.TemporaryDirectory() as folder:
            with self.assertRaisesRegex(ValueError, "no Symfony migrations discovered"):
                MODULE.build_plan(Path(folder))


if __name__ == "__main__":
    unittest.main()
