#!/usr/bin/env python3
"""Build and validate disposable GF-ARCH-002 rehearsal evidence.

This utility is deliberately non-operational:
- no database, network or subprocess access;
- no production credentials, paths or row data;
- CI/disposable evidence can never authorize or declare production readiness.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import re
import sys
from pathlib import Path
from typing import Any

INPUT_CONTRACT = "gf-arch-002-disposable-rehearsal-input-v1"
REPORT_CONTRACT = "gf-arch-002-disposable-rehearsal-report-v1"
OWNERSHIP_CONTRACT = "gf-arch-002-cutover-ownership-report-v1"
MAX_STDIN_BYTES = 1_000_000

CHECKS = (
    "schema_snapshot_parity",
    "migration_reversibility",
    "database_and_vault_restore",
    "post_restore_tenant_and_role_guards",
)
EXTERNAL_PRECONDITIONS = (
    "authorized_production_metadata_inventory",
    "real_backup_restore_rehearsal",
    "single_writer_freeze_evidence",
    "owner_authorization",
    "production_smoke",
)
ROOT_FIELDS = frozenset({
    "contract",
    "ci",
    "ownership_report",
    "checks",
    "production_authorized",
})
CI_FIELDS = frozenset({
    "provider",
    "head_sha",
    "run_id",
    "disposable",
})
GATE_RESULT_FIELDS = frozenset({
    "head_sha",
    "run_id",
    "checks",
})
MAX_GATE_RESULTS_BYTES = 64_000
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
RUN_ID_RE = re.compile(r"^[1-9]\d*$")
DIGEST_RE = re.compile(r"^[0-9a-f]{64}$")


def fail(message: str) -> None:
    """Raise without echoing untrusted evidence or credentials."""
    raise ValueError(message)


def ownership_module() -> Any:
    path = Path(__file__).with_name("cutover-ownership-plan.py")
    spec = importlib.util.spec_from_file_location("gf_cutover_ownership", path)
    if spec is None or spec.loader is None:
        fail("ownership validator unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def validate_ci(ci: Any) -> dict[str, Any]:
    if not isinstance(ci, dict) or set(ci) != CI_FIELDS:
        fail("ci provenance must use exactly the disposable contract fields")
    if ci["provider"] != "github_actions":
        fail("unsupported CI provider")
    if not isinstance(ci["head_sha"], str) or not SHA_RE.fullmatch(ci["head_sha"]):
        fail("invalid CI head SHA")
    if not isinstance(ci["run_id"], str) or not RUN_ID_RE.fullmatch(ci["run_id"]):
        fail("invalid CI run id")
    if type(ci["disposable"]) is not bool or ci["disposable"] is not True:
        fail("rehearsal evidence must come from disposable CI")
    return ci


def checked_in_ownership_report(module_name: str) -> dict[str, Any]:
    ownership = ownership_module()
    if module_name not in ownership.MODULE_TABLES:
        fail("ownership module is not reviewed in this checkout")
    draft = ownership.draft_envelope(module_name)
    return ownership.build_report(draft["source"], draft["plan"])


def ownership_required_fields() -> set[str]:
    return {
        "contract",
        "module",
        "source_inventory_sha256",
        "source_only",
        "database_contacted",
        "cutover_authorized",
        "current_writer",
        "proposed_writer",
        "rollback_writer",
        "table_ownership",
        "outside_this_proposal",
        "pending_preconditions",
        "next_action",
    }


def validate_ownership_identity(report: dict[str, Any]) -> None:
    if report["contract"] != OWNERSHIP_CONTRACT:
        fail("unsupported ownership report contract")
    if not isinstance(report["module"], str) or not report["module"]:
        fail("ownership module is invalid")
    digest = report["source_inventory_sha256"]
    if not isinstance(digest, str) or not DIGEST_RE.fullmatch(digest):
        fail("ownership inventory digest is invalid")


def validate_ownership_safety(report: dict[str, Any]) -> None:
    expected = {
        "source_only": True,
        "database_contacted": False,
        "cutover_authorized": False,
    }
    if any(
        type(report[key]) is not bool or report[key] is not value
        for key, value in expected.items()
    ):
        fail("ownership evidence must remain source-only and unauthorized")


def validate_ownership_transition(report: dict[str, Any]) -> None:
    expected = {
        "current_writer": "laravel",
        "proposed_writer": "symfony",
        "rollback_writer": "laravel",
    }
    if any(report[key] != value for key, value in expected.items()):
        fail("ownership writer transition is not the reviewed proposal")
    if not isinstance(report["table_ownership"], dict):
        fail("ownership table mapping is invalid")
    if not isinstance(report["outside_this_proposal"], dict):
        fail("ownership outside-scope mapping is invalid")


def validate_ownership_pending(report: dict[str, Any]) -> None:
    pending = report["pending_preconditions"]
    if not isinstance(pending, list) or not pending:
        fail("ownership report must retain pending preconditions")
    if any(not isinstance(value, str) or not value for value in pending):
        fail("ownership pending preconditions are invalid")


def validate_ownership(report: Any) -> dict[str, Any]:
    if not isinstance(report, dict):
        fail("ownership report must be an object")
    if set(report) != ownership_required_fields():
        fail("ownership report fields do not match the contract")
    validate_ownership_identity(report)
    validate_ownership_safety(report)
    validate_ownership_transition(report)
    validate_ownership_pending(report)
    if report != checked_in_ownership_report(report["module"]):
        fail("ownership report differs from checked-in migrations")
    return report


def validate_checks(checks: Any) -> dict[str, str]:
    if not isinstance(checks, dict) or set(checks) != set(CHECKS):
        fail("rehearsal checks must match the exact disposable contract")
    if any(checks[name] != "passed" for name in CHECKS):
        fail("all disposable rehearsal checks must have passed")
    return checks


def build_report(envelope: Any) -> dict[str, Any]:
    if not isinstance(envelope, dict) or set(envelope) != ROOT_FIELDS:
        fail("rehearsal envelope fields do not match the contract")
    if envelope["contract"] != INPUT_CONTRACT:
        fail("unsupported rehearsal input contract")
    if (
        type(envelope["production_authorized"]) is not bool
        or envelope["production_authorized"] is not False
    ):
        fail("disposable evidence cannot authorize production")

    ci = validate_ci(envelope["ci"])
    ownership = validate_ownership(envelope["ownership_report"])
    validate_checks(envelope["checks"])

    canonical = json.dumps(
        envelope,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=False,
    ).encode("utf-8")

    return {
        "contract": REPORT_CONTRACT,
        "evidence_sha256": hashlib.sha256(canonical).hexdigest(),
        "scope": "ci_disposable_only",
        "ci": {
            "provider": ci["provider"],
            "head_sha": ci["head_sha"],
            "run_id": ci["run_id"],
            "disposable": ci["disposable"],
        },
        "module": ownership["module"],
        "source_inventory_sha256": ownership["source_inventory_sha256"],
        "passed_checks": list(CHECKS),
        "disposable_evidence": True,
        "production_ready": False,
        "production_authorized": False,
        "external_preconditions_pending": list(EXTERNAL_PRECONDITIONS),
        "next_action": (
            "review disposable evidence; collect authorized production evidence "
            "separately before any rehearsal or cutover"
        ),
    }


def read_stdin_json(max_bytes: int, label: str) -> Any:
    """Read one bounded UTF-8 JSON document from stdin."""
    raw = sys.stdin.buffer.read(max_bytes + 1)
    if len(raw) > max_bytes:
        fail(f"{label} exceed safety limit")
    return json.loads(raw.decode("utf-8"))


def validate_gate_results(
    payload: Any,
    head_sha: str,
    run_id: str,
) -> dict[str, str]:
    """Validate explicit same-run disposable gate results."""
    if not isinstance(payload, dict) or set(payload) != GATE_RESULT_FIELDS:
        fail("gate results fields do not match the contract")
    if payload["head_sha"] != head_sha or payload["run_id"] != run_id:
        fail("gate results do not match the CI run")
    return validate_checks(payload["checks"])


def draft_envelope(
    module: str,
    head_sha: str,
    run_id: str,
    checks: dict[str, str],
) -> dict[str, Any]:
    """Build a disposable envelope from explicitly validated same-run gates."""
    ownership = ownership_module()
    draft = ownership.draft_envelope(module)
    ownership_report = ownership.build_report(draft["source"], draft["plan"])
    envelope = {
        "contract": INPUT_CONTRACT,
        "ci": {
            "provider": "github_actions",
            "head_sha": head_sha,
            "run_id": run_id,
            "disposable": True,
        },
        "ownership_report": ownership_report,
        "checks": validate_checks(checks),
        "production_authorized": False,
    }
    build_report(envelope)
    return envelope


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Validate disposable rehearsal evidence from stdin."
    )
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--template", choices=("identity", "vault"))
    parser.add_argument("--head-sha")
    parser.add_argument("--run-id")
    args = parser.parse_args()

    try:
        if args.template is not None:
            if args.head_sha is None or args.run_id is None:
                fail("--template requires --head-sha and --run-id")
            gate_results = read_stdin_json(
                MAX_GATE_RESULTS_BYTES,
                "gate results",
            )
            checks = validate_gate_results(
                gate_results,
                args.head_sha,
                args.run_id,
            )
            print(json.dumps(
                draft_envelope(args.template, args.head_sha, args.run_id, checks),
                sort_keys=True,
                indent=2,
            ))
            return 0

        report = build_report(read_stdin_json(MAX_STDIN_BYTES, "input"))
    except (RecursionError, TypeError, ValueError):
        print("ERROR: disposable rehearsal evidence validation failed", file=sys.stderr)
        return 2

    if args.json:
        print(json.dumps(report, sort_keys=True, indent=2))
    else:
        print(f"GF-ARCH-002 disposable rehearsal evidence: {report['module']}")
        print("Scope: CI disposable only")
        print("Production ready: no")
        print("Production authorized: no")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
