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

    def test_applies_create_alter_indexes_foreign_key_and_trigger(self) -> None:
        migration = """<?php
        public function up($schema): void
        {
            $this->addSql(<<<'SQL'
                CREATE TABLE gf_child (
                    id CHAR(36) NOT NULL,
                    parent_id CHAR(36) DEFAULT NULL,
                    label VARCHAR(120) NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_gf_child_label (label)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                SQL);
            $this->addSql(<<<'SQL'
                ALTER TABLE gf_child
                    ADD private_note VARCHAR(280) DEFAULT NULL,
                    ADD INDEX ix_gf_child_parent (parent_id),
                    ADD CONSTRAINT fk_gf_child_parent FOREIGN KEY (parent_id)
                        REFERENCES gf_parent (id) ON DELETE SET NULL
                SQL);
            $this->addSql(<<<'SQL'
                CREATE TRIGGER gf_child_no_delete BEFORE DELETE ON gf_child
                FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000'; END
                SQL);
        }

        public function down($schema): void
        {
            $this->addSql('ALTER TABLE gf_child DROP COLUMN private_note');
        }
        """
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "Version1.php"
            path.write_text(migration, encoding="utf-8")
            inventory = MODULE.build_inventory(Path(folder))

        table = inventory["tables"][0]
        self.assertFalse(inventory["database_contacted"])
        self.assertEqual("gf_child", table["name"])
        self.assertEqual(
            {"name": "private_note", "type": "varchar(280)", "nullable": True},
            next(row for row in table["columns"] if row["name"] == "private_note"),
        )
        self.assertEqual(3, len(table["indexes"]))
        self.assertEqual("SET NULL", table["foreign_keys"][0]["on_delete"])
        self.assertEqual("gf_child_no_delete", inventory["triggers"][0]["name"])

    def test_replaces_trigger_after_explicit_drop(self) -> None:
        migration = """<?php
        public function up($schema): void
        {
            $this->addSql('CREATE TABLE gf_note (id CHAR(36) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
            $this->addSql("CREATE TRIGGER gf_note_guard BEFORE UPDATE ON gf_note FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000'; END");
            $this->addSql('DROP TRIGGER IF EXISTS gf_note_guard');
            $this->addSql("CREATE TRIGGER gf_note_guard AFTER UPDATE ON gf_note FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000'; END");
        }
        public function down($schema): void {}
        """
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "Version1.php"
            path.write_text(migration, encoding="utf-8")
            inventory = MODULE.build_inventory(Path(folder))

        self.assertEqual(1, len(inventory["triggers"]))
        self.assertEqual("gf_note_guard", inventory["triggers"][0]["name"])
        self.assertEqual("AFTER", inventory["triggers"][0]["timing"])

    def test_rejects_unknown_non_optional_trigger_drop(self) -> None:
        migration = """<?php
        public function up($schema): void
        {
            $this->addSql('DROP TRIGGER gf_missing_guard');
        }
        public function down($schema): void {}
        """
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "Version1.php"
            path.write_text(migration, encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "unknown trigger"):
                MODULE.build_inventory(Path(folder))

    def test_down_sql_does_not_mutate_final_inventory(self) -> None:
        migration = """<?php
        public function up($schema): void
        {
            $this->addSql('CREATE TABLE gf_note (id CHAR(36) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
        }
        public function down($schema): void
        {
            $this->addSql('DROP TABLE gf_note');
        }
        """
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "Version1.php"
            path.write_text(migration, encoding="utf-8")
            inventory = MODULE.build_inventory(Path(folder))
        self.assertEqual(["gf_note"], [row["name"] for row in inventory["tables"]])

    def test_rejects_non_additive_up_alter(self) -> None:
        migration = """<?php
        public function up($schema): void
        {
            $this->addSql('CREATE TABLE gf_note (id CHAR(36) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
            $this->addSql('ALTER TABLE gf_note DROP COLUMN id');
        }
        public function down($schema): void {}
        """
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "Version1.php"
            path.write_text(migration, encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "non-additive ALTER"):
                MODULE.build_inventory(Path(folder))

    def test_exact_ledger_check_expansion_is_the_only_supported_constraint_replacement(self) -> None:
        migration = "symfony/migrations/Version20261019193000.php"
        original = (
            "ALTER TABLE gf_external_publication_attempts "
            "DROP CONSTRAINT ck_gf_external_attempt_provider, "
            "ADD CONSTRAINT ck_gf_external_attempt_provider "
            "CHECK (provider IN ('facebook_page', 'instagram_reels'))"
        )
        table = MODULE.new_table("gf_external_publication_attempts", "VersionOriginal.php")
        tables = {"gf_external_publication_attempts": table}
        MODULE.apply_alter_table(original, tables, migration)
        self.assertEqual([], table["columns"])
        self.assertEqual([], table["indexes"])
        self.assertEqual([], table["foreign_keys"])

        invalid = [
            (original, "Version20261019193000.php"),
            (original.replace("'instagram_reels'", "'unknown_provider'"), migration),
            (original.replace("ck_gf_external_attempt_provider", "ck_unrelated_provider"), migration),
            (original.replace(", ADD CONSTRAINT", ", DROP COLUMN id, ADD CONSTRAINT"), migration),
            (original.replace("'facebook_page', 'instagram_reels'", "'instagram_reels'"), migration),
        ]
        for sql, source in invalid:
            with self.subTest(sql=sql, migration=source):
                with self.assertRaisesRegex(ValueError, "non-additive ALTER"):
                    MODULE.apply_alter_table(sql, tables, source)

    def test_rejects_unprefixed_symfony_table(self) -> None:
        with self.assertRaisesRegex(ValueError, "gf_ prefix"):
            MODULE.parse_table("users", "id CHAR(36) NOT NULL", "Version1.php")


if __name__ == "__main__":
    unittest.main()
