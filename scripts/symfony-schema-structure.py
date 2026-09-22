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
REGEX_FLAGS = re.I | re.S | re.A
IDENTIFIER = r"\w+"

CREATE_TABLE = re.compile(
    rf"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?({IDENTIFIER})`?\s*\((.*?)\)\s*ENGINE\s*=",
    REGEX_FLAGS,
)
CREATE_TRIGGER = re.compile(
    rf"CREATE\s+TRIGGER\s+`?({IDENTIFIER})`?\s+"
    rf"(BEFORE|AFTER)\s+(INSERT|UPDATE|DELETE)\s+ON\s+`?({IDENTIFIER})`?",
    REGEX_FLAGS,
)
PRIMARY_KEY = re.compile(r"^PRIMARY\s+KEY\s*\(([^)]+)\)$", REGEX_FLAGS)
UNIQUE_INDEX = re.compile(
    rf"^UNIQUE\s+(?:KEY|INDEX)\s+`?({IDENTIFIER})`?\s*\(([^)]+)\)$",
    REGEX_FLAGS,
)
PLAIN_INDEX = re.compile(
    rf"^(?:KEY|INDEX)\s+`?({IDENTIFIER})`?\s*\(([^)]+)\)$",
    REGEX_FLAGS,
)
FOREIGN_KEY = re.compile(
    rf"^CONSTRAINT\s+`?({IDENTIFIER})`?\s+FOREIGN\s+KEY\s*\(([^)]+)\)\s+"
    rf"REFERENCES\s+`?({IDENTIFIER})`?\s*\(([^)]+)\)"
    r"(?:\s+ON\s+DELETE\s+(CASCADE|RESTRICT|SET\s+NULL|NO\s+ACTION))?",
    REGEX_FLAGS,
)


class ScanState:
    """Mutable state for safe top-level SQL comma scanning."""

    __slots__ = ("depth", "quote", "escaped")

    def __init__(self) -> None:
        self.depth = 0
        self.quote: str | None = None
        self.escaped = False


def normalize_space(value: str) -> str:
    """Collapse whitespace while preserving semantic token order."""
    return " ".join(value.strip().split())


def normalize_type(value: str) -> str:
    """Normalize MariaDB-like column type spelling for stable comparison."""
    compact = normalize_space(value).lower()
    return ",".join(part.strip() for part in compact.split(","))


def sql_identifier(value: str) -> str:
    """Extract and validate one simple ASCII SQL identifier."""
    token = value.strip()
    if token.startswith("`"):
        closing = token.find("`", 1)
        if closing < 0:
            raise ValueError(f"unterminated quoted identifier: {value}")
        token = token[1:closing]
    else:
        token = token.split(maxsplit=1)[0].split("(", 1)[0]

    if re.fullmatch(IDENTIFIER, token, flags=re.A) is None:
        raise ValueError(f"unsupported SQL identifier: {value}")
    return token


def identifier_list(raw: str) -> list[str]:
    """Extract ordered identifiers from an index or key column list."""
    return [sql_identifier(part) for part in split_top_level(raw)]


def scan_separator(state: ScanState, char: str) -> bool:
    """Update scanner state and report a comma at top level."""
    if state.quote is not None:
        if state.escaped:
            state.escaped = False
        elif char == "\\":
            state.escaped = True
        elif char == state.quote:
            state.quote = None
        return False

    if char in ("'", '"'):
        state.quote = char
    elif char == "(":
        state.depth += 1
    elif char == ")":
        state.depth -= 1
        if state.depth < 0:
            raise ValueError("unbalanced SQL parentheses")
    elif char == "," and state.depth == 0:
        return True
    return False


def append_fragment(items: list[str], current: list[str]) -> None:
    """Append one non-empty SQL fragment and clear its buffer."""
    item = "".join(current).strip()
    if item:
        items.append(item)
    current.clear()


def split_top_level(raw: str) -> list[str]:
    """Split comma-separated SQL fragments while respecting quotes/parentheses."""
    items: list[str] = []
    current: list[str] = []
    state = ScanState()

    for char in raw:
        if scan_separator(state, char):
            append_fragment(items, current)
        else:
            current.append(char)

    if state.quote is not None or state.depth != 0:
        raise ValueError("unterminated SQL fragment")
    append_fragment(items, current)
    return items


