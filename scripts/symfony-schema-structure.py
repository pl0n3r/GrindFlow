#!/usr/bin/env python3
"""Inventory final Symfony gf_* structure from Doctrine migration SQL only.

GF-ARCH-002 guardrail: this parser reads migration source and never connects to
MariaDB. It evaluates supported DDL from each migration's up() method in order,
so later ALTER TABLE operations are reflected in the final structural contract.
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
    rf"^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?({IDENTIFIER})`?\s*\((.*)\)\s*ENGINE\s*=",
    REGEX_FLAGS,
)
CREATE_TRIGGER = re.compile(
    rf"^CREATE\s+TRIGGER\s+`?({IDENTIFIER})`?\s+"
    rf"(BEFORE|AFTER)\s+(INSERT|UPDATE|DELETE)\s+ON\s+`?({IDENTIFIER})`?",
    REGEX_FLAGS,
)
ALTER_TABLE = re.compile(
    rf"^ALTER\s+TABLE\s+`?({IDENTIFIER})`?\s+(.+)$",
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
    r"(?:\s+ON\s+DELETE\s+(CASCADE|RESTRICT|SET\s+NULL|NO\s+ACTION))?$",
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
    """Separate a simple MariaDB column type from remaining modifiers."""
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


def append_named(table: dict[str, Any], field: str, row: dict[str, Any]) -> None:
    """Append a named structural row while rejecting duplicate final-state names."""
    if any(existing["name"] == row["name"] for existing in table[field]):
        raise ValueError(f"duplicate {field} definition on {table['name']}: {row['name']}")
    table[field].append(row)


def parse_table_item(item: str, table: dict[str, Any]) -> None:
    """Classify one CREATE/ALTER table item into structural metadata."""
    normalized = normalize_space(item)

    match = PRIMARY_KEY.match(normalized)
    if match:
        append_named(
            table,
            "indexes",
            {"name": "PRIMARY", "unique": True, "columns": identifier_list(match.group(1))},
        )
        return

    match = UNIQUE_INDEX.match(normalized)
    if match:
        append_named(
            table,
            "indexes",
            {"name": match.group(1), "unique": True, "columns": identifier_list(match.group(2))},
        )
        return

    match = PLAIN_INDEX.match(normalized)
    if match:
        append_named(
            table,
            "indexes",
            {"name": match.group(1), "unique": False, "columns": identifier_list(match.group(2))},
        )
        return

    match = FOREIGN_KEY.match(normalized)
    if match:
        append_named(
            table,
            "foreign_keys",
            {
                "name": match.group(1),
                "columns": identifier_list(match.group(2)),
                "referenced_table": match.group(3),
                "referenced_columns": identifier_list(match.group(4)),
                "on_delete": normalize_space(match.group(5) or "RESTRICT").upper(),
            },
        )
        return

    if normalized.upper().startswith(("CONSTRAINT ", "CHECK ")):
        return
    append_named(table, "columns", parse_column(normalized))


def new_table(name: str, migration: str) -> dict[str, Any]:
    """Create an empty normalized table record."""
    if not name.startswith("gf_"):
        raise ValueError(f"Symfony structure table must use gf_ prefix: {name}")
    return {
        "name": name,
        "migration": migration,
        "columns": [],
        "indexes": [],
        "foreign_keys": [],
    }


def parse_table(name: str, body: str, migration: str) -> dict[str, Any]:
    """Parse one CREATE TABLE body into deterministic metadata."""
    table = new_table(name, migration)
    for item in split_top_level(body):
        parse_table_item(item, table)
    return table


def up_section(text: str) -> str:
    """Return only the migration up() source, excluding rollback SQL."""
    _, found, remainder = text.partition("public function up")
    if not found:
        raise ValueError("migration is missing public function up")
    body, found_down, _ = remainder.partition("public function down")
    if not found_down:
        raise ValueError("migration is missing public function down")
    return body


def quoted_add_sql(line: str, quote: str) -> str | None:
    """Extract a one-line addSql quoted string when present."""
    prefix = f"$this->addSql({quote}"
    start = line.find(prefix)
    if start < 0:
        return None
    start += len(prefix)
    end = line.rfind(f"{quote});")
    if end < start:
        raise ValueError("unterminated one-line addSql")
    return line[start:end].replace(f"\\{quote}", quote).strip()


def is_heredoc_start(line: str) -> bool:
    """Return whether a line opens a SQL heredoc addSql call."""
    return "addSql(<<<'SQL'" in line or 'addSql(<<<"SQL"' in line


def consume_heredoc_line(
    buffer: list[str],
    line: str,
    statements: list[str],
) -> list[str] | None:
    """Consume one heredoc line and return the next buffer state."""
    if line.strip() != "SQL);":
        buffer.append(line)
        return buffer

    sql = "\n".join(buffer).strip()
    if sql:
        statements.append(sql)
    return None


def quoted_sql_from_line(line: str) -> str | None:
    """Return the first supported one-line quoted addSql body."""
    for quote in ("'", '"'):
        sql = quoted_add_sql(line, quote)
        if sql is not None:
            return sql
    return None


def extract_up_sql(text: str) -> list[str]:
    """Extract addSql statements from up() in declaration order."""
    statements: list[str] = []
    heredoc: list[str] | None = None

    for line in up_section(text).splitlines():
        if heredoc is not None:
            heredoc = consume_heredoc_line(heredoc, line, statements)
            continue
        if is_heredoc_start(line):
            heredoc = []
            continue

        sql = quoted_sql_from_line(line)
        if sql is not None:
            statements.append(sql)

    if heredoc is not None:
        raise ValueError("unterminated addSql heredoc")
    return statements


def apply_create_table(
    statement: str,
    migration: str,
    tables: dict[str, dict[str, Any]],
    duplicates: set[str],
) -> None:
    """Apply one CREATE TABLE statement to the in-memory final schema."""
    match = CREATE_TABLE.match(statement)
    if match is None:
        raise ValueError(f"unsupported CREATE TABLE statement in {migration}")
    name, body = match.groups()
    if name in tables:
        duplicates.add(name)
        return
    tables[name] = parse_table(name, body, migration)


def apply_alter_table(statement: str, tables: dict[str, dict[str, Any]]) -> None:
    """Apply supported additive ALTER TABLE operations to an existing table."""
    match = ALTER_TABLE.match(normalize_space(statement))
    if match is None:
        raise ValueError("unsupported ALTER TABLE statement")

    name, operations = match.groups()
    if not name.startswith("gf_"):
        raise ValueError(f"Symfony ALTER TABLE must target gf_ table: {name}")
    table = tables.get(name)
    if table is None:
        raise ValueError(f"ALTER TABLE targets unknown table: {name}")

    for operation in split_top_level(operations):
        normalized = normalize_space(operation)
        if not normalized.upper().startswith("ADD "):
            raise ValueError(f"unsupported non-additive ALTER operation: {normalized}")
        addition = normalized[4:].strip()
        if addition.upper().startswith("COLUMN "):
            addition = addition[7:].strip()
        parse_table_item(addition, table)


def apply_trigger(
    statement: str,
    migration: str,
    triggers: dict[str, dict[str, str]],
    duplicates: set[str],
) -> None:
    """Apply one CREATE TRIGGER statement to the final source inventory."""
    match = CREATE_TRIGGER.match(normalize_space(statement))
    if match is None:
        raise ValueError(f"unsupported CREATE TRIGGER statement in {migration}")

    trigger, timing, event, table_name = match.groups()
    if not table_name.startswith("gf_"):
        raise ValueError(f"Symfony trigger must target gf_ table: {trigger}")
    if trigger in triggers:
        duplicates.add(trigger)
        return
    triggers[trigger] = {
        "name": trigger,
        "table": table_name,
        "timing": timing.upper(),
        "event": event.upper(),
        "migration": migration,
    }


def apply_statement(
    statement: str,
    migration: str,
    tables: dict[str, dict[str, Any]],
    triggers: dict[str, dict[str, str]],
    duplicate_tables: set[str],
    duplicate_triggers: set[str],
) -> None:
    """Apply one supported up() DDL statement or fail closed."""
    normalized = normalize_space(statement)
    upper = normalized.upper()
    if upper.startswith("CREATE TABLE "):
        apply_create_table(statement, migration, tables, duplicate_tables)
        return
    if upper.startswith("ALTER TABLE "):
        apply_alter_table(statement, tables)
        return
    if upper.startswith("CREATE TRIGGER "):
        apply_trigger(statement, migration, triggers, duplicate_triggers)
        return
    raise ValueError(f"unsupported migration SQL in {migration}: {normalized[:80]}")


def migration_name(path: Path) -> str:
    """Return a repo-relative path when possible, otherwise a fixture filename."""
    return path.relative_to(ROOT).as_posix() if path.is_relative_to(ROOT) else path.name


def sort_table(table: dict[str, Any]) -> None:
    """Sort normalized table collections for deterministic JSON output."""
    for field in ("columns", "indexes", "foreign_keys"):
        table[field] = sorted(
            table[field],
            key=lambda row: (row["name"], json.dumps(row, sort_keys=True)),
        )


def build_inventory(directory: Path = MIGRATIONS_DIR) -> dict[str, Any]:
    """Apply every migration up() and return the final source-only structure."""
    tables: dict[str, dict[str, Any]] = {}
    triggers: dict[str, dict[str, str]] = {}
    duplicate_tables: set[str] = set()
    duplicate_triggers: set[str] = set()

    for path in sorted(directory.glob("*.php")):
        migration = migration_name(path)
        text = path.read_text(encoding="utf-8")
        for statement in extract_up_sql(text):
            apply_statement(
                statement,
                migration,
                tables,
                triggers,
                duplicate_tables,
                duplicate_triggers,
            )

    for table in tables.values():
        sort_table(table)

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
    """Emit source structure and fail closed if unsupported/duplicate DDL exists."""
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
