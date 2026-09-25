"""Regresiones del gate local de decisiones del propietario."""

import importlib.util
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "decision-owner-gate.py"
spec = importlib.util.spec_from_file_location("decision_owner_gate", SCRIPT)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

HEAD = "a" * 40
OWNER = "pl0n3r"


def policy(text="Regla vigente", status="active", limit=3):
    return gate.validate_policy(
        {
            "version": 1,
            "review_round_limit": limit,
            "decisions": [{"id": "D-100", "status": status, "text": text}],
        }
    )


def comment(*, sha=HEAD, login=OWNER, association="OWNER"):
    return (
        '{"author_association":"'
        + association
        + '","user":{"login":"'
        + login
        + '"},"body":"<!-- grindflow-decision-approval {\\\"sha\\\":\\\"'
        + sha
        + '\\\"} -->"}'
    )


class DecisionOwnerGateTests(unittest.TestCase):
    def test_bootstrap_without_base_policy_passes(self):
        gate.evaluate(base=None, current=policy(), comments=[], owner=OWNER, head_sha=HEAD)

    def test_identical_policy_needs_no_approval(self):
        current = policy()
        gate.evaluate(base=current, current=current, comments=[], owner=OWNER, head_sha=HEAD)

    def test_delete_change_and_supersede_require_approval(self):
        base = {
            "version": 1,
            "review_round_limit": 3,
            "decisions": [
                {"id": "D-100", "status": "active", "text": "A"},
                {"id": "D-101", "status": "active", "text": "B"},
            ],
        }
        variants = [
            {
                "version": 1,
                "review_round_limit": 3,
                "decisions": [{"id": "D-100", "status": "active", "text": "A"}],
            },
            {
                "version": 1,
                "review_round_limit": 3,
                "decisions": [
                    {"id": "D-100", "status": "superseded", "text": "A"},
                    {"id": "D-101", "status": "active", "text": "B"},
                ],
            },
            {
                "version": 1,
                "review_round_limit": 3,
                "decisions": [
                    {"id": "D-100", "status": "active", "text": "C"},
                    {"id": "D-101", "status": "active", "text": "B"},
                ],
            },
        ]
        validated_base = gate.validate_policy(base)
        for current in variants:
            with self.subTest(current=current):
                validated_current = gate.validate_policy(current)
                with self.assertRaises(gate.DecisionGateError):
                    gate.evaluate(
                        base=validated_base,
                        current=validated_current,
                        comments=[],
                        owner=OWNER,
                        head_sha=HEAD,
                    )

    def test_exact_owner_marker_allows_semantic_change(self):
        gate.evaluate(
            base=policy("A"),
            current=policy("B"),
            comments=[comment()],
            owner=OWNER,
            head_sha=HEAD,
        )

    def test_non_owner_and_wrong_sha_markers_fail(self):
        base = policy("A")
        current = policy("B")
        for evidence in (
            comment(login="otro"),
            comment(association="MEMBER"),
            comment(sha="b" * 40),
        ):
            with self.subTest(evidence=evidence):
                with self.assertRaises(gate.DecisionGateError):
                    gate.evaluate(
                        base=base,
                        current=current,
                        comments=[evidence],
                        owner=OWNER,
                        head_sha=HEAD,
                    )

    def test_review_limit_cannot_be_relaxed(self):
        with self.assertRaises(gate.DecisionGateError):
            policy(limit=4)

    def test_schema_rejects_duplicates_and_extra_keys(self):
        duplicate_policy = {
            "version": 1,
            "review_round_limit": 3,
            "decisions": [
                {"id": "D-100", "status": "active", "text": "A"},
                {"id": "D-100", "status": "active", "text": "B"},
            ],
        }
        with self.assertRaises(gate.DecisionGateError):
            gate.validate_policy(duplicate_policy)

        extra_key_policy = {
            "version": 1,
            "review_round_limit": 3,
            "decisions": [
                {"id": "D-100", "status": "active", "text": "A", "extra": True}
            ],
        }
        with self.assertRaises(gate.DecisionGateError):
            gate.validate_policy(extra_key_policy)


if __name__ == "__main__":
    unittest.main()
