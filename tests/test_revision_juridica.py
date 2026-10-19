"""Legal handoff contract: byte copies, bundle and read-only workflow."""
import importlib.util
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/generar_revision_juridica.py"
spec = importlib.util.spec_from_file_location("generar_revision_juridica", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RevisionJuridicaTests(unittest.TestCase):
    def test_package_and_workflow(self):
        """AC-01: source-faithful handoff and least-privilege sync."""
        run = subprocess.run([sys.executable, str(SCRIPT), "--check"],
                             cwd=ROOT, capture_output=True, text=True, timeout=35)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        sha, day = module.origin(ROOT)
        expected = module.render(ROOT, sha, day)
        self.assertEqual(len(expected), 9)
        self.assertTrue(expected["00-LEEME.md"].decode().startswith(
            f"Última actualización automática de las fuentes: {day} · SHA de origen: `{sha}`"))
        self.assertIn("documented_not_legally_approved", expected["00-LEEME.md"].decode())
        for provider in ("cloudflare_r2", "dropbox", "google_drive", "supabase"):
            self.assertIn(provider, expected["00-LEEME.md"].decode())
        for filename, content in expected.items():
            self.assertEqual((ROOT / "revision-juridica" / filename).read_bytes(), content)
        for name, _ in module.FILES:
            self.assertEqual((ROOT / "revision-juridica" / name).read_bytes(),
                             (ROOT / "docs/privacidad" / name).read_bytes())
            self.assertIn(f"## docs/privacidad/{name}",
                          expected["paquete-completo.md"].decode("utf-8"))
        wf = (ROOT / ".github/workflows/revision-juridica-sync.yml").read_text()
        for needle in ("schedule:", "branches: [main]", "contents: read",
                       "issues: write", "revision-juridica-desfase",
                       "python3 scripts/generar_revision_juridica.py --check", "gh issue create"):
            self.assertIn(needle, wf)
        self.assertNotIn("contents: write", wf)
        self.assertNotIn("pull_request_target:", wf)


if __name__ == "__main__":
    unittest.main()
