import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class FactoryCoordinationAdoptionTests(unittest.TestCase):
    def test_caller_uses_factory_v1_spanish_profile_only(self):
        text = (ROOT / ".github/workflows/work-coordination.yml").read_text(encoding="utf-8")
        self.assertIn("pl0n3r/factory/.github/workflows/coordinacion.yml@v1", text)
        self.assertIn("profile: es", text)
        self.assertNotIn("@main", text)
        self.assertNotIn("coordinar_trabajo.py", text)
        for event in ("issue_comment:", "issues:", "pull_request:", "workflow_dispatch:", "schedule:"):
            self.assertIn(event, text)
