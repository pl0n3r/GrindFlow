#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CALLER = ROOT / ".github" / "workflows" / "aceptacion.yml"

EXPECTED = """name: Aceptación ejecutable

on:
  pull_request:

permissions:
  contents: read
  issues: read
  checks: read

jobs:
  acceptance:
    uses: pl0n3r/factory/.github/workflows/aceptacion.yml@v1
    with:
      issue_number: 0
"""


class FactoryAcceptanceAdoptionTests(unittest.TestCase):
    def caller(self) -> str:
        self.assertTrue(CALLER.is_file(), "falta el caller de aceptación ejecutable")
        return CALLER.read_text(encoding="utf-8")

    def test_caller_uses_factory_v1_and_derives_issue(self) -> None:
        workflow = self.caller()
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/aceptacion.yml@v1",
            workflow,
        )
        self.assertIn("issue_number: 0", workflow)

    def test_caller_is_pull_request_read_only(self) -> None:
        workflow = self.caller()
        self.assertIn("on:\n  pull_request:\n", workflow)
        self.assertIn(
            "permissions:\n"
            "  contents: read\n"
            "  issues: read\n"
            "  checks: read\n",
            workflow,
        )
        self.assertNotIn("write", workflow)

    def test_caller_contract_is_closed_and_secret_free(self) -> None:
        workflow = self.caller()
        self.assertEqual(workflow, EXPECTED)
        self.assertNotIn("secrets:", workflow)
        self.assertNotIn("actions/checkout", workflow)
        self.assertNotIn("workflow_dispatch", workflow)
        self.assertNotIn("pull_request_target", workflow)


if __name__ == "__main__":
    unittest.main()
