#!/usr/bin/env python3
"""Compare Symfony source structure with a metadata-only MariaDB snapshot.

The comparator is intentionally offline: one JSON envelope is read from stdin,
no filesystem path is accepted and no database client is imported.
"""
from __future__ import annotations

import argparse
import json
import sys
from typing import Any

SOURCE_CONTRACT = "gf-arch-002-symfony-structure-v1"
SNAPSHOT_CONTRACT = "gf-arch-002-db-structure-snapshot-v1"
REPORT_CONTRACT = "gf-arch-002-structure-parity-report-v1"
SOURCE_CHECKS = ("duplicate_tables", "duplicate_triggers")


def load_envelope(raw: str) -> tuple[dict[str, Any], dict[str, Any]]:
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


def validate_source(source: dict[str, Any]) -> None:
    if source.get("contract") != SOURCE_CONTRACT:
        raise ValueError(f"source contract must be {SOURCE_CONTRACT}")
    if source.get("source_only") is not True:
        raise ValueError("source must explicitly state source_only=true")
    if source.get("database_contacted") is not False:
        raise ValueError("source must explicitly state database_contacted=false")
    checks = source.get("checks")
    if not isinstance(checks, dict):
        raise ValueError("source checks must be an object")
    for name in SOURCE_CHECKS:
        values = checks.get(name)
        if not isinstance(values, list):
            raise ValueError(f"source check {name} must be a list")
        if values:
            raise ValueError(f"source structure is not clean: {name}")


def validate_snapshot(snapshot: dict[str, Any]) -> None:
    if snapshot.get("contract") != SNAPSHOT_CONTRACT:
        raise ValueError(f"snapshot contract must be {SNAPSHOT_CONTRACT}")
    if snapshot.get("metadata_only") is not True:
        raise ValueError("snapshot must explicitly state metadata_only=true")
    if snapshot.get("contains_row_data") is not False:
        raise ValueError("snapshot must explicitly state contains_row_data=false")
    if not isinstance(snapshot.get("tables"), list):
        raise ValueError("snapshot tables must be a list")
    if not isinstance(snapshot.get("triggers"), list):
        raise ValueError("snapshot triggers must be a list")


def normalized_rows(rows: Any, field: str, required: tuple[str, ...]) -> dict[str, dict[str, Any]]:
    if not isinstance(rows, list):
        raise ValueError(f"{field} must be a list")
    result: dict[str, dict[str, Any]] = {}
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError(f"{field} contains a non-object row")
        for key in required:
            if key not in row:
                raise ValueError(f"{field} row missing {key}")
        name = row.get("name")
        if not isinstance(name, str) or not name:
            raise ValueError(f"{field} row has invalid name")
        if name in result:
            raise ValueError(f"{field} contains duplicate name: {name}")
        result[name] = row
    return result


INTEGER_TYPES = ("tinyint", "smallint", "mediumint", "int", "integer", "bigint")


def canonical_sql_type(value: str) -> str:
    """Normalize engine-only integer display widths without hiding real type drift."""
    compact = " ".join(value.strip().lower().split())
    head, separator, tail = compact.partition(" ")
    for integer_type in INTEGER_TYPES:
        prefix = f"{integer_type}("
        if head.startswith(prefix) and head.endswith(")") and head[len(prefix):-1].isdigit():
            head = integer_type
            break
    normalized = head if not separator else f"{head} {tail}"
    return ",".join(part.strip() for part in normalized.split(","))


def canonical_column(row: dict[str, Any]) -> tuple[Any, ...]:
    if not isinstance(row.get("type"), str) or not isinstance(row.get("nullable"), bool):
        raise ValueError("column requires string type and boolean nullable")
    return (row["name"], canonical_sql_type(row["type"]), row["nullable"])


def canonical_index(row: dict[str, Any]) -> tuple[Any, ...]:
    columns = row.get("columns")
    if not isinstance(row.get("unique"), bool) or not isinstance(columns, list):
        raise ValueError("index requires boolean unique and columns list")
    if not all(isinstance(value, str) and value for value in columns):
        raise ValueError("index columns must contain non-empty strings")
    return (row["name"], row["unique"], tuple(columns))


def canonical_fk(row: dict[str, Any]) -> tuple[Any, ...]:
    columns = row.get("columns")
    referenced_columns = row.get("referenced_columns")
    referenced_table = row.get("referenced_table")
    on_delete = row.get("on_delete")
    if not isinstance(columns, list) or not isinstance(referenced_columns, list):
        raise ValueError("foreign key requires column lists")
    if not all(isinstance(value, str) and value for value in columns + referenced_columns):
        raise ValueError("foreign key columns must contain non-empty strings")
    if not isinstance(referenced_table, str) or not referenced_table:
        raise ValueError("foreign key requires referenced_table")
    if not isinstance(on_delete, str) or not on_delete:
        raise ValueError("foreign key requires on_delete")
    return (
        row["name"],
        tuple(columns),
        referenced_table,
        tuple(referenced_columns),
        " ".join(on_delete.upper().split()),
    )


def canonical_trigger(row: dict[str, Any]) -> tuple[Any, ...]:
    table = row.get("table")
    timing = row.get("timing")
    event = row.get("event")
    if not all(isinstance(value, str) and value for value in (table, timing, event)):
        raise ValueError("trigger requires table, timing and event")
    return (row["name"], table, timing.upper(), event.upper())


