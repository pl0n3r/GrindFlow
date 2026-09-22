#!/usr/bin/env python3
"""Inventory Laravel/Symfony schema writers without connecting to a database.

This is a GF-ARCH-002 guardrail. It parses migration source only, emits a stable
JSON inventory, and fails if the isolated Symfony schema collides by table name
with Laravel. It never opens DATABASE_URL and cannot mutate a database.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LARAVEL_DIR = ROOT / "database" / "migrations"
SYMFONY_DIR = ROOT / "symfony" / "migrations"

LARAVEL_CREATE = re.compile(r"Schema::create\(\s*['\"]([^'\"]+)['\"]")
LARAVEL_RENAME = re.compile(r"Schema::rename\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]")
SYMFONY_CREATE = re.compile(r"\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?", re.I)


def scan(directory: Path, create_pattern: re.Pattern[str], runtime: str) -> list[dict[str, str]]:
    inventory: dict[str, dict[str, str]] = {}
    for path in sorted(directory.glob("*.php")):
        text = path.read_text(encoding="utf-8")
        migration = path.relative_to(ROOT).as_posix()
        for table in sorted(set(create_pattern.findall(text))):
            inventory[table] = {
                "runtime": runtime,
                "table": table,
                "migration": migration,
                "writer": runtime,
            }
        if runtime == "laravel":
            for old, new in LARAVEL_RENAME.findall(text):
                previous = inventory.pop(old, None)
                inventory[new] = {
                    "runtime": runtime,
                    "table": new,
                    "migration": migration if previous is None else previous["migration"],
                    "writer": runtime if previous is None else previous["writer"],
                }
    return [inventory[name] for name in sorted(inventory)]


def build_inventory() -> dict[str, object]:
    laravel = scan(LARAVEL_DIR, LARAVEL_CREATE, "laravel")
    symfony = scan(SYMFONY_DIR, SYMFONY_CREATE, "symfony")
    laravel_names = {row["table"] for row in laravel}
    symfony_names = {row["table"] for row in symfony}
    collisions = sorted(laravel_names & symfony_names)
    unprefixed_symfony = sorted(name for name in symfony_names if not name.startswith("gf_"))
    return {
        "contract": "gf-arch-002-source-inventory-v1",
        "source_only": True,
        "database_contacted": False,
        "laravel": laravel,
        "symfony": symfony,
        "checks": {
            "table_name_collisions": collisions,
            "symfony_tables_without_gf_prefix": unprefixed_symfony,
        },
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true", help="print the stable JSON inventory")
    args = parser.parse_args()
    inventory = build_inventory()
    checks = inventory["checks"]
    assert isinstance(checks, dict)
    failures: list[str] = []
    if checks["table_name_collisions"]:
        failures.append(f"table-name collision: {', '.join(checks['table_name_collisions'])}")
    if checks["symfony_tables_without_gf_prefix"]:
        failures.append(f"Symfony table without gf_ prefix: {', '.join(checks['symfony_tables_without_gf_prefix'])}")

    if args.json:
        print(json.dumps(inventory, indent=2, sort_keys=True))
    else:
        print(f"Laravel tables: {len(inventory['laravel'])}")
        print(f"Symfony tables: {len(inventory['symfony'])}")
        print("Database contacted: no")

    if failures:
        for failure in failures:
            print(f"ERROR: {failure}")
        return 1
    print("GF-ARCH-002 source schema guard: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
