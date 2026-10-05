"""Contratos de adopción de política Factory v1 y ownership protegido."""

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _factory_policy_job(text: str) -> str:
    """Extrae únicamente el job caller de Policy para validar sus inputs efectivos."""
    match = re.search(
        r"(?ms)^  factory-policy:\n(?P<body>(?:(?!^  [A-Za-z0-9_-]+:\n).)*)",
        text,
    )
    if match is None:
        raise ValueError("caller Factory no define el job factory-policy")
    return match.group(0)


def validate_factory_caller(text: str) -> None:
    """Falla si el caller amplía eventos/permisos o deja de usar Factory v1."""
    expected_prefix = """name: Política Factory v1

on:
  pull_request:
    branches: [main]
    types: [opened, synchronize, reopened, edited]
  pull_request_review:
    types: [submitted, edited, dismissed]

permissions:
  contents: read
  pull-requests: read

jobs:
"""
    if not text.startswith(expected_prefix):
        raise ValueError("eventos o permisos del caller Factory divergentes")
    if "pull_request_target:" in text or re.search(r"^\s+push:", text, re.MULTILINE):
        raise ValueError("caller Factory debe limitarse a pull_request y pull_request_review")
    if "secrets: inherit" in text or "issues:" in text or "contents: write" in text:
        raise ValueError("caller Factory excede permisos mínimos")

    job = _factory_policy_job(text)
    refs = re.findall(
        r"^    uses: pl0n3r/factory/\.github/workflows/politica\.yml@([^\s]+)\s*$",
        job,
        re.MULTILINE,
    )
    if len(refs) != 1:
        raise ValueError("caller Factory debe fijar exactamente un reusable Policy")
    ref = refs[0]
    if ref == "v1":
        pass
    elif re.fullmatch(r"[0-9a-f]{40}", ref):
        factory_refs = re.findall(
            r"^      factory_ref:\s*([^\s]+)\s*$",
            job,
            re.MULTILINE,
        )
        if factory_refs != [ref]:
            raise ValueError("caller Factory por SHA exige factory_ref idéntico")
    else:
        raise ValueError("ref Factory debe ser @v1 o SHA lowercase exacto de 40 hex")
    if re.search(r"politica\.yml@(main|master|HEAD|v\d+\.\d+\.\d+)", job):
        raise ValueError("ref Factory no corresponde a un canal aprobado")
    if not re.search(
        r"^      pr_number:\s*\$\{\{ github\.event\.pull_request\.number \}\}\s*$",
        job,
        re.MULTILINE,
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

    def test_caller_is_pr_and_review_only_fixed_v1_and_minimal_permissions(self):
        validate_factory_caller(self.workflow)

    def test_caller_rejects_extra_permission_push_and_floating_ref(self):
        variants = (
            self.workflow.replace(
                "  pull-requests: read\n", "  pull-requests: read\n  issues: write\n"
            ),
            self.workflow.replace(
                "  pull_request_review:\n    types: [submitted, edited, dismissed]\n",
                "  pull_request_review:\n    types: [submitted, edited, dismissed]\n  push:\n    branches: [main]\n",
            ),
            self.workflow.replace(
                "  pull_request_review:\n",
                "  pull_request_target:\n    branches: [main]\n  pull_request_review:\n",
            ),
            re.sub(
                r"(pl0n3r/factory/\.github/workflows/politica\.yml@)[^\s]+",
                r"\1main",
                self.workflow,
                count=1,
            ),
        )
        for candidate in variants:
            with self.subTest(candidate=candidate):
                with self.assertRaises(ValueError):
                    validate_factory_caller(candidate)

    def test_exact_sha_caller_rejects_mismatched_factory_ref(self):
        job = _factory_policy_job(self.workflow)
        match = re.search(
            r"^    uses: pl0n3r/factory/\.github/workflows/politica\.yml@([0-9a-f]{40})\s*$",
            job,
            re.MULTILINE,
        )
        if match is None:
            self.skipTest("caller actual usa el canal estable @v1")
        candidate = self.workflow.replace(
            f"factory_ref: {match.group(1)}",
            "factory_ref: 0000000000000000000000000000000000000000",
            1,
        )
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
        self.assertEqual(ids, ["D-055", "D-056", "D-057", "D-058", "D-059", "D-060", "D-061", "D-062"])

    def test_merge_queue_owner_decision_is_encoded_and_durable(self):
        decisions = {item["id"]: item for item in self.policy["decisions"]}
        decision = decisions["D-061"]
        self.assertEqual(decision["status"], "active")
        text = decision["text"].lower()
        for required in (
            "ownership personal",
            "required checks",
            "main-required-checks",
            "etiquetas",
            "auto-merge",
            "coordinacion factory",
            "merge queue",
            "no es requisito obligatorio",
            "nueva decision explicita",
        ):
            with self.subTest(required=required):
                self.assertIn(required, text)


if __name__ == "__main__":
    unittest.main()
