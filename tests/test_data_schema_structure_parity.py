"""Contract tests for GF-ARCH-002 Symfony structural parity."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "data-schema-structure-parity.py"
SPEC = importlib.util.spec_from_file_location("data_schema_structure_parity", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("unable to load structural parity module")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class DataSchemaStructureParityTest(unittest.TestCase):
    def source(self) -> dict:
        return {
            "contract": MODULE.SOURCE_CONTRACT,
            "source_only": True,
            "database_contacted": False,
            "tables": [{
                "name": "gf_child",
                "columns": [
                    {"name": "id", "type": "char(36)", "nullable": False},
                    {"name": "parent_id", "type": "char(36)", "nullable": True},
                ],
                "indexes": [{"name": "PRIMARY", "unique": True, "columns": ["id"]}],
                "foreign_keys": [{
                    "name": "fk_child_parent",
                    "columns": ["parent_id"],
                    "referenced_table": "gf_parent",
                    "referenced_columns": ["id"],
                    "on_delete": "SET NULL",
                }],
            }],
            "triggers": [{
                "name": "gf_child_no_delete",
                "table": "gf_child",
                "timing": "BEFORE",
                "event": "DELETE",
            }],
            "checks": {"duplicate_tables": [], "duplicate_triggers": []},
        }

    def snapshot(self) -> dict:
        source = self.source()
        return {
            "contract": MODULE.SNAPSHOT_CONTRACT,
            "metadata_only": True,
            "contains_row_data": False,
            "tables": [source["tables"][0].copy(), {
                "name": "legacy_jobs",
                "columns": [],
                "indexes": [],
                "foreign_keys": [],
            }],
            "triggers": [source["triggers"][0].copy()],
        }

    def test_exact_structure_passes(self) -> None:
        report = MODULE.build_report(self.source(), self.snapshot())
        self.assertTrue(report["compatible"])
        self.assertEqual(["legacy_jobs"], report["informational"]["non_gf_tables"])

    def test_missing_column_fails(self) -> None:
        snapshot = self.snapshot()
        snapshot["tables"][0]["columns"] = [snapshot["tables"][0]["columns"][0]]
        report = MODULE.build_report(self.source(), snapshot)
        self.assertFalse(report["compatible"])
        self.assertIn("gf_child:columns:missing:parent_id", report["checks"]["structure_mismatches"])

    def test_index_mismatch_fails(self) -> None:
        snapshot = self.snapshot()
        snapshot["tables"][0]["indexes"][0] = {
            "name": "PRIMARY",
            "unique": False,
            "columns": ["id"],
        }
        report = MODULE.build_report(self.source(), snapshot)
        self.assertIn("gf_child:indexes:mismatch:PRIMARY", report["checks"]["structure_mismatches"])

    def test_mariadb_integer_display_width_is_semantically_ignored(self) -> None:
        source = self.source()
        source["tables"][0]["columns"].append(
            {"name": "counter", "type": "smallint unsigned", "nullable": False}
        )
        snapshot = self.snapshot()
        snapshot["tables"][0]["columns"].append(
            {"name": "counter", "type": "smallint(5) unsigned", "nullable": False}
        )
        report = MODULE.build_report(source, snapshot)
        self.assertTrue(report["compatible"])

    def test_mariadb_implicit_fk_support_index_is_ignored(self) -> None:
        snapshot = self.snapshot()
        snapshot["tables"][0]["indexes"].append({
            "name": "fk_child_parent",
            "unique": False,
            "columns": ["parent_id"],
        })
        report = MODULE.build_report(self.source(), snapshot)
        self.assertTrue(report["compatible"])

    def test_unrelated_unexpected_index_still_fails(self) -> None:
        snapshot = self.snapshot()
        snapshot["tables"][0]["indexes"].append({
            "name": "ix_unexpected",
            "unique": False,
            "columns": ["parent_id"],
        })
        report = MODULE.build_report(self.source(), snapshot)
        self.assertIn("gf_child:indexes:unexpected:ix_unexpected", report["checks"]["structure_mismatches"])

    def test_unknown_gf_table_fails(self) -> None:
        snapshot = self.snapshot()
        snapshot["tables"].append({
            "name": "gf_orphan",
            "columns": [],
            "indexes": [],
            "foreign_keys": [],
        })
        report = MODULE.build_report(self.source(), snapshot)
        self.assertIn("tables:unexpected:gf_orphan", report["checks"]["structure_mismatches"])

    def test_missing_trigger_fails(self) -> None:
        snapshot = self.snapshot()
        snapshot["triggers"] = []
        report = MODULE.build_report(self.source(), snapshot)
        self.assertIn("schema:triggers:missing:gf_child_no_delete", report["checks"]["structure_mismatches"])

    def test_snapshot_with_row_data_is_rejected(self) -> None:
        snapshot = self.snapshot()
        snapshot["contains_row_data"] = True
        source = self.source()
        with self.assertRaisesRegex(ValueError, "contains_row_data=false"):
            MODULE.build_report(source, snapshot)

    def test_dirty_source_is_rejected(self) -> None:
        source = self.source()
        source["checks"]["duplicate_tables"] = ["gf_child"]
        snapshot = self.snapshot()
        with self.assertRaisesRegex(ValueError, "source structure is not clean"):
            MODULE.build_report(source, snapshot)


if __name__ == "__main__":
    unittest.main()
