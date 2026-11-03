#!/usr/bin/env python3
"""GF-MIG-004: negative, offline gate for retiring the Laravel/legacy stack.

Only synthetic proof objects are exercised here. This test is not an
authorization to cut over traffic, remove code, or inspect production data.
"""
from __future__ import annotations

from copy import deepcopy
from pathlib import Path
import runpy
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/PARITY-MATRIX-EXECUTABLE.md"
SOURCE = ROOT / "docs/STACK-TRANSITION-SYMFONY.md"
PARITY = runpy.run_path(str(ROOT / "tests/test_parity_matrix_executable.py"))
REVERSAL = runpy.run_path(str(ROOT / "scripts/symfony-migration-reversal-plan.py"))

# Separate functional equivalence from a source-only inventory or green CI.
DIMENSIONS = (
    "tenant_isolation",
    "roles",
    "routes",
    "data",
    "states",
    "identifiers",
    "history",
    "write_ownership",
)
PROOFS = DIMENSIONS + (
    "backup_restored_in_isolation",
    "rollback_observed",
    "independent_observer",
    "owner_cutover_authorization",
)


def offline_retirement_gate(
    manifest: object,
    source: str,
    reversal: object,
    proofs: object,
) -> bool:
    """Model the necessary conditions; NEVER grant production authorization.

    Real module validators must accept the evidence before considering the
    independent operational receipts. A source-only reversal plan is not an
    observed rollback; deliberately reject it even with forged proof flags.
    """
    if not isinstance(manifest, dict) or not isinstance(reversal, dict):
        return False
    if not isinstance(proofs, dict) or set(proofs) != set(PROOFS):
        return False
    if any(proofs[key] is not True for key in PROOFS):
        return False

    try:
        PARITY["validate"](manifest, source)
    except (ValueError, KeyError, TypeError, OSError):
        return False

    rows = manifest["capabilities"]
    if not rows or any(
        row["status"] != PARITY["CODE"] or row["deferred_reason"] is not None
        for row in rows
    ):
        return False

    if (
        reversal.get("contract") != REVERSAL["CONTRACT"]
        or reversal.get("direction") != "down"
        or reversal.get("source_only") is not False
        or reversal.get("database_contacted") is not True
        or not isinstance(reversal.get("count"), int)
        or reversal["count"] < 1
        or not isinstance(reversal.get("migrations"), list)
        or reversal["count"] != len(reversal["migrations"])
    ):
        return False

    # The proof object is only a disposable fixture in this test module.
    # Actual operator identity, attestations and authorization require
    # independent validation outside CI; this function is not a deploy gate.
    return True


class MigrationParityExitGateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = SOURCE.read_text(encoding="utf-8")
        cls.manifest = PARITY["load_manifest"](MATRIX.read_text(encoding="utf-8"))
        cls.reversal = REVERSAL["build_plan"]()
        PARITY["validate"](cls.manifest, cls.source)

    def test_incomplete_parity_forbids_legacy_retirement(self) -> None:
        """GF-MIG-003/004: partial parity and untested rollback are NO GO."""
        self.assertTrue(
            any(
                row["status"] == PARITY["DEFERRED"]
                for row in self.manifest["capabilities"]
            ),
            "Current incomplete parity must not be mistaken for full coverage",
        )
        self.assertTrue(self.reversal["source_only"])
        self.assertFalse(self.reversal["database_contacted"])
        self.assertEqual(self.reversal["direction"], "down")

        # Even a fully forged set of boolean receipts cannot promote actual
        # deferred capabilities or source-only reversal into a retirement GO.
        forged_receipts = {name: True for name in PROOFS}
        self.assertFalse(
            offline_retirement_gate(
                self.manifest, self.source, self.reversal, forged_receipts
            )
        )

        # A coded route with a test file is not functional equivalence.
        for dimension in PROOFS:
            with self.subTest(missing_receipt=dimension):
                incomplete = dict(forged_receipts)
                incomplete[dimension] = False
                self.assertFalse(
                    offline_retirement_gate(
                        self.manifest, self.source, self.reversal, incomplete
                    )
                )

    def test_fake_full_parity_does_not_bypass_real_matrix_validator(self) -> None:
        """GF-MIG-003: no fabricated status may satisfy missing routes."""
        forged = deepcopy(self.manifest)
        for row in forged["capabilities"]:
            row["status"] = PARITY["CODE"]
            row["deferred_reason"] = None
        with self.assertRaises(ValueError):
            PARITY["validate"](forged, self.source)
        self.assertFalse(
            offline_retirement_gate(
                forged,
                self.source,
                self.reversal,
                {name: True for name in PROOFS},
            )
        )

    def test_missing_evidence_or_route_remains_fail_closed(self) -> None:
        """GF-MIG-003: negative fixtures exercise the real code validator."""
        matrix = deepcopy(self.manifest)
        coded = next(
            row for row in matrix["capabilities"]
            if row["status"] == PARITY["CODE"]
        )
        coded["evidence"] = []
        with self.assertRaisesRegex(ValueError, "evidence"):
            PARITY["validate"](matrix, self.source)

        matrix = deepcopy(self.manifest)
        coded = next(
            row for row in matrix["capabilities"]
            if row["status"] == PARITY["CODE"]
        )
        coded["symfony"]["route"] = "/fictional-parity-route"
        with self.assertRaisesRegex(ValueError, "Symfony route"):
            PARITY["validate"](matrix, self.source)

    def test_source_only_plan_and_malformed_receipts_are_never_go(self) -> None:
        """GF-MIG-004: a plan without an observed recovery is not a rollback."""
        good_flags = {name: True for name in PROOFS}
        self.assertFalse(
            offline_retirement_gate(
                self.manifest, self.source, self.reversal, good_flags
            )
        )
        for forged in (
            {},
            {**good_flags, "extra": True},
            {**good_flags, "rollback_observed": "true"},
            {**good_flags, "owner_cutover_authorization": None},
        ):
            with self.subTest(forged=forged):
                self.assertFalse(
                    offline_retirement_gate(
                        self.manifest, self.source, self.reversal, forged
                    )
                )

        broken_plan = deepcopy(self.reversal)
        broken_plan["migrations"] = []
        self.assertFalse(
            offline_retirement_gate(
                self.manifest, self.source, broken_plan, good_flags
            )
        )
        self.assertTrue(
            all(row["file"].startswith("Version") for row in
                self.reversal["migrations"])
        )


if __name__ == "__main__":
    unittest.main()
