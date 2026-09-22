#!/usr/bin/env python3
"""Compare a source schema inventory with a metadata-only database snapshot.

GF-ARCH-002 intentionally separates capture from comparison. This tool never
opens DATABASE_URL and never connects to MariaDB. It consumes JSON files so a
read-only snapshot can be captured by an authorized operator and reviewed
without giving the comparator database credentials.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

SOURCE_CONTRACT = "gf-arch-002-source-inventory-v1"
SNAPSHOT_CONTRACT = "gf-arch-002-db-snapshot-v1"
REPORT_CONTRACT = "gf-arch-002-parity-report-v1"


def load_json(path: Path) -> dict[str, Any]:
    """Load one JSON object and reject non-object roots."""
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError(f"{path}: JSON root must be an object")
    return payload


def source_table_names(source: dict[str, Any]) -> set[str]:
    """Return all declared Laravel and Symfony table names from source inventory."""
    if source.get("contract") != SOURCE_CONTRACT:
        raise ValueError(f"source contract must be {SOURCE_CONTRACT}")
    if source.get("source_only") is not True:
        raise ValueError("source inventory must explicitly state source_only=true")
    if source.get("database_contacted") is not False:
        raise ValueError("source inventory must explicitly state database_contacted=false")

    checks = source.get("checks")
    if not isinstance(checks, dict):
        raise ValueError("source inventory field checks must be an object")
    for check in ("table_name_collisions", "symfony_tables_without_gf_prefix"):
        values = checks.get(check)
        if not isinstance(values, list):
            raise ValueError(f"source inventory check {check} must be a list")
        if values:
            raise ValueError(f"source inventory is not clean: {check}")

    names: set[str] = set()
    for runtime in ("laravel", "symfony"):
        rows = source.get(runtime)
        if not isinstance(rows, list):
            raise ValueError(f"source inventory field {runtime} must be a list")
        for row in rows:
            if not isinstance(row, dict) or not isinstance(row.get("table"), str):
                raise ValueError(f"source inventory contains invalid {runtime} table row")
            names.add(row["table"])
    return names


def snapshot_table_names(snapshot: dict[str, Any]) -> tuple[set[str], list[str]]:
    """Return unique snapshot table names and duplicate names."""
    if snapshot.get("contract") != SNAPSHOT_CONTRACT:
        raise ValueError(f"snapshot contract must be {SNAPSHOT_CONTRACT}")
    if snapshot.get("metadata_only") is not True:
        raise ValueError("snapshot must explicitly state metadata_only=true")
    if snapshot.get("contains_row_data") is not False:
        raise ValueError("snapshot must explicitly state contains_row_data=false")

    rows = snapshot.get("tables")
    if not isinstance(rows, list):
        raise ValueError("snapshot field tables must be a list")

    seen: set[str] = set()
    duplicates: set[str] = set()
    for row in rows:
        if not isinstance(row, dict) or not isinstance(row.get("name"), str) or not row["name"]:
            raise ValueError("snapshot contains invalid table row")
        name = row["name"]
        if name in seen:
            duplicates.add(name)
        seen.add(name)
    return seen, sorted(duplicates)


def build_report(source: dict[str, Any], snapshot: dict[str, Any]) -> dict[str, Any]:
    """Build deterministic table-level parity checks without touching a database."""
    expected = source_table_names(source)
    actual, duplicates = snapshot_table_names(snapshot)
    missing = sorted(expected - actual)
    extra = sorted(actual - expected)
    unknown_gf = sorted(name for name in extra if name.startswith("gf_"))

    failures = {
        "missing_source_tables": missing,
        "duplicate_snapshot_tables": duplicates,
        "unknown_gf_tables": unknown_gf,
    }
    return {
        "contract": REPORT_CONTRACT,
        "comparison_level": "tables",
        "database_contacted": False,
        "compatible": not any(failures.values()),
        "counts": {
            "expected_tables": len(expected),
            "snapshot_tables": len(actual),
            "extra_nonblocking_tables": len([name for name in extra if name not in unknown_gf]),
        },
        "checks": failures,
        "informational": {
            "extra_nonblocking_tables": sorted(name for name in extra if name not in unknown_gf),
        },
    }


def main() -> int:
    """Run the JSON comparator and return a fail-closed process status."""
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True, type=Path, help="JSON emitted by data-schema-inventory.py --json")
    parser.add_argument("--snapshot", required=True, type=Path, help="metadata-only database snapshot JSON")
    parser.add_argument("--json", action="store_true", help="print full stable report")
    args = parser.parse_args()

    try:
        report = build_report(load_json(args.source), load_json(args.snapshot))
    except (OSError, json.JSONDecodeError, ValueError) as error:
        print(f"ERROR: {error}")
        return 2

    if args.json:
        print(json.dumps(report, indent=2, sort_keys=True))
    else:
        print(f"Expected tables: {report['counts']['expected_tables']}")
        print(f"Snapshot tables: {report['counts']['snapshot_tables']}")
        print(f"Compatible: {'yes' if report['compatible'] else 'no'}")
        print("Database contacted: no")

    if not report["compatible"]:
        for check, values in report["checks"].items():
            if values:
                print(f"ERROR: {check}: {', '.join(values)}")
        return 1

    print("GF-ARCH-002 table parity snapshot: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
