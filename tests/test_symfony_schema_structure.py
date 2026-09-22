"""Tests for Symfony source-only structural inventory."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "symfony-schema-structure.py"
SPEC = importlib.util.spec_from_file_location("symfony_schema_structure", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("unable to load Symfony structure module")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class SymfonySchemaStructureTest(unittest.TestCase):
    def test_split_top_level_preserves_nested_commas(self) -> None:
        raw = "id CHAR(36) NOT NULL, amount DECIMAL(12, 2) NOT NULL, UNIQUE KEY uq (id, amount)"
        self.assertEqual(3, len(MODULE.split_top_level(raw)))

    def test_parses_table_indexes_foreign_key_and_trigger(self) -> None:
        migration = """<?php
        $this->addSql(<<<'SQL'
        CREATE TABLE gf_child (
            id CHAR(36) NOT NULL,
            parent_id CHAR(36) DEFAULT NULL,
            label VARCHAR(120) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gf_child_label (label),
            INDEX ix_gf_child_parent (parent_id),
            CONSTRAINT fk_gf_child_parent FOREIGN KEY (parent_id)
                REFERENCES gf_parent (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
        CREATE TRIGGER gf_child_no_delete BEFORE DELETE ON gf_child
        FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000'; END
        SQL);
        """
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "Version1.php"
            path.write_text(migration, encoding="utf-8")
            inventory = MODULE.build_inventory(Path(folder))

        self.assertFalse(inventory["database_contacted"])
        self.assertEqual("gf_child", inventory["tables"][0]["name"])
        self.assertEqual(
            {"name": "parent_id", "type": "char(36)", "nullable": True},
            next(row for row in inventory["tables"][0]["columns"] if row["name"] == "parent_id"),
        )
        self.assertEqual(3, len(inventory["tables"][0]["indexes"]))
        self.assertEqual("SET NULL", inventory["tables"][0]["foreign_keys"][0]["on_delete"])
        self.assertEqual("gf_child_no_delete", inventory["triggers"][0]["name"])

    def test_rejects_unprefixed_symfony_table(self) -> None:
        with self.assertRaisesRegex(ValueError, "gf_ prefix"):
            MODULE.parse_table("users", "id CHAR(36) NOT NULL", "Version1.php")


if __name__ == "__main__":
    unittest.main()
