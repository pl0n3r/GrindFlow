"""Contratos del caller CI reusable Factory v1."""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def validate_factory_ci_caller(text: str) -> None:
    """Rechaza drift de eventos, permisos, referencia o inputs del caller."""
    expected_prefix = """name: CI Factory v1

on:
  pull_request:
    branches: [main]

permissions:
  contents: read

jobs:
"""
    if not text.startswith(expected_prefix):
        raise ValueError("eventos o permisos del caller CI Factory divergentes")
    if "pull_request_target:" in text or re.search(r"^\s+push:", text, re.MULTILINE):
        raise ValueError("caller CI Factory debe ser exclusivamente pull_request")
    if re.search(r"(?m)^\s{2,}(issues|pull-requests|actions|checks|deployments):", text):
        raise ValueError("caller CI Factory excede permisos mínimos")
    if "contents: write" in text or "secrets: inherit" in text:
        raise ValueError("caller CI Factory no puede escribir ni heredar secretos")
    if text.count("pl0n3r/factory/.github/workflows/ci.yml@v1") != 1:
        raise ValueError("caller CI Factory debe fijarse exactamente a @v1")
    if re.search(r"ci\.yml@(main|master|HEAD|v\d+\.\d+\.\d+)", text):
        raise ValueError("ref Factory no corresponde al canal mayor aprobado")

    expected_inputs = {
        "stack": "laravel",
        "domain": "https://www.grindflow.com.co",
        "version_source": "config/version.php",
        "label_language": "es",
        "phase": "construccion",
        "php_version": "'8.5'",
        "node_enabled": "true",
        "node_version": "'24'",
        "working_directory": ".",
        "kit_ref": "v1",
    }
    for key, value in expected_inputs.items():
        if not re.search(
            rf"(?m)^\s{{6}}{re.escape(key)}:\s*{re.escape(value)}\s*$", text
        ):
            raise ValueError(f"input Factory inválido o ausente: {key}")


class FactoryCiAdoptionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = (ROOT / ".github/workflows/factory-ci.yml").read_text(
            encoding="utf-8"
        )
        cls.local_ci = (ROOT / ".github/workflows/grindflow-ci.yml").read_text(
            encoding="utf-8"
        )

    def test_caller_is_pr_only_read_only_fixed_v1_with_real_inputs(self):
        validate_factory_ci_caller(self.workflow)

    def test_caller_rejects_event_permission_ref_and_input_drift(self):
        variants = (
            self.workflow.replace(
                "  pull_request:\n    branches: [main]\n",
                "  pull_request:\n    branches: [main]\n  push:\n    branches: [main]\n",
            ),
            self.workflow.replace("  contents: read\n", "  contents: write\n"),
            self.workflow.replace("ci.yml@v1", "ci.yml@main"),
            self.workflow.replace("      stack: laravel\n", "      stack: php\n"),
            self.workflow.replace("      phase: construccion\n", "      phase: live\n"),
            self.workflow.replace("      node_enabled: true\n", "      node_enabled: false\n"),
            self.workflow.replace("      kit_ref: v1\n", "      kit_ref: main\n"),
        )
        for candidate in variants:
            with self.subTest(candidate=candidate):
                with self.assertRaises(ValueError):
                    validate_factory_ci_caller(candidate)

    def test_local_ci_remains_present_and_tests_factory_contract(self):
        self.assertIn("name: GrindFlow CI", self.local_ci)
        self.assertIn("name: validate", self.local_ci)
        self.assertIn(".github/workflows/factory-ci.yml", self.local_ci)
        self.assertIn("tests/test_factory_ci_adoption.py", self.local_ci)


if __name__ == "__main__":
    unittest.main()
