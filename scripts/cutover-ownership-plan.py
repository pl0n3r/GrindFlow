#!/usr/bin/env python3
"""Validate a proposed GF-ARCH-002 module writer handoff, entirely offline.

No database connection, migrations, row data, credentials or production approval.
A structurally valid plan is NOT evidence of data parity or permission to cut over.
"""
from __future__ import annotations

import argparse
import json
import sys
from typing import Any

SOURCE_CONTRACT = "gf-arch-002-source-inventory-v1"
PLAN_CONTRACT = "gf-arch-002-cutover-ownership-plan-v1"
REPORT_CONTRACT = "gf-arch-002-cutover-ownership-report-v1"
MAX_STDIN_BYTES = 1_000_000

# These groups are source migration ownership, NOT a claim that schemas or rows
# already correspond. Expanding to a new module requires a reviewed mapping.
MODULE_TABLES: dict[str, dict[str, tuple[str, ...]]] = {
    "identity": {
        "laravel": ("users", "organizations", "memberships"),
        "symfony": (
            "gf_identity_users",
            "gf_identity_organizations",
            "gf_identity_memberships",
        ),
    },
    "vault": {
        "laravel": ("media_assets", "media_blobs"),
        "symfony": ("gf_vault_assets",),
    },
}
PRECONDITIONS = (
    "authorized_metadata_inventory",
    "schema_and_data_parity",
    "restored_database_and_blobs",
    "negative_tenant_and_role_tests",
    "single_writer_freeze_and_rollback",
    "idempotent_migration_rehearsal",
    "owner_authorization",
)
EXPECTED_FIELDS = frozenset({
    "contract", "module", "mode", "source_only", "database_contacted",
    "production_authorized", "current_writer", "proposed_writer",
    "rollback_writer", "laravel_tables", "symfony_tables",
    "single_writer_required", "readiness",
})


def fail(message: str) -> None:
    """Never print submitted rows, identifiers, credentials or input JSON."""
    raise ValueError(message)


def table_names(source: dict[str, Any], runtime: str) -> set[str]:
    rows = source.get(runtime)
    if not isinstance(rows, list):
        fail("invalid source runtime inventory")
    names: set[str] = set()
    for row in rows:
        if not isinstance(row, dict):
            fail("invalid source table row")
        name = row.get("table")
        if (
            not isinstance(name, str)
            or not name
            or row.get("runtime") != runtime
            or row.get("writer") != runtime
            or not isinstance(row.get("migration"), str)
            or not row["migration"]
        ):
            fail("invalid source table ownership")
        if name in names:
            fail("duplicate source table")
        names.add(name)
    return names


def validated_source(source: Any) -> dict[str, set[str]]:
    if not isinstance(source, dict):
        fail("source must be an object")
    if (
        source.get("contract") != SOURCE_CONTRACT
        or source.get("source_only") is not True
        or source.get("database_contacted") is not False
    ):
        fail("source provenance contract failed")
    checks = source.get("checks")
    if not isinstance(checks, dict) or any(
        checks.get(key) != [] for key in (
            "table_name_collisions", "symfony_tables_without_gf_prefix",
        )
    ):
        fail("source schema guard is not clean")
    names = {runtime: table_names(source, runtime) for runtime in ("laravel", "symfony")}
    if names["laravel"] & names["symfony"]:
        fail("source runtimes share a table name")
    if any(not name.startswith("gf_") for name in names["symfony"]):
        fail("Symfony source table lacks gf_ prefix")
    return names


def list_of_unique_strings(value: Any) -> set[str]:
    if not isinstance(value, list) or not value:
        fail("planned table list must be nonempty")
    if any(not isinstance(name, str) or not name for name in value):
        fail("invalid planned table name")
    if len(set(value)) != len(value):
        fail("duplicate planned table")
    return set(value)


def build_report(source: Any, plan: Any) -> dict[str, Any]:
    inventory = validated_source(source)
    if not isinstance(plan, dict) or set(plan) != EXPECTED_FIELDS:
        fail("plan must have exactly the offline ownership contract fields")
    if plan["contract"] != PLAN_CONTRACT:
        fail("unsupported ownership plan contract")
    module = plan["module"]
    if not isinstance(module, str) or module not in MODULE_TABLES:
        fail("unsupported module: an explicit reviewed table mapping is required")
    if (
        plan["mode"] != "planning_only"
        or plan["source_only"] is not True
        or plan["database_contacted"] is not False
        or plan["production_authorized"] is not False
        or plan["single_writer_required"] is not True
    ):
        fail("plan must be source-only, non-operational and unauthorized")
    if (
        plan["current_writer"] != "laravel"
        or plan["proposed_writer"] != "symfony"
        or plan["rollback_writer"] != "laravel"
    ):
        fail("writer transition or rollback is not the documented proposal")
    readiness = plan["readiness"]
    if not isinstance(readiness, dict) or set(readiness) != set(PRECONDITIONS):
        fail("all required cutover preconditions must be present")
    if any(value != "pending" for value in readiness.values()):
        fail("an offline proposal cannot certify readiness or authorization")

    grouping = MODULE_TABLES[module]
    for runtime in ("laravel", "symfony"):
        names = list_of_unique_strings(plan[f"{runtime}_tables"])
        if names != set(grouping[runtime]):
            fail("module table coverage differs from the reviewed mapping")
        if not names <= inventory[runtime]:
            fail("planned tables are missing from the source migrations")

    if set(plan["laravel_tables"]) & set(plan["symfony_tables"]):
        fail("two runtimes cannot own the same table")

    return {
        "contract": REPORT_CONTRACT,
        "module": module,
        "source_only": True,
        "database_contacted": False,
        "cutover_authorized": False,
        "current_writer": "laravel",
        "proposed_writer": "symfony",
        "rollback_writer": "laravel",
        "table_ownership": {
            runtime: sorted(grouping[runtime]) for runtime in ("laravel", "symfony")
        },
        "pending_preconditions": list(PRECONDITIONS),
        "next_action": "review evidence and authorize a separate rehearsal; do not run cutover",
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Validate one proposed module ownership plan from stdin, offline."
    )
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    try:
        raw = sys.stdin.read(MAX_STDIN_BYTES + 1)
        if len(raw) > MAX_STDIN_BYTES:
            fail("input exceeds safety limit")
        envelope = json.loads(raw)
        if not isinstance(envelope, dict) or set(envelope) != {"source", "plan"}:
            fail("envelope must contain source and plan objects only")
        report = build_report(envelope["source"], envelope["plan"])
    except (ValueError, TypeError, json.JSONDecodeError):
        print("ERROR: offline ownership plan validation failed", file=sys.stderr)
        return 2

    if args.json:
        print(json.dumps(report, sort_keys=True, indent=2))
    else:
        print(f"GF-ARCH-002 {report['module']} ownership plan: structurally valid")
        print("Database contacted: no")
        print("Production cutover authorized: no")
        print("All preconditions: pending")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
