"""GrindFlow #651: contrato fail-closed para propuesta offline de soporte y SLA."""
from __future__ import annotations

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]
DRAFT = ROOT / "docs" / "launch" / "support-sla-draft.md"
EXIT_CHECKLIST = ROOT / "docs" / "lista-salida-produccion.md"
ONBOARDING = ROOT / "docs" / "launch" / "onboarding-support.md"
ROW_PREFIX = "| Soporte y operación diaria | SLA, guardias y gestión de incidencias antes de venta |"

EMAIL = re.compile(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", re.I)
PRIVATE_SECRET = re.compile(
    r"(?i)\b(?:api[_-]?key|client[_-]?secret|password|passwd|bearer|"
    r"access[_-]?token|refresh[_-]?token)\s*(?:[:=]|\bis\b)\s*"
    r"['\"]?[A-Za-z0-9_/-]{6,}"
)
IP_ADDRESS = re.compile(r"\b(?:\d{1,3}\.){3}\d{1,3}\b")
PHONE = re.compile(r"(?<!\d)(?:\+\d{1,3}[ -]?)?\d{10}(?!\d)")
LIVE_TOKEN = re.compile(r"(?i)\b(?:sk_(?:live|test)_|ghp_)[A-Za-z0-9]{10,}\b")


def contains_private_data(content: str) -> bool:
    """Only detect content; no network, accounts or user data."""
    return any(pattern.search(content) for pattern in (EMAIL, PRIVATE_SECRET, IP_ADDRESS, PHONE, LIVE_TOKEN))


def severity_rows(content: str) -> dict[str, list[str]]:
    result: dict[str, list[str]] = {}
    for line in content.splitlines():
        if not re.match(r"^\| S[1-4] \|", line):
            continue
        cells = [item.strip() for item in line.strip().strip("|").split("|")]
        if len(cells) != 8:
            raise AssertionError(f"Fila incompleta: {cells[0]}")
        if cells[0] in result:
            raise AssertionError(f"Severidad duplicada: {cells[0]}")
        result[cells[0]] = cells
    return result


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.content = DRAFT.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self) -> None:
        self.assertTrue(DRAFT.is_file())
        for heading in (
            "# GrindFlow — borrador de soporte, SLA e incidencias (pre-live)",
            "## Matriz S1–S4: severidad, respuesta, canal, responsable y escalamiento",
            "## Triage y escalamiento: flujo PROPUESTA / NO ENSAYADO",
            "## Plantilla de comunicado de incidente (PROPUESTA / NO ENSAYADO)",
            "## Guion de primer contacto (PROPUESTA / NO ENSAYADO)",
            "## Puertas pendientes y evidencia exigida (DEL_DUEÑO, NO-GO)",
        ):
            with self.subTest(heading=heading):
                self.assertIn(heading, self.content)
        rows = severity_rows(self.content)
        self.assertEqual(set(rows), {"S1", "S2", "S3", "S4"})
        for level, cols in rows.items():
            with self.subTest(level=level):
                self.assertIn("hábil", cols[2])
                self.assertIn("POR ASIGNAR", cols[3])
                self.assertIn("POR ASIGNAR", cols[4])
                self.assertIn("PROPUESTA", cols[5])
                if level == "S4":
                    self.assertIn("#651", cols[7])
                else:
                    self.assertIn("onboarding", cols[7])
        self.assertIn("referencia opaca", self.content.lower())
        self.assertIn("Próxima actualización", self.content)

    def test_sin_datos_sensibles(self) -> None:
        self.assertFalse(contains_private_data(self.content))
        self.assertIn("no envíe contraseñas, tokens, datos personales", self.content)
        self.assertIn("sin datos reales", self.content.lower())
        for invented_private_value in (
            "persona@example.invalid",
            "api_key=VALORFICTICIO",
            "192.168.1.15",
            "0" * 10,
        ):
            with self.subTest(negative=invented_private_value):
                self.assertTrue(contains_private_data(invented_private_value))

    def test_estado_honesto(self) -> None:
        self.assertIn("PROPUESTA · NO APROBADO · NO ENSAYADO", self.content)
        self.assertIn("no constituye un SLA contractual", self.content)
        self.assertIn("UNKNOWN", self.content)
        self.assertIn("POR ASIGNAR", self.content)
        self.assertIn("NO-GO", self.content)
        self.assertNotRegex(
            self.content,
            r"(?i)\b(?:SLA vigente|guardia activa|garantizamos|aprobado y vigente|"
            r"24/7 garantizado|se validó en producción)\b",
        )
        for level, row in severity_rows(self.content).items():
            with self.subTest(level=level):
                self.assertIn("PROPUESTA", row[2])
                self.assertIn("NO ENSAYADO", row[2])
                self.assertEqual(row[6], "PROPUESTA / NO ENSAYADO")
                self.assertIn("PROPUESTA", row[5])
        self.assertIn("UNKNOWN; NO ENSAYADO", self.content)

    def test_enlaza_fila_de_salida(self) -> None:
        self.assertTrue(EXIT_CHECKLIST.is_file())
        self.assertTrue(ONBOARDING.is_file())
        rows = [
            line for line in EXIT_CHECKLIST.read_text(encoding="utf-8").splitlines()
            if line.startswith(ROW_PREFIX)
        ]
        self.assertEqual(len(rows), 1, "La fuente de salida debe tener una única fila SLA")
        columns = [cell.strip() for cell in rows[0].strip().strip("|").split("|")]
        self.assertEqual(columns[2], "DEL_DUEÑO")
        self.assertIn("/issues/351", columns[4])
        self.assertIn("[`docs/lista-salida-produccion.md`", self.content)
        self.assertIn("](../lista-salida-produccion.md)", self.content)
        self.assertIn("[#351](https://github.com/pl0n3r/GrindFlow/issues/351)", self.content)
        self.assertIn("[onboarding](onboarding-support.md)", self.content)
        self.assertIn("no cambia esa fila", self.content)


if __name__ == "__main__":
    unittest.main()
