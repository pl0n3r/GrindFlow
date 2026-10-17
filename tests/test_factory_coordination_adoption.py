import unittest
import re
import os
import subprocess
import sys
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class FactoryCoordinationAdoptionTests(unittest.TestCase):
    def test_caller_uses_factory_v1_spanish_profile_only(self):
        text = (ROOT / ".github/workflows/work-coordination.yml").read_text(encoding="utf-8")
        self.assertIn("pl0n3r/factory/.github/workflows/coordinacion.yml@v1", text)
        self.assertIn("profile: es", text)
        self.assertNotIn("@main", text)
        self.assertNotIn("coordinar_trabajo.py", text)
        self.assertIn("startsWith(github.event.pull_request.head.ref, 'factory/bootstrap-coordination-')", text)
        self.assertIn("github.event.pull_request.head.repo.full_name == github.repository", text)
        self.assertIn("github.event.pull_request.author_association == 'OWNER'", text)
        self.assertIn("require_reservation: true", text)

    @staticmethod
    def comment_jobs():
        """Inspeccionar las condiciones reales de ambos jobs."""
        text = (ROOT / ".github/workflows/work-coordination.yml").read_text(encoding="utf-8")
        preflight = text.split("  clasificar-comentario:\n", 1)[1].split("  comentario:\n", 1)[0]
        consumer = text.split("  comentario:\n", 1)[1].split("  etiqueta:\n", 1)[0]
        return preflight, consumer

    @staticmethod
    def normalized_if(job):
        match = re.search(r"(?m)^    if: >-\n((?:      .*\n)+)", job)
        if not match:
            raise AssertionError("No existe if completo para la validación")
        return " ".join(match.group(1).split())

    @classmethod
    def classify(cls, body):
        """Ejecutar offline el Python realmente embebido en Actions."""
        preflight, _ = cls.comment_jobs()
        script = preflight.split('          python3 - <<\'PY\' >> "$GITHUB_OUTPUT"\n', 1)[1]
        script = textwrap.dedent(script.split("\n          PY", 1)[0])
        result = subprocess.run(
            [sys.executable, "-c", script],
            env={**os.environ, "COMMENT_BODY": body},
            capture_output=True,
            check=True,
            text=True,
            timeout=5,
        )
        self_output = result.stdout.strip()
        if self_output not in ("valido=true", "valido=false"):
            raise AssertionError("Salida de clasificación no canónica")
        return self_output == "valido=true"

    def test_comment_dispatch_does_not_match_embedded_prose(self):
        preflight, consumer = self.comment_jobs()
        self.assertEqual(self.normalized_if(preflight),
            "github.event_name == 'issue_comment' && "
            "github.event.issue.pull_request == null && "
            "github.event.sender.login == github.event.comment.user.login")
        self.assertEqual(self.normalized_if(consumer),
            "github.event_name == 'issue_comment' && "
            "github.event.issue.pull_request == null && "
            "github.event.sender.login == github.event.comment.user.login && "
            "needs['clasificar-comentario'].outputs.valido == 'true'")
        self.assertIn("needs: clasificar-comentario", consumer)
        self.assertIn("contents: read", preflight)
        self.assertIn("COMMENT_BODY: ${{ github.event.comment.body }}", preflight)
        self.assertNotIn("contains(github.event.comment.body", consumer)
        for body in (
            "Despacho: el texto /tomar es solo una referencia",
            "Informe /liberar-forzado de otra sesión",
            "### Nota sobre /adoptar-contrato-huerfana",
            "Se documentó /transferir 12345678-1234-4234-8234-123456789abc",
            "documentación sobre /renovar-contrato 12345678-1234-4234-8234-123456789abc",
            "texto\n/tomar",
            "/tomar documentación",
            "/liberar texto no UUID",
            "/transferir 12345678-1234-4234-8234-123456789abc y más",
            "/renovar-contrato 12345678-1234-4234-8234-123456789abc\notro",
            "/liberar 12345678-1234-4234-8234-123456789abz",
            "/liberar 12345678-1234-7234-8234-123456789abc",
            "/liberar 12345678-1234-4234-1234-123456789abc",
        ):
            with self.subTest(comment=body):
                self.assertFalse(self.classify(body), body)

    def test_comment_dispatch_preserves_exact_and_prefix_commands(self):
        uuid = "12345678-1234-4234-8234-123456789abc"
        for command in ("/tomar", "/liberar-forzado", "/adoptar-contrato-huerfana"):
            with self.subTest(command=command):
                self.assertTrue(self.classify(command))
        for command in ("/liberar", "/transferir", "/migrar-contrato", "/renovar-contrato"):
            with self.subTest(command=command):
                self.assertTrue(self.classify(f"{command} {uuid}"))
                self.assertTrue(self.classify(f"{command} {uuid.upper()}"))
        self.assertTrue(self.classify("  /tomar\n"))
        self.assertTrue(self.classify(f"/liberar {uuid}\n"))

    def test_caller_keeps_hardened_consumer_coordination_contract(self):
        text = (ROOT / ".github/workflows/work-coordination.yml").read_text(encoding="utf-8")
        self.assertIn("types: [opened, reopened, synchronize, edited, ready_for_review, converted_to_draft, closed]", text)
        self.assertIn("group: coordinacion-${{ github.repository }}", text)
        self.assertIn("cancel-in-progress: false", text)
        self.assertIn("queue: max", text)
        comment = text.split("  comentario:", 1)[1].split("  etiqueta:", 1)[0]
        self.assertIn("needs['clasificar-comentario'].outputs.valido == 'true'", comment)
        self.assertNotIn("contains(github.event.comment.body", comment)
        self.assertIn("github.event.issue.pull_request == null", comment)
        self.assertIn("github.event.sender.login == github.event.comment.user.login", comment)
        self.assertIn("operation: comment", comment)
        routes = {
            "comentario": "operation: comment",
            "etiqueta": "operation: label",
            "pr": "operation: pr",
            "validar-pr": "operation: validate",
            "issue": "operation: issue",
            "sweep": "operation: sweep",
        }
        names = list(routes)
        for index, name in enumerate(names):
            tail = text.split(f"  {name}:", 1)[1]
            block = tail.split(f"  {names[index + 1]}:", 1)[0] if index + 1 < len(names) else tail
            self.assertIn(routes[name], block)
