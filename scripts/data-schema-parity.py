#!/usr/bin/env python3
"""Compare source schema inventory with a metadata-only database snapshot.

GF-ARCH-002 intentionally separates capture from comparison. This tool never
opens DATABASE_URL, never connects to MariaDB, and never accepts filesystem
paths from CLI arguments. The CLI reads one JSON envelope from stdin containing
both source inventory and snapshot metadata.
"""
from __future__ import annotations

import argparse
import json
import sys
from typing import Any

SOURCE_CONTRACT = "gf-arch-002-source-inventory-v1"
SNAPSHOT_CONTRACT = "gf-arch-002-db-snapshot-v1"
REPORT_CONTRACT = "gf-arch-002-parity-report-v1"
SOURCE_CHECKS = ("table_name_collisions", "symfony_tables_without_gf_prefix")


def load_envelope(raw: str) -> tuple[dict[str, Any], dict[str, Any]]:
    """Decode the stdin envelope and return source plus snapshot objects."""
    payload = json.loads(raw)
    if not isinstance(payload, dict):
        raise ValueError("JSON root must be an object")
    source = payload.get("source")
    snapshot = payload.get("snapshot")
    if not isinstance(source, dict):
        raise ValueError("envelope field source must be an object")
    if not isinstance(snapshot, dict):
        raise ValueError("envelope field snapshot must be an object")
    return source, snapshot


def validate_source_header(source: dict[str, Any]) -> None:
    """Validate source provenance and its precomputed guard checks."""
    if source.get("contract") != SOURCE_CONTRACT:
        raise ValueError(f"source contract must be {SOURCE_CONTRACT}")
    if source.get("source_only") is not True:
        raise ValueError("source inventory must explicitly state source_only=true")
    if source.get("database_contacted") is not False:
        raise ValueError("source inventory must explicitly state database_contacted=false")

    checks = source.get("checks")
    if not isinstance(checks, dict):
        raise ValueError("source inventory field checks must be an object")
    for check in SOURCE_CHECKS:
        values = checks.get(check)
        if not isinstance(values, list):
            raise ValueError(f"source inventory check {check} must be a list")
        if values:
            raise ValueError(f"source inventory is not clean: {check}")


def extract_source_runtime(source: dict[str, Any], runtime: str) -> set[str]:
    """Extract table names for one source runtime."""
    rows = source.get(runtime)
    if not isinstance(rows, list):
        raise ValueError(f"source inventory field {runtime} must be a list")

    names: set[str] = set()
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError(f"source inventory contains invalid {runtime} table row")
        table = row.get("table")
        if not isinstance(table, str) or not table:
            raise ValueError(f"source inventory contains invalid {runtime} table row")
        names.add(table)
    return names


def source_table_names(source: dict[str, Any]) -> set[str]:
    """Return all declared Laravel and Symfony table names from source inventory."""
    validate_source_header(source)
    return extract_source_runtime(source, "laravel") | extract_source_runtime(source, "symfony")


def validate_snapshot_header(snapshot: dict[str, Any]) -> list[Any]:
    """Validate snapshot provenance and return its table rows."""
    if snapshot.get("contract") != SNAPSHOT_CONTRACT:
        raise ValueError(f"snapshot contract must be {SNAPSHOT_CONTRACT}")
    if snapshot.get("metadata_only") is not True:
        raise ValueError("snapshot must explicitly state metadata_only=true")
    if snapshot.get("contains_row_data") is not False:
        raise ValueError("snapshot must explicitly state contains_row_data=false")
    rows = snapshot.get("tables")
    if not isinstance(rows, list):
        raise ValueError("snapshot field tables must be a list")
    return rows


def snapshot_table_names(snapshot: dict[str, Any]) -> tuple[set[str], list[str]]:
    """Return unique snapshot table names and duplicate names."""
    rows = validate_snapshot_header(snapshot)
    seen: set[str] = set()
    duplicates: set[str] = set()
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError("snapshot contains invalid table row")
        name = row.get("name")
        if not isinstance(name, str) or not name:
            raise ValueError("snapshot contains invalid table row")
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
    extra_nonblocking = sorted(name for name in extra if name not in unknown_gf)

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
            "extra_nonblocking_tables": len(extra_nonblocking),
        },
        "checks": failures,
        "informational": {
            "extra_nonblocking_tables": extra_nonblocking,
        },
    }


def render_report(report: dict[str, Any], as_json: bool) -> None:
    """Print either the stable JSON report or a compact human summary."""
    if as_json:
        print(json.dumps(report, indent=2, sort_keys=True))
        return
    print(f"Expected tables: {report['counts']['expected_tables']}")
    print(f"Snapshot tables: {report['counts']['snapshot_tables']}")
    print(f"Compatible: {'yes' if report['compatible'] else 'no'}")
    print("Database contacted: no")


def main() -> int:
    """Run the stdin comparator and return a fail-closed process status."""
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true", help="print full stable report")
    args = parser.parse_args()

    try:
        source, snapshot = load_envelope(sys.stdin.read())
        report = build_report(source, snapshot)
    except (OSError, ValueError) as error:
        print(f"ERROR: {error}")
        return 2

    render_report(report, args.json)
    if not report["compatible"]:
        for check, values in report["checks"].items():
            if values:
                print(f"ERROR: {check}: {', '.join(values)}")
        return 1

    print("GF-ARCH-002 table parity snapshot: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
