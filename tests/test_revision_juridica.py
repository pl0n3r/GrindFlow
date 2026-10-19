"""Legal handoff contract: byte copies, bundle and read-only workflow."""
import importlib.util
import subprocess
import sys
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch

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
                       "python3 scripts/generar_revision_juridica.py --check", "gh issue create",
                       "concurrency:", "cancel-in-progress: false"):
            self.assertIn(needle, wf)
        self.assertNotIn("contents: write", wf)
        self.assertNotIn("pull_request_target:", wf)


    def test_unknown_entries_and_symlinks_never_escape_or_mutate_handoff(self):
        """Generated package is a closed set; reject extras before writing."""
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            folder = root / "revision-juridica"
            folder.mkdir()
            canonical = folder / "aviso-privacidad.md"
            canonical.write_bytes(b"old")
            unexpected = folder / "unknown.php"
            unexpected.write_bytes(b"keep")
            expected = {"aviso-privacidad.md": b"new"}
            with patch.object(module, "origin", return_value=("a" * 40, "2026-10-19")), patch.object(
                module, "render", return_value=expected
            ):
                for check in (True, False):
                    with self.subTest(kind="foreign", check=check):
                        with self.assertRaisesRegex(ValueError, "Entradas no previstas"):
                            module.sync(root, check)
                self.assertEqual(canonical.read_bytes(), b"old")
                self.assertEqual(unexpected.read_bytes(), b"keep")
                unexpected.unlink()
                outside = root / "external.txt"
                outside.write_bytes(b"safe")
                canonical.unlink()
                canonical.symlink_to(outside)
                for check in (True, False):
                    with self.subTest(kind="symlink", check=check):
                        with self.assertRaisesRegex(ValueError, "Entradas no previstas"):
                            module.sync(root, check)
                self.assertEqual(outside.read_bytes(), b"safe")


if __name__ == "__main__":
    unittest.main()
