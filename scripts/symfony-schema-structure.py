#!/usr/bin/env python3
"""Inventory Symfony gf_* table structure from Doctrine migration SQL only.

GF-ARCH-002 guardrail: this parser reads migration source and never connects to
MariaDB. It emits deterministic metadata for columns, indexes, foreign keys and
triggers so an authorized metadata-only database snapshot can be compared later.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS_DIR = ROOT / "symfony" / "migrations"
CONTRACT = "gf-arch-002-symfony-structure-v1"

CREATE_TABLE = re.compile(
    r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE\s*=",
    re.I | re.S,
)
CREATE_TRIGGER = re.compile(
    r"CREATE\s+TRIGGER\s+`?([A-Za-z0-9_]+)`?\s+"
    r"(BEFORE|AFTER)\s+(INSERT|UPDATE|DELETE)\s+ON\s+`?([A-Za-z0-9_]+)`?",
    re.I | re.S,
)
PRIMARY_KEY = re.compile(r"^PRIMARY\s+KEY\s*\((.+)\)$", re.I | re.S)
UNIQUE_INDEX = re.compile(
    r"^UNIQUE\s+(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$",
    re.I | re.S,
)
PLAIN_INDEX = re.compile(
    r"^(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$",
    re.I | re.S,
)
FOREIGN_KEY = re.compile(
    r"^CONSTRAINT\s+`?([A-Za-z0-9_]+)`?\s+FOREIGN\s+KEY\s*\((.+?)\)\s+"
    r"REFERENCES\s+`?([A-Za-z0-9_]+)`?\s*\((.+?)\)"
    r"(?:\s+ON\s+DELETE\s+(CASCADE|RESTRICT|SET\s+NULL|NO\s+ACTION))?",
    re.I | re.S,
)
COLUMN = re.compile(
    r"^`?([A-Za-z0-9_]+)`?\s+([A-Za-z]+(?:\([^)]*\))?(?:\s+UNSIGNED)?)(.*)$",
    re.I | re.S,
)


def normalize_space(value: str) -> str:
    """Collapse whitespace while preserving semantic token order."""
    return " ".join(value.strip().split())


def normalize_type(value: str) -> str:
    """Normalize MariaDB-like column type spelling for stable comparison."""
    compact = normalize_space(value).lower()
    compact = re.sub(r"\s*,\s*", ",", compact)
    return compact


def identifier_list(raw: str) -> list[str]:
    """Extract ordered identifier names from an index or key column list."""
    names: list[str] = []
    for part in split_top_level(raw):
        match = re.match(r"\s*`?([A-Za-z0-9_]+)`?", part)
        if match is None:
            raise ValueError(f"unsupported key column expression: {part.strip()}")
        names.append(match.group(1))
    return names


def split_top_level(raw: str) -> list[str]:
    """Split comma-separated SQL fragments while respecting quotes/parentheses."""
    items: list[str] = []
    current: list[str] = []
    depth = 0
    quote: str | None = None
    escaped = False

    for char in raw:
        if quote is not None:
            current.append(char)
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue

        if char in ("'", '"'):
            quote = char
            current.append(char)
            continue
        if char == "(":
            depth += 1
            current.append(char)
            continue
        if char == ")":
            depth -= 1
            if depth < 0:
                raise ValueError("unbalanced SQL parentheses")
            current.append(char)
            continue
        if char == "," and depth == 0:
            item = "".join(current).strip()
            if item:
                items.append(item)
            current = []
            continue
        current.append(char)

    if quote is not None or depth != 0:
        raise ValueError("unterminated SQL fragment")

    item = "".join(current).strip()
    if item:
        items.append(item)
    return items


def parse_table_item(item: str, table: dict[str, Any]) -> None:
    """Classify one CREATE TABLE item into structural metadata."""
    normalized = normalize_space(item)

    match = PRIMARY_KEY.match(normalized)
    if match:
        table["indexes"].append(
            {"name": "PRIMARY", "unique": True, "columns": identifier_list(match.group(1))}
        )
        return

    match = UNIQUE_INDEX.match(normalized)
    if match:
        table["indexes"].append(
            {"name": match.group(1), "unique": True, "columns": identifier_list(match.group(2))}
        )
        return

    match = PLAIN_INDEX.match(normalized)
    if match:
        table["indexes"].append(
            {"name": match.group(1), "unique": False, "columns": identifier_list(match.group(2))}
        )
        return

    match = FOREIGN_KEY.match(normalized)
    if match:
        table["foreign_keys"].append(
            {
                "name": match.group(1),
                "columns": identifier_list(match.group(2)),
                "referenced_table": match.group(3),
                "referenced_columns": identifier_list(match.group(4)),
                "on_delete": normalize_space(match.group(5) or "RESTRICT").upper(),
            }
        )
        return

    if normalized.upper().startswith(("CONSTRAINT ", "CHECK ")):
        return

    match = COLUMN.match(normalized)
    if match is None:
        raise ValueError(f"unsupported CREATE TABLE item: {normalized}")

    remainder = normalize_space(match.group(3))
    table["columns"].append(
        {
            "name": match.group(1),
            "type": normalize_type(match.group(2)),
            "nullable": "NOT NULL" not in remainder.upper(),
        }
    )


def parse_table(name: str, body: str, migration: str) -> dict[str, Any]:
    """Parse one CREATE TABLE body into deterministic metadata."""
    if not name.startswith("gf_"):
        raise ValueError(f"Symfony structure table must use gf_ prefix: {name}")

    table: dict[str, Any] = {
        "name": name,
        "migration": migration,
        "columns": [],
        "indexes": [],
        "foreign_keys": [],
    }
    for item in split_top_level(body):
        parse_table_item(item, table)

    for field in ("columns", "indexes", "foreign_keys"):
        table[field] = sorted(table[field], key=lambda row: (row["name"], json.dumps(row, sort_keys=True)))
    return table


def build_inventory(directory: Path = MIGRATIONS_DIR) -> dict[str, Any]:
    """Parse every Doctrine migration and return a source-only structure contract."""
    tables: dict[str, dict[str, Any]] = {}
    triggers: dict[str, dict[str, str]] = {}
    duplicate_tables: set[str] = set()
    duplicate_triggers: set[str] = set()

    for path in sorted(directory.glob("*.php")):
        text = path.read_text(encoding="utf-8")
        migration = path.relative_to(ROOT).as_posix() if path.is_relative_to(ROOT) else path.name

        for name, body in CREATE_TABLE.findall(text):
            if name in tables:
                duplicate_tables.add(name)
            tables[name] = parse_table(name, body, migration)

        for trigger, timing, event, table_name in CREATE_TRIGGER.findall(text):
            if not table_name.startswith("gf_"):
                raise ValueError(f"Symfony trigger must target gf_ table: {trigger}")
            if trigger in triggers:
                duplicate_triggers.add(trigger)
            triggers[trigger] = {
                "name": trigger,
                "table": table_name,
                "timing": timing.upper(),
                "event": event.upper(),
                "migration": migration,
            }

    return {
        "contract": CONTRACT,
        "source_only": True,
        "database_contacted": False,
        "tables": [tables[name] for name in sorted(tables)],
        "triggers": [triggers[name] for name in sorted(triggers)],
        "checks": {
            "duplicate_tables": sorted(duplicate_tables),
            "duplicate_triggers": sorted(duplicate_triggers),
        },
    }


def main() -> int:
    """Emit source structure and fail closed if duplicate definitions are found."""
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true", help="print stable JSON structure inventory")
    args = parser.parse_args()

    try:
        inventory = build_inventory()
    except (OSError, ValueError) as error:
        print(f"ERROR: {error}")
        return 2

    checks = inventory["checks"]
    assert isinstance(checks, dict)
    failures = [key for key, values in checks.items() if values]

    if args.json:
        print(json.dumps(inventory, indent=2, sort_keys=True))
    else:
        print(f"Symfony tables: {len(inventory['tables'])}")
        print(f"Symfony triggers: {len(inventory['triggers'])}")
        print("Database contacted: no")

    if failures:
        for failure in failures:
            print(f"ERROR: {failure}: {', '.join(checks[failure])}")
        return 1

    print("GF-ARCH-002 Symfony structure source guard: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
