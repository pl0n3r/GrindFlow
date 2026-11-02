"""Contrato offline de #654: checklist de paridad por tenant; no se ejecuta cutover."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CHECKLIST = ROOT / "docs" / "launch" / "symfony-parity-checklist.md"
REQUIREMENTS = ROOT / "docs" / "REQUIREMENTS.md"
EXIT_LIST = ROOT / "docs" / "lista-salida-produccion.md"

HEADING = re.compile(r"^#{2,6}\s+(GF-[A-Z0-9-]+)\b", re.MULTILINE)
ROW = re.compile(r"^\|\s*(GF-[A-Z0-9-]+)\s*\|", re.MULTILINE)


class DocContractTests(unittest.TestCase):
    """AC-01..AC-04: comprobación estática fail-closed del inventario."""

    def read_checklist(self) -> str:
        self.assertTrue(CHECKLIST.is_file(), "Falta checklist documental de #654")
        return CHECKLIST.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self) -> None:
        content = self.read_checklist()
        for heading in (
            "## Alcance y límites",
            "## Inventario de paridad por requisito",
            "## Método de comparación sin producción",
            "## Evidencia y estado",
            "## Gate de cutover (DEL_DUEÑO)",
        ):
            with self.subTest(section=heading):
                self.assertIn(heading, content)
        self.assertTrue(REQUIREMENTS.is_file(), "Faltan los requisitos fuente")
        actual = HEADING.findall(REQUIREMENTS.read_text(encoding="utf-8"))
        documented = ROW.findall(content)
        self.assertGreater(len(actual), 0)
        self.assertEqual(len(actual), len(set(actual)), "GF-* duplicado en fuente")
        self.assertEqual(len(documented), len(set(documented)), "Fila GF-* duplicada")
        self.assertEqual(set(actual), set(documented), "Faltan o sobran filas GF-*")

    def test_sin_datos_sensibles(self) -> None:
        content = self.read_checklist()
        patterns = (
            r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
            r"(?i)\b(?:ghp_|github_pat_|glpat-|sk_live_|AKIA)[A-Za-z0-9_-]{8,}",
            r"(?i)\bBearer\s+[A-Za-z0-9._=-]{12,}",
            r"(?i)\b(?:password|token|secret|api_key)\s*[:=]\s*[\x22\x27]?[A-Za-z0-9_-]{12,}",
            r"(?i)\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b",
        )
        for expression in patterns:
            with self.subTest(pattern=expression):
                self.assertNotRegex(content, expression)

    def test_estado_honesto(self) -> None:
        content = self.read_checklist()
        rows = [line for line in content.splitlines() if ROW.match(line)]
        self.assertGreater(len(rows), 0)
        for row in rows:
            with self.subTest(requirement=ROW.match(row).group(1)):
                self.assertIn("UNKNOWN", row)
                self.assertIn("PROPUESTA", row)
                self.assertIn("PENDIENTE", row)
                self.assertIn("NO ENSAYADO", row)
                self.assertIn("REQUIREMENTS.md", row)
        self.assertIn("no prueba paridad real", content)
        self.assertIn("sin producción", content.lower())

    def test_enlaza_fila_de_salida(self) -> None:
        content = self.read_checklist()
        self.assertTrue(EXIT_LIST.is_file(), "Falta lista de salida")
        exit_content = EXIT_LIST.read_text(encoding="utf-8")
        self.assertRegex(
            exit_content,
            r"\|\s*Migración de datos\s*\|\s*Cutover Laravel a Symfony con paridad por tenant\s*\|\s*DEL_DUEÑO\s*\|",
        )
        self.assertIn("[lista de salida a producción](../lista-salida-produccion.md)", content)
        self.assertIn("Cutover Laravel a Symfony con paridad por tenant", content)
        self.assertIn("DEL_DUEÑO", content)


if __name__ == "__main__":
    unittest.main()
