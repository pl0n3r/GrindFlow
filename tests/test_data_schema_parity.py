"""Contract tests for the metadata-only GF-ARCH-002 parity comparator."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "data-schema-parity.py"
SPEC = importlib.util.spec_from_file_location("data_schema_parity", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class DataSchemaParityTest(unittest.TestCase):
    """Prove fail-closed behavior without accessing any database."""

    def source(self) -> dict:
        """Build a minimal valid source inventory."""
        return {
            "contract": MODULE.SOURCE_CONTRACT,
            "database_contacted": False,
            "laravel": [{"table": "users"}],
            "symfony": [{"table": "gf_accounts"}],
        }

    def snapshot(self, *names: str) -> dict:
        """Build a metadata-only database snapshot fixture."""
        return {
            "contract": MODULE.SNAPSHOT_CONTRACT,
            "metadata_only": True,
            "contains_row_data": False,
            "tables": [{"name": name} for name in names],
        }

    def test_matching_snapshot_is_compatible(self) -> None:
        """A complete table set passes and unrelated legacy tables stay informational."""
        report = MODULE.build_report(self.source(), self.snapshot("users", "gf_accounts", "legacy_jobs"))
        self.assertTrue(report["compatible"])
        self.assertEqual(["legacy_jobs"], report["informational"]["extra_nonblocking_tables"])

    def test_missing_source_table_fails(self) -> None:
        """A table declared in source but absent from the snapshot blocks parity."""
        report = MODULE.build_report(self.source(), self.snapshot("users"))
        self.assertFalse(report["compatible"])
        self.assertEqual(["gf_accounts"], report["checks"]["missing_source_tables"])

    def test_unknown_symfony_namespace_table_fails(self) -> None:
        """Untracked gf_ tables are treated as Symfony schema drift and fail closed."""
        report = MODULE.build_report(self.source(), self.snapshot("users", "gf_accounts", "gf_orphan"))
        self.assertFalse(report["compatible"])
        self.assertEqual(["gf_orphan"], report["checks"]["unknown_gf_tables"])

    def test_duplicate_snapshot_table_fails(self) -> None:
        """Duplicate metadata rows cannot silently collapse into a valid snapshot."""
        report = MODULE.build_report(self.source(), self.snapshot("users", "users", "gf_accounts"))
        self.assertFalse(report["compatible"])
        self.assertEqual(["users"], report["checks"]["duplicate_snapshot_tables"])

    def test_snapshot_must_prohibit_row_data(self) -> None:
        """Snapshots without an explicit no-row-data guarantee are rejected."""
        snapshot = self.snapshot("users", "gf_accounts")
        snapshot["contains_row_data"] = True
        with self.assertRaisesRegex(ValueError, "contains_row_data=false"):
            MODULE.build_report(self.source(), snapshot)


if __name__ == "__main__":
    unittest.main()