def parse_type_prefix(raw: str) -> tuple[str, str]:
    """Separate a simple MariaDB column type from the remaining modifiers."""
    text = raw.strip()
    boundary = 0
    while boundary < len(text) and (text[boundary].isalnum() or text[boundary] == "_"):
        boundary += 1
    if boundary == 0:
        raise ValueError(f"missing column type: {raw}")

    type_text = text[:boundary]
    remainder = text[boundary:].lstrip()
    if remainder.startswith("("):
        closing = remainder.find(")")
        if closing < 0:
            raise ValueError(f"unterminated column type: {raw}")
        type_text += remainder[: closing + 1]
        remainder = remainder[closing + 1 :].lstrip()

    if remainder.upper().startswith("UNSIGNED"):
        type_text += " UNSIGNED"
        remainder = remainder[len("UNSIGNED") :].lstrip()
    return normalize_type(type_text), remainder


def parse_column(item: str) -> dict[str, Any]:
    """Parse one column declaration without a backtracking-heavy regex."""
    first, separator, remainder = item.partition(" ")
    if not separator:
        raise ValueError(f"unsupported column declaration: {item}")
    name = sql_identifier(first)
    column_type, modifiers = parse_type_prefix(remainder)
    return {
        "name": name,
        "type": column_type,
        "nullable": "NOT NULL" not in modifiers.upper(),
    }


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
    table["columns"].append(parse_column(normalized))


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
        table[field] = sorted(
            table[field],
            key=lambda row: (row["name"], json.dumps(row, sort_keys=True)),
        )
    return table


def migration_name(path: Path) -> str:
    """Return a repo-relative path when possible, otherwise a fixture filename."""
    return path.relative_to(ROOT).as_posix() if path.is_relative_to(ROOT) else path.name


def register_tables(
    text: str,
    migration: str,
    tables: dict[str, dict[str, Any]],
    duplicates: set[str],
) -> None:
    """Register CREATE TABLE statements from one migration."""
    for name, body in CREATE_TABLE.findall(text):
        if name in tables:
            duplicates.add(name)
        tables[name] = parse_table(name, body, migration)


def register_triggers(
    text: str,
    migration: str,
    triggers: dict[str, dict[str, str]],
    duplicates: set[str],
) -> None:
    """Register CREATE TRIGGER statements from one migration."""
    for trigger, timing, event, table_name in CREATE_TRIGGER.findall(text):
        if not table_name.startswith("gf_"):
            raise ValueError(f"Symfony trigger must target gf_ table: {trigger}")
        if trigger in triggers:
            duplicates.add(trigger)
        triggers[trigger] = {
            "name": trigger,
            "table": table_name,
            "timing": timing.upper(),
            "event": event.upper(),
            "migration": migration,
        }


def build_inventory(directory: Path = MIGRATIONS_DIR) -> dict[str, Any]:
    """Parse every Doctrine migration and return a source-only structure contract."""
    tables: dict[str, dict[str, Any]] = {}
    triggers: dict[str, dict[str, str]] = {}
    duplicate_tables: set[str] = set()
    duplicate_triggers: set[str] = set()

    for path in sorted(directory.glob("*.php")):
        text = path.read_text(encoding="utf-8")
        migration = migration_name(path)
        register_tables(text, migration, tables, duplicate_tables)
        register_triggers(text, migration, triggers, duplicate_triggers)

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


def render_summary(inventory: dict[str, Any], as_json: bool) -> None:
    """Render JSON or a compact human-readable inventory summary."""
    if as_json:
        print(json.dumps(inventory, indent=2, sort_keys=True))
        return
    print(f"Symfony tables: {len(inventory['tables'])}")
    print(f"Symfony triggers: {len(inventory['triggers'])}")
    print("Database contacted: no")


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
    render_summary(inventory, args.json)

    if failures:
        for failure in failures:
            print(f"ERROR: {failure}: {', '.join(checks[failure])}")
        return 1

    print("GF-ARCH-002 Symfony structure source guard: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
