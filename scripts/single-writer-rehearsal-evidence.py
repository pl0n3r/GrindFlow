#!/usr/bin/env python3
"""Validate redacted single-writer rehearsal evidence, entirely offline.

The verifier does not freeze writers, migrate data, contact services or
authorize production. It validates only a minimal receipt produced after an
authorized non-production rehearsal and binds it to prior operator evidence.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
import importlib.util
import json
import math
from pathlib import Path
import re
import sys
from typing import Any

INPUT_CONTRACT = "gf-arch-002-single-writer-rehearsal-input-v1"
REPORT_CONTRACT = "gf-arch-002-single-writer-rehearsal-report-v1"
OPERATOR_REPORT_CONTRACT = "gf-arch-002-operator-evidence-report-v1"
RECEIPT_CONTRACT = "gf-arch-002-single-writer-receipt-v1"
MAX_STDIN_BYTES = 1_000_000

EXPECTED_OPERATOR_PENDING = (
    "single_writer_freeze_evidence",
    "owner_authorization",
    "production_smoke",
)
REMAINING_PRECONDITIONS = (
    "owner_authorization",
    "production_smoke",
)
OPERATOR_REPORT_FIELDS = frozenset({
    "contract",
    "evidence_bundle_sha256",
    "module",
    "source_inventory_sha256",
    "scope",
    "receipt_content_verified",
    "validated_receipt_references",
    "production_ready",
    "production_authorized",
    "remaining_preconditions",
    "next_action",
})
OPERATOR_REFERENCE_FIELDS = frozenset({
    "evidence_sha256",
    "observed_at_utc",
    "environment",
})
ROOT_FIELDS = frozenset({
    "contract",
    "module",
    "operator_evidence_report",
    "single_writer_receipt",
    "production_authorized",
})
RECEIPT_FIELDS = frozenset({
    "contract",
    "module",
    "source_inventory_sha256",
    "operator_evidence_bundle_sha256",
    "evidence_sha256",
    "observed_at_utc",
    "environment",
    "previous_writer",
    "candidate_writer",
    "rollback_writer",
    "legacy_writer_frozen",
    "candidate_writer_exclusive",
    "concurrent_writers_observed",
    "rollback_path_observed",
    "operator_observed",
    "contains_row_data",
    "contains_secrets",
})
OPERATOR_REFERENCE_ENVIRONMENTS = {
    "authorized_metadata_inventory": "authorized_read_only_capture",
    "real_backup_restore_rehearsal": "isolated_restore_observation",
}
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
UTC_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")


def fail(message: str) -> None:
    """Raise without reproducing untrusted evidence."""
    raise ValueError(message)


def exact_bool(container: dict[str, Any], key: str, expected: bool) -> None:
    value = container[key]
    if type(value) is not bool or value is not expected:
        fail(f"{key} must be {str(expected).lower()}")


def validate_digest(value: Any, label: str) -> str:
    if not isinstance(value, str) or not SHA256_RE.fullmatch(value):
        fail(f"{label} digest is invalid")
    return value


def validate_timestamp(value: Any, label: str) -> str:
    if not isinstance(value, str) or not UTC_RE.fullmatch(value):
        fail(f"{label} timestamp must use second-precision UTC")
    try:
        datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError as error:
        raise ValueError(f"{label} timestamp is invalid") from error
    return value


def validate_operator_reference(
    evidence_type: str,
    reference: Any,
) -> dict[str, Any]:
    if not isinstance(reference, dict) or set(reference) != OPERATOR_REFERENCE_FIELDS:
        fail("operator evidence reference fields do not match the contract")
    validate_digest(reference["evidence_sha256"], "operator evidence")
    validate_timestamp(reference["observed_at_utc"], "operator evidence")
    if reference["environment"] != OPERATOR_REFERENCE_ENVIRONMENTS[evidence_type]:
        fail("operator evidence reference environment mismatch")
    return reference


def checked_in_source_digest(module_name: str) -> str:
    """Bind the receipt chain to the reviewed migration inventory in this checkout."""
    path = Path(__file__).with_name("operator-evidence-verifier.py")
    spec = importlib.util.spec_from_file_location("gf_operator_evidence", path)
    if spec is None or spec.loader is None:
        fail("operator evidence validator unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    report = module.checked_in_ownership_report(module_name)
    return validate_digest(report["source_inventory_sha256"], "checked-in inventory")


def validate_operator_report(report: Any, module_name: str) -> dict[str, Any]:
    if not isinstance(report, dict) or set(report) != OPERATOR_REPORT_FIELDS:
        fail("operator evidence report fields do not match the contract")
    if report["contract"] != OPERATOR_REPORT_CONTRACT:
        fail("unsupported operator evidence report contract")
    if report["module"] != module_name:
        fail("operator evidence report module mismatch")

    validate_digest(report["evidence_bundle_sha256"], "operator evidence bundle")
    source_digest = validate_digest(
        report["source_inventory_sha256"],
        "source inventory",
    )
    if source_digest != checked_in_source_digest(module_name):
        fail("operator evidence inventory differs from checked-in migrations")
    if report["scope"] != "redacted_references_only":
        fail("operator evidence report scope is invalid")
    exact_bool(report, "receipt_content_verified", False)
    exact_bool(report, "production_ready", False)
    exact_bool(report, "production_authorized", False)
    if report["remaining_preconditions"] != list(EXPECTED_OPERATOR_PENDING):
        fail("operator evidence report preconditions are invalid")

    references = report["validated_receipt_references"]
    if not isinstance(references, dict) or set(references) != set(
        OPERATOR_REFERENCE_ENVIRONMENTS
    ):
        fail("operator evidence report references are incomplete")
    for evidence_type in OPERATOR_REFERENCE_ENVIRONMENTS:
        validate_operator_reference(evidence_type, references[evidence_type])
    reference_digests = {
        reference["evidence_sha256"] for reference in references.values()
    }
    if len(reference_digests) != len(references):
        fail("operator evidence references must remain distinct")
    if reference_digests & {
        source_digest,
        report["evidence_bundle_sha256"],
    }:
        fail("operator evidence digests must remain distinct")
    return report


def validate_single_writer_receipt(
    receipt: Any,
    module_name: str,
    source_inventory_sha256: str,
) -> dict[str, Any]:
    if not isinstance(receipt, dict) or set(receipt) != RECEIPT_FIELDS:
        fail("single-writer receipt fields do not match the contract")
    if receipt["contract"] != RECEIPT_CONTRACT:
        fail("unsupported single-writer receipt contract")
    if receipt["module"] != module_name:
        fail("single-writer receipt module mismatch")
    if receipt["source_inventory_sha256"] != source_inventory_sha256:
        fail("single-writer receipt source inventory mismatch")

    validate_digest(
        receipt["operator_evidence_bundle_sha256"],
        "operator evidence bundle",
    )
    validate_digest(receipt["evidence_sha256"], "single-writer evidence")
    validate_timestamp(receipt["observed_at_utc"], "single-writer evidence")
    if receipt["environment"] != "authorized_nonproduction_rehearsal":
        fail("single-writer receipt environment is invalid")

    expected_writers = {
        "previous_writer": "laravel",
        "candidate_writer": "symfony",
        "rollback_writer": "laravel",
    }
    if any(receipt[key] != value for key, value in expected_writers.items()):
        fail("single-writer receipt transition is invalid")

    exact_bool(receipt, "legacy_writer_frozen", True)
    exact_bool(receipt, "candidate_writer_exclusive", True)
    exact_bool(receipt, "concurrent_writers_observed", False)
    exact_bool(receipt, "rollback_path_observed", True)
    exact_bool(receipt, "operator_observed", True)
    exact_bool(receipt, "contains_row_data", False)
    exact_bool(receipt, "contains_secrets", False)
    return receipt


def build_report(envelope: Any) -> dict[str, Any]:
    if not isinstance(envelope, dict) or set(envelope) != ROOT_FIELDS:
        fail("single-writer evidence fields do not match the contract")
    if envelope["contract"] != INPUT_CONTRACT:
        fail("unsupported single-writer evidence contract")
    exact_bool(envelope, "production_authorized", False)

    module_name = envelope["module"]
    if not isinstance(module_name, str) or not module_name:
        fail("single-writer module is invalid")

    operator_report = validate_operator_report(
        envelope["operator_evidence_report"],
        module_name,
    )
    receipt = validate_single_writer_receipt(
        envelope["single_writer_receipt"],
        module_name,
        operator_report["source_inventory_sha256"],
    )
    if (
        receipt["operator_evidence_bundle_sha256"]
        != operator_report["evidence_bundle_sha256"]
    ):
        fail("single-writer receipt operator evidence mismatch")
    references = operator_report["validated_receipt_references"]
    prerequisite_digests = {
        operator_report["source_inventory_sha256"],
        operator_report["evidence_bundle_sha256"],
        *(reference["evidence_sha256"] for reference in references.values()),
    }
    if receipt["evidence_sha256"] in prerequisite_digests:
        fail("single-writer receipt must reference distinct evidence")
    latest_operator_observation = max(
        reference["observed_at_utc"] for reference in references.values()
    )
    if receipt["observed_at_utc"] <= latest_operator_observation:
        fail("single-writer evidence must be later than prerequisite operator evidence")

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
        "source_inventory_sha256": operator_report["source_inventory_sha256"],
        "operator_evidence_bundle_sha256": operator_report["evidence_bundle_sha256"],
        "single_writer_evidence_sha256": receipt["evidence_sha256"],
        "observed_at_utc": receipt["observed_at_utc"],
        "scope": "authorized_nonproduction_rehearsal_reference_only",
        "receipt_content_verified": False,
        "single_writer_receipt_validated": True,
        "production_ready": False,
        "production_authorized": False,
        "remaining_preconditions": list(REMAINING_PRECONDITIONS),
        "next_action": (
            "review the rehearsal evidence out-of-band; keep owner authorization "
            "and production smoke separate before any cutover"
        ),
    }


def reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    """Reject ambiguous JSON objects before contract validation."""
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            fail("single-writer evidence contains duplicate fields")
        result[key] = value
    return result


def reject_nonfinite_number(value: str) -> None:
    """Reject JavaScript-style non-finite constants; they are not valid JSON."""
    fail("single-writer evidence contains a non-finite number")


def parse_finite_float(value: str) -> float:
    """Reject finite-notation values that overflow Python's float range."""
    number = float(value)
    if not math.isfinite(number):
        fail("single-writer evidence contains a non-finite number")
    return number


def read_stdin_json() -> Any:
    raw = sys.stdin.buffer.read(MAX_STDIN_BYTES + 1)
    if len(raw) > MAX_STDIN_BYTES:
        fail("single-writer evidence exceeds safety limit")
    return json.loads(
        raw.decode("utf-8"),
        object_pairs_hook=reject_duplicate_keys,
        parse_constant=reject_nonfinite_number,
        parse_float=parse_finite_float,
    )


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Validate redacted single-writer rehearsal evidence offline."
    )
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    try:
        report = build_report(read_stdin_json())
    except (RecursionError, TypeError, ValueError):
        print("ERROR: single-writer rehearsal evidence validation failed", file=sys.stderr)
        return 2

    if args.json:
        print(json.dumps(report, sort_keys=True, indent=2))
    else:
        print(f"GF-ARCH-002 single-writer rehearsal evidence: {report['module']}")
        print("Scope: authorized non-production rehearsal reference only")
        print("Receipt content verified: no")
        print("Production ready: no")
        print("Production authorized: no")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
