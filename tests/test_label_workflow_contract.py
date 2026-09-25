"""Contrato del gate PR de etiquetas: metadata-only y mínimo privilegio."""
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/validate-label-selection.py"
WORKFLOW = ROOT / ".github/workflows/validar-etiquetas.yml"
EXPECTED = "name: Etiquetas\n\non:\n  pull_request:\n    branches: [main]\n    types: [opened, reopened, synchronize, edited, labeled, unlabeled, ready_for_review]\n\npermissions:\n  contents: read\n\nconcurrency:\n  group: grindflow-etiquetas-pr-${{ github.event.pull_request.number }}\n  cancel-in-progress: true\n\njobs:\n  etiquetas:\n    name: Etiquetas\n    runs-on: ubuntu-latest\n    timeout-minutes: 3\n    steps:\n      - name: Leer validador de main, nunca código del PR\n        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1\n        with:\n          ref: ${{ github.event.pull_request.base.sha }}\n          persist-credentials: false\n      - name: Validar clasificación de la PR\n        env:\n          LABELS_JSON: ${{ toJSON(github.event.pull_request.labels) }}\n        shell: bash\n        run: |\n          set -euo pipefail\n          printf '%s' \"$LABELS_JSON\" | python3 scripts/validate-label-selection.py\n"


class LabelWorkflowTests(unittest.TestCase):
    def test_manifest_is_exact_readonly_metadata_only_contract(self):
        self.assertEqual(WORKFLOW.read_text(encoding="utf-8"), EXPECTED)

    def test_mutations_cannot_escape_contract(self):
        for changed in (
            EXPECTED.replace("  contents: read", "  contents: write"),
            EXPECTED.replace("    name: Etiquetas", "    name: Etiquetas bypass"),
            EXPECTED.replace("pull_request:", "pull_request_target:"),
            EXPECTED.replace("github.event.pull_request.base.sha", "github.event.pull_request.head.sha"),
            EXPECTED.replace("          persist-credentials: false", "          persist-credentials: true"),
            EXPECTED.replace("types: [opened, reopened, synchronize, edited, labeled, unlabeled, ready_for_review]", "types: [opened, reopened, synchronize]"),
            EXPECTED.replace("python3 scripts/validate-label-selection.py", "true"),
            EXPECTED + "  unexpected:\n    runs-on: ubuntu-latest\n",
        ):
            with self.subTest(fragment=changed[:65]):
                self.assertNotEqual(changed, EXPECTED)

    def test_cli_accepts_github_metadata_and_rejects_missing_category(self):
        good = [
            {"name": "tipo: producto"}, {"name": "tipo: seguridad"},
            {"name": "prioridad: alta"}, {"name": "estado: en revisión"},
        ]
        for labels, expected in ((good, 0), (good[:2] + good[3:], 2)):
            with self.subTest(labels=labels):
                result = subprocess.run(
                    [sys.executable, str(SCRIPT)], input=json.dumps(labels).encode(),
                    capture_output=True, check=False,
                )
                self.assertEqual(result.returncode, expected, result.stderr)

    def test_fast_ci_contains_workflow_contract(self):
        ci = (ROOT / ".github/workflows/grindflow-ci.yml").read_text(encoding="utf-8")
        self.assertIn("python3 -m unittest tests/test_label_workflow_contract.py", ci)


if __name__ == "__main__":
    unittest.main()
