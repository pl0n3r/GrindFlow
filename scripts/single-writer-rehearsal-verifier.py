#!/usr/bin/env python3
"""Validate redacted single-writer rehearsal evidence for GF-ARCH-002.

This utility is deliberately non-operational. It validates a redacted rehearsal
receipt and the checked-in ownership/operator evidence contracts. It does not
freeze writers, contact databases, launch subprocesses, read caller-provided
files, or authorize production.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import importlib.util
import json
from pathlib import Path
import re
import sys
from typing import Any

INPUT_CONTRACT = "gf-arch-002-single-writer-rehearsal-input-v1"
REPORT_CONTRACT = "gf-arch-002-single-writer-rehearsal-report-v1"
RECEIPT_CONTRACT = "gf-arch-002-single-writer-rehearsal-receipt-v1"
MAX_STDIN_BYTES = 1_000_000
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
UTC_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")

ROOT_FIELDS = frozenset({
    "contract",
    "module",
    "operator_evidence_input",
    "receipt",
    "production_authorized",
})
RECEIPT_FIELDS = frozenset({
    "contract",
    "module",
    "source_inventory_sha256",
    "operator_evidence_bundle_sha256",
    "freeze_evidence_sha256",
    "observed_at_utc",
    "window_started_at_utc",
    "window_ended_at_utc",
    "environment",
    "previous_writer",
    "proposed_writer",
    "old_writer_writes_blocked",
    "new_writer_writes_enabled",
    "overlapping_writes_observed",
    "operator_observed",
    "contains_row_data",
    "contains_secrets",
})
EXPECTED_ENVIRONMENT = "authorized_isolated_rehearsal"
REMAINING_PRECONDITIONS = (
    "production_single_writer_freeze",
    "owner_authorization",
    "production_smoke",
)


def fail(message: str) -> None:
    """Raise a bounded validation error without reproducing submitted input."""
    raise ValueError(message)


def load_module(filename: str, module_name: str) -> Any:
    path = Path(__file__).with_name(filename)
    spec = importlib.util.spec_from_file_location(module_name, path)
    if spec is None or spec.loader is None:
        fail("required verifier unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def parse_utc(value: Any, field: str) -> datetime:
    if not isinstance(value, str) or not UTC_RE.fullmatch(value):
        fail(f"{field} must use second-precision UTC")
    try:
        return datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError as error:
        raise ValueError(f"{field} is invalid") from error


def exact_bool(receipt: dict[str, Any], key: str, expected: bool) -> None:
    value = receipt[key]
    if type(value) is not bool or value is not expected:
        fail(f"{key} must be {str(expected).lower()}")


def validated_operator_report(envelope: Any, module_name: str) -> dict[str, Any]:
    verifier = load_module(
        "operator-evidence-verifier.py",
        "gf_operator_evidence_verifier",
    )
    report = verifier.build_report(envelope)
    if report.get("module") != module_name:
        fail("operator evidence module mismatch")
    if report.get("production_ready") is not False:
        fail("operator evidence cannot be production ready")
    if report.get("production_authorized") is not False:
        fail("operator evidence cannot authorize production")
    return report


def validate_receipt(
    receipt: Any,
    module_name: str,
    operator_report: dict[str, Any],
) -> dict[str, Any]:
    if not isinstance(receipt, dict) or set(receipt) != RECEIPT_FIELDS:
        fail("single-writer receipt fields do not match the contract")
    if receipt["contract"] != RECEIPT_CONTRACT:
        fail("unsupported single-writer receipt contract")
    if receipt["module"] != module_name:
        fail("single-writer receipt module mismatch")

    source_digest = operator_report["source_inventory_sha256"]
    bundle_digest = operator_report["evidence_bundle_sha256"]
    if receipt["source_inventory_sha256"] != source_digest:
        fail("single-writer receipt source inventory mismatch")
    if receipt["operator_evidence_bundle_sha256"] != bundle_digest:
        fail("single-writer receipt operator evidence mismatch")

    evidence_digest = receipt["freeze_evidence_sha256"]
    if not isinstance(evidence_digest, str) or not SHA256_RE.fullmatch(evidence_digest):
        fail("single-writer evidence digest is invalid")

    if receipt["environment"] != EXPECTED_ENVIRONMENT:
        fail("single-writer rehearsal environment is invalid")
    if receipt["previous_writer"] != "laravel":
        fail("previous writer must remain laravel in this rehearsal contract")
    if receipt["proposed_writer"] != "symfony":
        fail("proposed writer must remain symfony in this rehearsal contract")

    exact_bool(receipt, "old_writer_writes_blocked", True)
    exact_bool(receipt, "new_writer_writes_enabled", True)
    exact_bool(receipt, "overlapping_writes_observed", False)
    exact_bool(receipt, "operator_observed", True)
    exact_bool(receipt, "contains_row_data", False)
    exact_bool(receipt, "contains_secrets", False)

    started = parse_utc(receipt["window_started_at_utc"], "window_started_at_utc")
    ended = parse_utc(receipt["window_ended_at_utc"], "window_ended_at_utc")
    observed = parse_utc(receipt["observed_at_utc"], "observed_at_utc")
    if started >= ended:
        fail("single-writer rehearsal window must have positive duration")
    if observed < ended:
        fail("operator observation cannot predate rehearsal completion")
    return receipt


def build_report(envelope: Any) -> dict[str, Any]:
    if not isinstance(envelope, dict) or set(envelope) != ROOT_FIELDS:
        fail("single-writer evidence fields do not match the contract")
    if envelope["contract"] != INPUT_CONTRACT:
        fail("unsupported single-writer evidence contract")
    if type(envelope["production_authorized"]) is not bool:
        fail("production_authorized must be boolean")
    if envelope["production_authorized"] is not False:
        fail("single-writer rehearsal cannot authorize production")

    module_name = envelope["module"]
    if not isinstance(module_name, str) or not module_name:
        fail("single-writer module is invalid")

    operator_report = validated_operator_report(
        envelope["operator_evidence_input"],
        module_name,
    )
    receipt = validate_receipt(envelope["receipt"], module_name, operator_report)
    return {
        "contract": REPORT_CONTRACT,
        "module": module_name,
        "source_inventory_sha256": operator_report["source_inventory_sha256"],
        "operator_evidence_bundle_sha256": operator_report["evidence_bundle_sha256"],
        "freeze_evidence_sha256": receipt["freeze_evidence_sha256"],
        "window_started_at_utc": receipt["window_started_at_utc"],
        "window_ended_at_utc": receipt["window_ended_at_utc"],
        "observed_at_utc": receipt["observed_at_utc"],
        "environment": EXPECTED_ENVIRONMENT,
        "single_writer_rehearsal_verified": True,
        "production_ready": False,
        "production_authorized": False,
        "remaining_preconditions": list(REMAINING_PRECONDITIONS),
        "next_action": (
            "review rehearsal evidence out-of-band; production freeze, owner "
            "authorization and production smoke remain separate gates"
        ),
    }


def read_stdin_json() -> Any:
    raw = sys.stdin.buffer.read(MAX_STDIN_BYTES + 1)
    if len(raw) > MAX_STDIN_BYTES:
        fail("single-writer evidence exceeds safety limit")
    return json.loads(raw.decode("utf-8"))


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Validate redacted single-writer rehearsal evidence offline."
    )
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    try:
        report = build_report(read_stdin_json())
    except (RecursionError, TypeError, ValueError):
        print("ERROR: single-writer evidence validation failed", file=sys.stderr)
        return 2

    if args.json:
        print(json.dumps(report, sort_keys=True, indent=2))
    else:
        print(f"GF-ARCH-002 single-writer rehearsal: {report['module']}")
        print("Single writer rehearsal: verified structurally")
        print("Production ready: no")
        print("Production authorized: no")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
