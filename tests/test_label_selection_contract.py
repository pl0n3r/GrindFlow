"""Regresiones de contrato local de etiquetas; no llama a GitHub."""
import importlib.util
import io
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/validate-label-selection.py"
SPEC = importlib.util.spec_from_file_location("grindflow_labels", SCRIPT)
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)
BASE = ["tipo: infraestructura", "prioridad: alta", "estado: en revisión"]


class LabelSelectionTests(unittest.TestCase):
    def test_canonical_selection(self):
        result = module.validate_selection(BASE + ["rol: qa"])
        self.assertEqual(result["types"], ["tipo: infraestructura"])
        self.assertEqual(result["priorities"], ["prioridad: alta"])

    def test_owner_allows_two_legitimate_types(self):
        result = module.validate_selection([
            "tipo: producto", "tipo: seguridad",
            "prioridad: crítica", "estado: disponible",
        ])
        self.assertEqual(result["types"], ["tipo: producto", "tipo: seguridad"])

    def test_missing_or_duplicate_dimensions_fail_closed(self):
        invalid = [
            BASE[1:], BASE[:1] + BASE[2:], BASE[:2],
            BASE + ["prioridad: baja"], BASE + ["estado: disponible"],
            BASE + ["tipo: seguridad", "tipo: producto"],
            BASE + ["tipo: desconocido"],
            BASE + ["prioridad: normal"],
            BASE + ["estado: desconocido"],
            BASE + [BASE[0]],
        ]
        for labels in invalid:
            with self.subTest(labels=labels):
                with self.assertRaises(module.LabelSelectionError):
                    module.validate_selection(labels)

    def test_bounded_untrusted_input(self):
        for invalid in (None, {}, "bad", list(BASE) + [None], [{"name": 1}], [" tipo: infraestructura"], list(BASE) * 100):
            with self.subTest(invalid=str(invalid)[:70]):
                with self.assertRaises(module.LabelSelectionError):
                    module.validate_selection(invalid)

    def test_github_labels_as_objects(self):
        self.assertEqual(
            module.validate_selection([{"name": name} for name in BASE])["states"],
            ["estado: en revisión"],
        )

    def test_cli_is_read_only_and_rejects_oversize(self):
        ok = subprocess.run([sys.executable, str(SCRIPT)], input=json.dumps(BASE).encode(), capture_output=True, check=False)
        self.assertEqual(ok.returncode, 0, ok.stderr)
        self.assertEqual(json.loads(ok.stdout)["types"], ["tipo: infraestructura"])
        no = subprocess.run([sys.executable, str(SCRIPT)], input=b"x" * 65537, capture_output=True, check=False)
        self.assertEqual(no.returncode, 2)
        self.assertNotIn(b"x" * 100, no.stderr)

    def test_fast_ci_executes_contract(self):
        ci = (ROOT / ".github/workflows/grindflow-ci.yml").read_text(encoding="utf-8")
        self.assertIn("python3 -m unittest tests/test_label_selection_contract.py", ci)


if __name__ == "__main__":
    unittest.main()
