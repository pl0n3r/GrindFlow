"""Contratos de adopción de política Factory v1 y ownership protegido."""

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def validate_factory_caller(text: str) -> None:
    """Falla si el caller amplía eventos/permisos o deja de usar Factory v1."""
    expected_prefix = """name: Política Factory v1

on:
  pull_request:
    branches: [main]

permissions:
  contents: read
  pull-requests: read

jobs:
"""
    if not text.startswith(expected_prefix):
        raise ValueError("eventos o permisos del caller Factory divergentes")
    if "pull_request_target:" in text or re.search(r"^\s+push:", text, re.MULTILINE):
        raise ValueError("caller Factory debe ser exclusivamente pull_request")
    if "secrets: inherit" in text or "issues:" in text or "contents: write" in text:
        raise ValueError("caller Factory excede permisos mínimos")
    if text.count("pl0n3r/factory/.github/workflows/politica.yml@v1") != 1:
        raise ValueError("caller Factory debe fijarse exactamente a @v1")
    if re.search(r"politica\.yml@(main|master|HEAD|v\d+\.\d+\.\d+)", text):
        raise ValueError("ref Factory no corresponde al canal mayor aprobado")
    if not re.search(
        r"pr_number:\s*\$\{\{ github\.event\.pull_request\.number \}\}", text
    ):
        raise ValueError("pr_number no proviene del PR exacto")


class FactoryPolicyAdoptionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = (ROOT / ".github/workflows/politica.yml").read_text(encoding="utf-8")
        cls.owner_workflow = (ROOT / ".github/workflows/decision-owner.yml").read_text(
            encoding="utf-8"
        )
        cls.ci = (ROOT / ".github/workflows/grindflow-ci.yml").read_text(encoding="utf-8")
        cls.policy = json.loads((ROOT / "decisiones.yml").read_text(encoding="utf-8"))

    def test_caller_is_pr_only_fixed_v1_and_minimal_permissions(self):
        validate_factory_caller(self.workflow)

    def test_caller_rejects_extra_permission_push_and_floating_ref(self):
        variants = (
            self.workflow.replace(
                "  pull-requests: read\n", "  pull-requests: read\n  issues: write\n"
            ),
            self.workflow.replace(
                "  pull_request:\n    branches: [main]\n",
                "  pull_request:\n    branches: [main]\n  push:\n    branches: [main]\n",
            ),
            self.workflow.replace("politica.yml@v1", "politica.yml@main"),
        )
        for candidate in variants:
            with self.subTest(candidate=candidate):
                with self.assertRaises(ValueError):
                    validate_factory_caller(candidate)

    def test_owner_gate_comes_from_protected_base_not_candidate(self):
        workflow = self.owner_workflow
        self.assertIn("pull_request_target:", workflow)
        self.assertNotRegex(workflow, r"(?m)^\s{2}pull_request:")
        self.assertNotRegex(workflow, r"(?m)^\s{2}push:")
        self.assertIn("contents: read", workflow)
        self.assertIn("issues: read", workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertNotIn("issues: write", workflow)
        pinned = "actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09"
        self.assertEqual(workflow.count(pinned), 2)
        self.assertIn("ref: ${{ github.event.pull_request.base.sha }}", workflow)
        self.assertIn("ref: ${{ github.event.pull_request.head.sha }}", workflow)
        self.assertIn("path: .trusted", workflow)
        self.assertIn("path: .candidate", workflow)
        self.assertIn("sparse-checkout: decisiones.yml", workflow)
        self.assertIn("python3 ../.trusted/scripts/decision-owner-gate.py", workflow)
        self.assertNotIn("python3 .candidate/", workflow)

    def test_local_ci_tests_gate_but_does_not_execute_privileged_head_gate(self):
        self.assertIn("tests/test_decision_owner_gate.py", self.ci)
        self.assertIn(".github/workflows/decision-owner.yml", self.ci)
        self.assertNotIn("Enforce owner decisions as code", self.ci)
        self.assertNotIn("issues/$GF_PR/comments?per_page=100", self.ci)
        self.assertIn("name: validate", self.ci)

    def test_policy_baseline_is_factory_compatible(self):
        self.assertEqual(self.policy["version"], 1)
        self.assertEqual(self.policy["review_round_limit"], 3)
        ids = [item["id"] for item in self.policy["decisions"]]
        self.assertEqual(len(ids), len(set(ids)))
        self.assertEqual(ids, ["D-055", "D-056", "D-057", "D-058", "D-059", "D-060"])


if __name__ == "__main__":
    unittest.main()