def compare_named_collection(
    expected: list[dict[str, Any]],
    actual: list[dict[str, Any]],
    canonicalizer: Any,
    label: str,
    table: str,
) -> list[str]:
    expected_by_name = normalized_rows(expected, f"{table}.{label}.source", ("name",))
    actual_by_name = normalized_rows(actual, f"{table}.{label}.snapshot", ("name",))
    failures: list[str] = []

    for name in sorted(expected_by_name.keys() | actual_by_name.keys()):
        if name not in actual_by_name:
            failures.append(f"{table}:{label}:missing:{name}")
            continue
        if name not in expected_by_name:
            failures.append(f"{table}:{label}:unexpected:{name}")
            continue
        if canonicalizer(expected_by_name[name]) != canonicalizer(actual_by_name[name]):
            failures.append(f"{table}:{label}:mismatch:{name}")
    return failures


def is_fk_support_index(index: dict[str, Any], foreign_keys: list[dict[str, Any]]) -> bool:
    """Recognize InnoDB's implicit FK support index without masking other indexes."""
    index_name, _unique, index_columns = canonical_index(index)
    for foreign_key in foreign_keys:
        fk_name, fk_columns, _table, _referenced_columns, _delete = canonical_fk(foreign_key)
        if index_name == fk_name and index_columns == fk_columns:
            return True
    return False


def comparable_snapshot_indexes(
    expected_indexes: list[dict[str, Any]],
    actual_indexes: list[dict[str, Any]],
    actual_foreign_keys: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Drop only engine-generated FK indexes absent from the source declaration."""
    expected_names = {
        row["name"]
        for row in expected_indexes
        if isinstance(row, dict) and isinstance(row.get("name"), str)
    }
    return [
        row
        for row in actual_indexes
        if row.get("name") in expected_names or not is_fk_support_index(row, actual_foreign_keys)
    ]


def compare_table(expected: dict[str, Any], actual: dict[str, Any]) -> list[str]:
    failures: list[str] = []
    expected_indexes = expected.get("indexes", [])
    actual_indexes = actual.get("indexes", [])
    actual_foreign_keys = actual.get("foreign_keys", [])

    failures += compare_named_collection(
        expected.get("columns", []), actual.get("columns", []), canonical_column, "columns", expected["name"]
    )
    failures += compare_named_collection(
        expected_indexes,
        comparable_snapshot_indexes(expected_indexes, actual_indexes, actual_foreign_keys),
        canonical_index,
        "indexes",
        expected["name"],
    )
    failures += compare_named_collection(
        expected.get("foreign_keys", []),
        actual_foreign_keys,
        canonical_fk,
        "foreign_keys",
        expected["name"],
    )
    return failures


def build_report(source: dict[str, Any], snapshot: dict[str, Any]) -> dict[str, Any]:
    validate_source(source)
    validate_snapshot(snapshot)

    source_tables = normalized_rows(source.get("tables"), "source.tables", ("name",))
    snapshot_tables = normalized_rows(snapshot.get("tables"), "snapshot.tables", ("name",))
    source_triggers = normalized_rows(source.get("triggers"), "source.triggers", ("name",))
    snapshot_triggers = normalized_rows(snapshot.get("triggers"), "snapshot.triggers", ("name",))

    failures: list[str] = []
    for table in sorted(source_tables.keys() | {name for name in snapshot_tables if name.startswith("gf_")}):
        if table not in snapshot_tables:
            failures.append(f"tables:missing:{table}")
            continue
        if table not in source_tables:
            failures.append(f"tables:unexpected:{table}")
            continue
        failures += compare_table(source_tables[table], snapshot_tables[table])

    expected_trigger_rows = [source_triggers[name] for name in sorted(source_triggers)]
    actual_trigger_rows = [
        snapshot_triggers[name]
        for name in sorted(snapshot_triggers)
        if str(snapshot_triggers[name].get("table", "")).startswith("gf_")
    ]
    failures += compare_named_collection(
        expected_trigger_rows,
        actual_trigger_rows,
        canonical_trigger,
        "triggers",
        "schema",
    )

    informational_tables = sorted(name for name in snapshot_tables if not name.startswith("gf_"))
    return {
        "contract": REPORT_CONTRACT,
        "comparison_level": "symfony-structure",
        "database_contacted": False,
        "compatible": not failures,
        "checks": {"structure_mismatches": sorted(failures)},
        "counts": {
            "expected_gf_tables": len(source_tables),
            "snapshot_gf_tables": len([name for name in snapshot_tables if name.startswith("gf_")]),
            "expected_triggers": len(source_triggers),
        },
        "informational": {"non_gf_tables": informational_tables},
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true", help="print stable JSON report")
    args = parser.parse_args()

    try:
        source, snapshot = load_envelope(sys.stdin.read())
        report = build_report(source, snapshot)
    except (OSError, ValueError) as error:
        print(f"ERROR: {error}")
        return 2

    if args.json:
        print(json.dumps(report, indent=2, sort_keys=True))
    else:
        print(f"Expected gf_ tables: {report['counts']['expected_gf_tables']}")
        print(f"Snapshot gf_ tables: {report['counts']['snapshot_gf_tables']}")
        print(f"Compatible: {'yes' if report['compatible'] else 'no'}")
        print("Database contacted: no")

    if not report["compatible"]:
        for failure in report["checks"]["structure_mismatches"]:
            print(f"ERROR: {failure}")
        return 1

    print("GF-ARCH-002 Symfony structure parity: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
