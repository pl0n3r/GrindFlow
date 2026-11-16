import os
import subprocess
import sys
import textwrap
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from test_factory_coordination_adoption import FactoryCoordinationAdoptionTests as Adoption

ROOT = Path(__file__).resolve().parents[1]
SHA = "0148bec975ea3fcb93e9957e9ee0c2d599db494f"


class WorkCoordinationRecoveryTests(unittest.TestCase):
    def test_comando_exacto(self):
        self.assertTrue(Adoption.classify(f"/recuperar-lock-huerfano {SHA}"))
        for ya_valido in ("/tomar", "/liberar-forzado", "/adoptar-contrato-huerfana"):
            self.assertTrue(Adoption.classify(ya_valido))

    def test_rechaza_comandos_inseguros(self):
        for body in (
            "/recuperar-lock-huerfano",
            f"  /recuperar-lock-huerfano {SHA}",
            f"/recuperar-lock-huerfano {SHA}  ",
            f"/recuperar-lock-huerfano {SHA}\n",
            f"/recuperar-lock-huerfano {SHA[:39]}",
            f"/recuperar-lock-huerfano {SHA}0",
            f"/recuperar-lock-huerfano {SHA.upper()}",
            f"/recuperar-lock-huerfano {SHA} extra",
            f"/recuperar-lock-huerfano  {SHA}",
            f"/recuperar-lock-huerfano {SHA}\n/tomar",
            f"Nota: /recuperar-lock-huerfano {SHA}",
            f"/recuperar-lock-huerfano $(id)",
            f"/recuperar-lock-huerfano {SHA};id",
        ):
            self.assertFalse(Adoption.classify(body), body)

    @staticmethod
    def classify_owner_v3(body, *, author="pl0n3r", sender="pl0n3r", association="OWNER", repository="pl0n3r/GrindFlow", event="issue_comment", is_pr="false"):
        """Ejecuta el clasificador REAL de Actions con contexto controlado."""
        preflight, _ = Adoption.comment_jobs()
        script = preflight.split('          python3 - <<\'PY\' >> "$GITHUB_OUTPUT"\n', 1)[1]
        script = textwrap.dedent(script.split("\n          PY", 1)[0])
        env = {
            **os.environ,
            "COMMENT_BODY": body,
            "COMMENT_AUTHOR": author,
            "COMMENT_SENDER": sender,
            "COMMENT_ASSOCIATION": association,
            "COMMENT_REPOSITORY": repository,
            "COMMENT_EVENT": event,
            "COMMENT_IS_PR": is_pr,
        }
        result = subprocess.run(
            [sys.executable, "-c", script],
            env=env, capture_output=True, check=True, text=True, timeout=5,
        )
        output = result.stdout.strip()
        if output not in ("valido=true", "valido=false"):
            raise AssertionError("Salida de clasificación no canónica")
        return output == "valido=true"

    def test_comando_owner_v3_exacto(self):
        session = "12345678-1234-4234-8234-123456789abc"
        exact = f"/recuperar-lock-v3-activa {SHA} {session}"
        self.assertTrue(self.classify_owner_v3(exact))
        # No romper el contrato legacy disponible en Factory@v1.
        self.assertTrue(Adoption.classify(f"/recuperar-lock-huerfano {SHA}"))
        self.assertTrue(Adoption.classify("/tomar"))
        self.assertTrue(Adoption.classify("/adoptar-contrato-huerfana"))

    def test_rechaza_cas_v3_inseguro(self):
        session = "12345678-1234-4234-8234-123456789abc"
        exact = f"/recuperar-lock-v3-activa {SHA} {session}"
        malformed = (
            "/recuperar-lock-v3-activa",
            f"/recuperar-lock-v3-activa {SHA}",
            f"/recuperar-lock-v3-activa {SHA[:-1]} {session}",
            f"/recuperar-lock-v3-activa {SHA.upper()} {session}",
            f"/recuperar-lock-v3-activa {SHA} {session.upper()}",
            f"/recuperar-lock-v3-activa {SHA}  {session}",
            f"/recuperar-lock-v3-activa  {SHA} {session}",
            f"/recuperar-lock-v3-activa {SHA} {session[:-1]}",
            f"/recuperar-lock-v3-activa {SHA} 12345678-1234-7234-8234-123456789abc",
            f"/recuperar-lock-v3-activa {SHA} 12345678-1234-4234-1234-123456789abc",
            exact + " ",
            exact + "\n",
            exact + "\n/tomar",
            exact + " extra",
            " " + exact,
            "Nota: " + exact,
            exact + ";echo hello",
            exact.replace(SHA, "$(id)"),
        )
        for raw in malformed:
            with self.subTest(raw=repr(raw)):
                self.assertFalse(self.classify_owner_v3(raw), raw)
        for identity in (
            {"association": "COLLABORATOR"},
            {"association": "MEMBER"},
            {"author": "intruso"},
            {"sender": "intruso"},
            {"association": ""},
            {"repository": "stranger/GrindFlow"},
            {"repository": "pl0n3r/GrindFlow-fork"},
            {"event": "pull_request"},
            {"event": "workflow_dispatch"},
            {"is_pr": "true"},
            {"is_pr": ""},
        ):
            with self.subTest(identity=identity):
                self.assertFalse(self.classify_owner_v3(exact, **identity))

    def test_workflow_owner_v3_protegido(self):
        content = (ROOT / ".github/workflows/work-coordination.yml").read_text(
            encoding="utf-8"
        )
        preflight, consumer = Adoption.comment_jobs()
        self.assertIn("COMMENT_BODY: ${{ github.event.comment.body }}", preflight)
        self.assertIn("COMMENT_AUTHOR: ${{ github.event.comment.user.login }}", preflight)
        self.assertIn("COMMENT_SENDER: ${{ github.event.sender.login }}", preflight)
        self.assertIn("COMMENT_ASSOCIATION: ${{ github.event.comment.author_association }}", preflight)
        self.assertIn("COMMENT_REPOSITORY: ${{ github.repository }}", preflight)
        self.assertIn("COMMENT_EVENT: ${{ github.event_name }}", preflight)
        self.assertIn("COMMENT_IS_PR: ${{ github.event.issue.pull_request != null }}", preflight)
        self.assertIn("github.event_name == 'issue_comment'", preflight)
        self.assertIn("github.event.issue.pull_request == null", preflight)
        self.assertIn("github.event.sender.login == github.event.comment.user.login", preflight)
        self.assertIn("needs['clasificar-comentario'].outputs.valido == 'true'", consumer)
        self.assertIn("group: coordinacion-${{ github.repository }}", content)
        self.assertIn("cancel-in-progress: false", content)
        self.assertIn("queue: max", content)
        self.assertIn("pl0n3r/factory/.github/workflows/coordinacion.yml@v1", content)
        self.assertNotIn(".github/workflows/coordinacion.yml@main", content)
        self.assertNotIn("contains(github.event.comment.body", content)
        self.assertIn("operation: comment", consumer)

    def test_workflow_protegido(self):
        text = (ROOT / ".github/workflows/work-coordination.yml").read_text(encoding="utf-8")
        self.assertIn("pl0n3r/factory/.github/workflows/coordinacion.yml@v1", text)
        self.assertNotIn("@main", text)
        self.assertNotIn("contains(github.event.comment.body", text)
        self.assertIn("github.event.issue.pull_request == null", text)


if __name__ == "__main__":
    unittest.main()
