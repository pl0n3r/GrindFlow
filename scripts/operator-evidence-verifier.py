#!/usr/bin/env python3
"""Validate redacted operator evidence for GF-ARCH-002, entirely offline.

This utility does not capture production metadata, restore backups, contact
services, read files supplied by a caller, or authorize a cutover. It accepts
only small JSON receipts that point to evidence reviewed out-of-band.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sys
from typing import Any

INPUT_CONTRACT = "gf-arch-002-operator-evidence-input-v1"
REPORT_CONTRACT = "gf-arch-002-operator-evidence-report-v1"
OWNERSHIP_CONTRACT = "gf-arch-002-cutover-ownership-report-v1"
RECEIPT_CONTRACT = "gf-arch-002-operator-receipt-v1"
MAX_STDIN_BYTES = 1_000_000

EVIDENCE_TYPES = {
    "authorized_metadata_inventory": "authorized_read_only_capture",
    "real_backup_restore_rehearsal": "isolated_restore_observation",
}
PENDING_PRECONDITIONS = (
    "single_writer_freeze_evidence",
    "owner_authorization",
    "production_smoke",
)

ROOT_FIELDS = frozenset({
    "contract",
    "module",
    "ownership_report",
    "receipts",
    "production_authorized",
})
RECEIPT_FIELDS = frozenset({
    "contract",
    "evidence_type",
    "module",
    "source_inventory_sha256",
    "evidence_sha256",
    "observed_at_utc",
    "environment",
    "operator_observed",
    "contains_row_data",
    "contains_secrets",
})
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
UTC_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")


def fail(message: str) -> None:
    """Raise a bounded error without reproducing submitted evidence."""
    raise ValueError(message)


def ownership_module() -> Any:
    path = Path(__file__).with_name("cutover-ownership-plan.py")
    spec = importlib.util.spec_from_file_location("gf_cutover_ownership", path)
    if spec is None or spec.loader is None:
        fail("ownership validator unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def checked_in_ownership_report(module_name: str) -> dict[str, Any]:
    ownership = ownership_module()
    if module_name not in ownership.MODULE_TABLES:
        fail("operator evidence module is not reviewed in this checkout")
    draft = ownership.draft_envelope(module_name)
    return ownership.build_report(draft["source"], draft["plan"])


def validate_ownership_report(report: Any, module_name: str) -> dict[str, Any]:
    if not isinstance(report, dict):
        fail("ownership report must be an object")
    if report.get("contract") != OWNERSHIP_CONTRACT:
        fail("unsupported ownership report contract")
    if report.get("module") != module_name:
        fail("ownership report module mismatch")
    expected = checked_in_ownership_report(module_name)
    if report != expected:
        fail("ownership report differs from checked-in migrations")
    return report


def validate_timestamp(value: Any) -> str:
    if not isinstance(value, str) or not UTC_RE.fullmatch(value):
        fail("operator receipt timestamp must use second-precision UTC")
    try:
        datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError as error:
        raise ValueError("operator receipt timestamp is invalid") from error
    return value


def exact_bool(receipt: dict[str, Any], key: str, expected: bool) -> None:
    value = receipt[key]
    if type(value) is not bool or value is not expected:
        fail(f"operator receipt {key} must be {str(expected).lower()}")


def validate_receipt(
    receipt: Any,
    expected_type: str,
    module_name: str,
    source_inventory_sha256: str,
) -> dict[str, Any]:
    if not isinstance(receipt, dict) or set(receipt) != RECEIPT_FIELDS:
        fail("operator receipt fields do not match the contract")
    if receipt["contract"] != RECEIPT_CONTRACT:
        fail("unsupported operator receipt contract")
    if receipt["evidence_type"] != expected_type:
        fail("operator receipt evidence type mismatch")
    if receipt["module"] != module_name:
        fail("operator receipt module mismatch")
    if receipt["source_inventory_sha256"] != source_inventory_sha256:
        fail("operator receipt source inventory mismatch")

    digest = receipt["evidence_sha256"]
    if not isinstance(digest, str) or not SHA256_RE.fullmatch(digest):
        fail("operator receipt evidence digest is invalid")

    expected_environment = EVIDENCE_TYPES[expected_type]
    if receipt["environment"] != expected_environment:
        fail("operator receipt environment is invalid")

    validate_timestamp(receipt["observed_at_utc"])
    exact_bool(receipt, "operator_observed", True)
    exact_bool(receipt, "contains_row_data", False)
    exact_bool(receipt, "contains_secrets", False)
    return receipt


def validate_receipts(
    receipts: Any,
    module_name: str,
    source_inventory_sha256: str,
) -> dict[str, dict[str, Any]]:
    if not isinstance(receipts, dict) or set(receipts) != set(EVIDENCE_TYPES):
        fail("operator evidence must contain exactly the reviewed receipt types")
    return {
        evidence_type: validate_receipt(
            receipts[evidence_type],
            evidence_type,
            module_name,
            source_inventory_sha256,
        )
        for evidence_type in EVIDENCE_TYPES
    }


def build_report(envelope: Any) -> dict[str, Any]:
    if not isinstance(envelope, dict) or set(envelope) != ROOT_FIELDS:
        fail("operator evidence fields do not match the contract")
    if envelope["contract"] != INPUT_CONTRACT:
        fail("unsupported operator evidence contract")
    if type(envelope["production_authorized"]) is not bool:
        fail("production_authorized must be boolean")
    if envelope["production_authorized"] is not False:
        fail("operator evidence cannot authorize production")

    module_name = envelope["module"]
    if not isinstance(module_name, str) or not module_name:
        fail("operator evidence module is invalid")

    ownership = validate_ownership_report(envelope["ownership_report"], module_name)
    source_digest = ownership["source_inventory_sha256"]
    receipts = validate_receipts(envelope["receipts"], module_name, source_digest)

    canonical = json.dumps(
        envelope,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=False,
    ).encode("utf-8")

    return {
        "contract": REPORT_CONTRACT,
        "evidence_bundle_sha256": hashlib.sha256(canonical).hexdigest(),
        "module": module_name,
        "source_inventory_sha256": source_digest,
        "verified_evidence": {
            evidence_type: {
                "evidence_sha256": receipt["evidence_sha256"],
                "observed_at_utc": receipt["observed_at_utc"],
                "environment": receipt["environment"],
            }
            for evidence_type, receipt in receipts.items()
        },
        "production_ready": False,
        "production_authorized": False,
        "remaining_preconditions": list(PENDING_PRECONDITIONS),
        "next_action": (
            "review the referenced evidence out-of-band; keep freeze, owner "
            "authorization and production smoke separate before any cutover"
        ),
    }


def read_stdin_json() -> Any:
    raw = sys.stdin.buffer.read(MAX_STDIN_BYTES + 1)
    if len(raw) > MAX_STDIN_BYTES:
        fail("operator evidence exceeds safety limit")
    return json.loads(raw.decode("utf-8"))


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Validate redacted operator evidence from stdin, offline."
    )
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    try:
        report = build_report(read_stdin_json())
    except (RecursionError, TypeError, ValueError):
        print("ERROR: operator evidence validation failed", file=sys.stderr)
        return 2

    if args.json:
        print(json.dumps(report, sort_keys=True, indent=2))
    else:
        print(f"GF-ARCH-002 operator evidence: {report['module']}")
        print("Metadata inventory receipt: verified structurally")
        print("Restore rehearsal receipt: verified structurally")
        print("Production ready: no")
        print("Production authorized: no")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
