"""Contrato fail-closed del caller CI reusable Factory v1.

El caller tiene un manifiesto pequeño y deliberadamente inmutable. Comparar
su texto completo con la plantilla aprobada impide que claves YAML extra,
comentarios con refs falsos o variaciones de espacios eludan los controles.
"""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CANONICAL_WORKFLOW = """name: CI Factory v1

on:
  pull_request:
    branches: [main]

permissions:
  contents: read

jobs:
  factory:
    name: Factory CI reusable
    uses: pl0n3r/factory/.github/workflows/ci.yml@v1
    with:
      stack: laravel
      domain: https://www.grindflow.com.co
      version_source: config/version.php
      label_language: es
      phase: construccion
      php_version: '8.5'
      node_enabled: true
      node_version: '24'
      working_directory: .
      kit_ref: v1
"""


def validate_factory_ci_caller(text: str) -> None:
    """Exige exactamente el caller aprobado; no busca subcadenas en comentarios."""
    if text != CANONICAL_WORKFLOW:
        raise ValueError(
            "caller CI Factory diverge del manifiesto aprobado "
            "(eventos, permisos, secretos, job, ref o inputs)"
        )


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
        variants = {
            "push": self.workflow.replace(
                "    branches: [main]\n\npermissions:",
                "    branches: [main]\n  push:\n    branches: [main]\n\npermissions:",
            ),
            "pull_request_target": self.workflow.replace(
                "  pull_request:\n",
                "  pull_request:\n  pull_request_target:\n",
            ),
            "write_permission": self.workflow.replace(
                "  contents: read\n", "  contents: write\n"
            ),
            "inherit_secrets": self.workflow.replace(
                "    with:\n", "    secrets: inherit\n    with:\n"
            ),
            "inherit_secrets_with_spaces": self.workflow.replace(
                "    with:\n", "    secrets:  inherit\n    with:\n"
            ),
            "masked_ref_v2": self.workflow.replace(
                "    uses: pl0n3r/factory/.github/workflows/ci.yml@v1\n",
                "    # pl0n3r/factory/.github/workflows/ci.yml@v1\n"
                "    uses: pl0n3r/factory/.github/workflows/ci.yml@v2\n",
            ),
            "floating_ref": self.workflow.replace(
                "ci.yml@v1", "ci.yml@main"
            ),
            "wrong_stack": self.workflow.replace(
                "      stack: laravel\n", "      stack: php\n"
            ),
            "live_phase": self.workflow.replace(
                "      phase: construccion\n", "      phase: live\n"
            ),
            "disable_node": self.workflow.replace(
                "      node_enabled: true\n", "      node_enabled: false\n"
            ),
            "floating_kit_ref": self.workflow.replace(
                "      kit_ref: v1\n", "      kit_ref: main\n"
            ),
            "unexpected_secret_input": self.workflow.replace(
                "      kit_ref: v1\n",
                "      kit_ref: v1\n      extra: unsafe\n",
            ),
            "unexpected_job": self.workflow + (
                "  unexpected:\n    runs-on: ubuntu-latest\n"
                "    steps: []\n"
            ),
            "comment_only": self.workflow + "# allowed @v1, effective @v2\n",
        }
        for name, candidate in variants.items():
            with self.subTest(name=name):
                self.assertNotEqual(candidate, self.workflow)
                with self.assertRaises(ValueError):
                    validate_factory_ci_caller(candidate)

    def test_local_ci_remains_present_and_tests_factory_contract(self):
        self.assertIn("name: GrindFlow CI", self.local_ci)
        self.assertIn("name: validate", self.local_ci)
        self.assertIn(".github/workflows/factory-ci.yml", self.local_ci)
        self.assertIn("tests/test_factory_ci_adoption.py", self.local_ci)


if __name__ == "__main__":
    unittest.main()
