"""Contratos offline para herencia segura de etiquetas de PR."""
import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "scripts/pr-label-inheritance.py"
SPEC = importlib.util.spec_from_file_location("grindflow_pr_label_inheritance", PATH)
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class PrLabelInheritanceTests(unittest.TestCase):
    def test_extracts_one_closes_reference_only(self):
        self.assertEqual(module.linked_issue_number("Cambio\n\nCloses #125"), 125)
        self.assertEqual(module.linked_issue_number("closes #125."), 125)
        self.assertIsNone(module.linked_issue_number("Refs #125"))
        with self.assertRaises(module.InheritanceError):
            module.linked_issue_number("Closes #125\nCloses #140")

    def test_inherits_only_missing_canonical_dimensions(self):
        issue = [
            {"name": "tipo: producto"},
            {"name": "tipo: seguridad"},
            {"name": "prioridad: crítica"},
            {"name": "estado: disponible"},
            {"name": "rol: seguridad"},
        ]
        self.assertEqual(
            module.plan_inheritance([], issue),
            ["estado: en revisión", "prioridad: crítica", "tipo: producto", "tipo: seguridad"],
        )
        self.assertEqual(
            module.plan_inheritance(
                ["tipo: infraestructura", "estado: bloqueado"], issue
            ),
            ["prioridad: crítica"],
        )

    def test_complete_pr_does_not_require_linked_issue_classification(self):
        pr = [
            "tipo: infraestructura",
            "prioridad: alta",
            "estado: en revisión",
        ]
        self.assertEqual(module.plan_inheritance(pr, []), [])
        self.assertEqual(
            module.plan_inheritance(
                ["tipo: infraestructura", "estado: en revisión"],
                ["prioridad: alta"],
            ),
            ["prioridad: alta"],
        )

    def test_never_inherits_issue_state_or_arbitrary_labels(self):
        issue = [
            "tipo: infraestructura",
            "prioridad: alta",
            "estado: bloqueado",
            "rol: qa",
            "cliente: valor-libre",
        ]
        add = module.plan_inheritance([], issue)
        self.assertEqual(
            add,
            ["estado: en revisión", "prioridad: alta", "tipo: infraestructura"],
        )
        self.assertNotIn("estado: bloqueado", add)
        self.assertNotIn("cliente: valor-libre", add)

    def test_invalid_source_or_conflicting_pr_fails_closed(self):
        invalid_sources = [
            ["prioridad: alta", "estado: disponible"],
            ["tipo: infraestructura", "prioridad: alta", "prioridad: baja"],
            ["tipo: no-canonico", "prioridad: alta"],
        ]
        for labels in invalid_sources:
            with self.subTest(labels=labels):
                with self.assertRaises(module.InheritanceError):
                    module.plan_inheritance([], labels)
        with self.assertRaises(module.InheritanceError):
            module.plan_inheritance(
                ["prioridad: alta", "prioridad: baja"],
                ["tipo: infraestructura", "prioridad: alta"],
            )

    def test_cli_output_is_bounded_and_sanitized(self):
        extract = subprocess.run(
            [sys.executable, str(PATH), "extract"],
            input=json.dumps({"body": "Closes #125"}).encode(),
            capture_output=True,
            check=False,
        )
        self.assertEqual(extract.returncode, 0, extract.stderr)
        self.assertEqual(json.loads(extract.stdout), {"issue_number": 125})

        bad = subprocess.run(
            [sys.executable, str(PATH), "extract"],
            input=json.dumps({"body": "Closes #125\nCloses #140 SECRET"}).encode(),
            capture_output=True,
            check=False,
        )
        self.assertEqual(bad.returncode, 2)
        self.assertNotIn(b"SECRET", bad.stderr)

    def test_workflow_uses_base_code_and_metadata_only(self):
        workflow = (ROOT / ".github/workflows/heredar-etiquetas-pr.yml").read_text()
        self.assertIn("github.event.pull_request.base.sha", workflow)
        self.assertNotIn("pull_request_target", workflow)
        self.assertNotIn("github.event.pull_request.head.sha", workflow)
        self.assertIn("issues: write", workflow)
        self.assertIn("pull-requests: read", workflow)
        self.assertNotIn("issue_comment", workflow)
        self.assertIn('has("pull_request")', workflow)
        self.assertIn("Closes debe enlazar un Issue", workflow)


if __name__ == "__main__":
    unittest.main()
