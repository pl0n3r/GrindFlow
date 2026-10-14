import unittest
import re
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
    def comment_guard():
        """Inspect the actual job-level Actions filter, not documentation."""
        text = (ROOT / ".github/workflows/work-coordination.yml").read_text(encoding="utf-8")
        job = text.split("  comentario:", 1)[1].split("  etiqueta:", 1)[0]
        guard = job.split("    if:", 1)[1].split("    # GitHub valida", 1)[0]
        exact = re.findall(r"github\.event\.comment\.body == '([^']+)'", guard)
        prefixes = re.findall(r"startsWith\(github\.event\.comment\.body, '([^']+)'\)", guard)
        return guard, exact, prefixes

    def test_comment_dispatch_does_not_match_embedded_prose(self):
        guard, exact, prefixes = self.comment_guard()
        self.assertEqual(set(exact), {
            "/tomar", "/liberar-forzado", "/adoptar-contrato-huerfana"
        })
        self.assertEqual(set(prefixes), {
            "/liberar ", "/transferir ", "/migrar-contrato ", "/renovar-contrato "
        })
        self.assertNotIn("contains(github.event.comment.body", guard)
        for body in (
            "Despacho: el texto /tomar es solo una referencia",
            "Informe /liberar-forzado de otra sesión",
            "### Nota sobre /adoptar-contrato-huerfana",
            "Se documentó /transferir 12345678-1234-4234-8234-123456789abc",
            "documentación sobre /renovar-contrato 12345678-1234-4234-8234-123456789abc",
            "texto\n/tomar",
            " /tomar",
            "/tomar documentación",
        ):
            with self.subTest(comment=body):
                self.assertFalse(body in exact or any(body.startswith(p) for p in prefixes))

    def test_comment_dispatch_preserves_exact_and_prefix_commands(self):
        _, exact, prefixes = self.comment_guard()
        for command in ("/tomar", "/liberar-forzado", "/adoptar-contrato-huerfana"):
            with self.subTest(command=command):
                self.assertIn(command, exact)
        uuid = "12345678-1234-4234-8234-123456789abc"
        for command in ("/liberar", "/transferir", "/migrar-contrato", "/renovar-contrato"):
            with self.subTest(command=command):
                self.assertTrue(any((command + " " + uuid).startswith(p) for p in prefixes))
        # The prefix only selects the job; the canonical Factory parser
        # still rejects an invalid UUID or unauthorized actor fail-closed.

    def test_caller_keeps_hardened_consumer_coordination_contract(self):
        text = (ROOT / ".github/workflows/work-coordination.yml").read_text(encoding="utf-8")
        self.assertIn("types: [opened, reopened, synchronize, edited, ready_for_review, converted_to_draft, closed]", text)
        self.assertIn("group: coordinacion-${{ github.repository }}", text)
        self.assertIn("cancel-in-progress: false", text)
        self.assertIn("queue: max", text)
        comment = text.split("  comentario:", 1)[1].split("  etiqueta:", 1)[0]
        self.assertIn("github.event.comment.body == '/tomar'", comment)
        self.assertNotIn("contains(github.event.comment.body", comment)
        self.assertIn("github.event.issue.pull_request == null", comment)
        self.assertIn("github.event.sender.login == github.event.comment.user.login", comment)
        self.assertIn("startsWith(github.event.comment.body, '/renovar-contrato ')", comment)
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
