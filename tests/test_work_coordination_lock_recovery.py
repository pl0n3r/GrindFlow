import sys
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

    def test_workflow_protegido(self):
        text = (ROOT / ".github/workflows/work-coordination.yml").read_text(encoding="utf-8")
        self.assertIn("pl0n3r/factory/.github/workflows/coordinacion.yml@v1", text)
        self.assertNotIn("@main", text)
        self.assertNotIn("contains(github.event.comment.body", text)
        self.assertIn("github.event.issue.pull_request == null", text)


if __name__ == "__main__":
    unittest.main()
