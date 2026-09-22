#!/usr/bin/env python3
"""Build the deterministic reverse-order Doctrine migration plan for Symfony CI.

The plan is source-only. It discovers GrindFlow migration files in the repository
and never connects to a database or executes a migration.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS_DIR = ROOT / "symfony" / "migrations"
CONTRACT = "gf-arch-002-symfony-reversal-plan-v1"
FILENAME = re.compile(r"^Version(\d{14})\.php$", re.ASCII)


def discover_migrations(directory: Path = MIGRATIONS_DIR) -> list[dict[str, str]]:
    """Return every valid migration sorted newest-first for safe rollback."""
    migrations: list[dict[str, str]] = []
    versions: set[str] = set()

    for path in directory.glob("Version*.php"):
        match = FILENAME.fullmatch(path.name)
        if match is None:
            raise ValueError(f"unsupported migration filename: {path.name}")

        version = match.group(1)
        if version in versions:
            raise ValueError(f"duplicate migration version: {version}")
        versions.add(version)

        migrations.append(
            {
                "version": version,
                "file": path.name,
                "class": f"GrindFlow\\Migrations\\Version{version}",
            }
        )

    if not migrations:
        raise ValueError("no Symfony migrations discovered")

    return sorted(migrations, key=lambda row: row["version"], reverse=True)


def build_plan(directory: Path = MIGRATIONS_DIR) -> dict[str, Any]:
    """Build the stable source contract used by CI and tests."""
    migrations = discover_migrations(directory)
    return {
        "contract": CONTRACT,
        "source_only": True,
        "database_contacted": False,
        "direction": "down",
        "count": len(migrations),
        "migrations": migrations,
    }


def render(plan: dict[str, Any], *, as_json: bool, classes_only: bool) -> None:
    """Render the plan for humans, machines, or the workflow execution loop."""
    migrations = plan["migrations"]
    assert isinstance(migrations, list)

    if classes_only:
        for migration in migrations:
            print(migration["class"])
        return

    if as_json:
        print(json.dumps(plan, indent=2, sort_keys=True))
        return

    print(f"Symfony reverse migrations: {plan['count']}")
    for migration in migrations:
        print(f"- {migration['class']}")


def main() -> int:
    parser = argparse.ArgumentParser()
    output = parser.add_mutually_exclusive_group()
    output.add_argument("--json", action="store_true", help="emit the full stable JSON plan")
    output.add_argument("--classes", action="store_true", help="emit only migration classes")
    args = parser.parse_args()

    try:
        plan = build_plan()
    except (OSError, ValueError) as error:
        print(f"ERROR: {error}")
        return 2

    render(plan, as_json=args.json, classes_only=args.classes)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
