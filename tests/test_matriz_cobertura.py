"""GrindFlow #630: matriz verificable y fail-closed, sin acceso externo."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
REQ = ROOT / "docs/REQUIREMENTS.md"
STACK = ROOT / "docs/STACK-TRANSITION-SYMFONY.md"
SPEC = ROOT / "docs/GRINDFLOW-SPEC.md"
ALLOWED = {"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"}
ID_RE = re.compile(r"\bGF-(?:FR|NFR|SEC|MIG|OPS|UX|ARCH|LIB)-\d{3}(?:[A-Z]|-[A-Z]+)?\b")


def rows_from_markdown(content: str) -> list[list[str]]:
    return [
        [part.strip() for part in line.strip("|").split("|")]
        for line in content.splitlines()
        if line.startswith("| ") and not line.startswith("| ID/fuente")
        and not re.match(r"^\|\s*---\s*\|", line)
    ]


class MatrizCoberturaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.content = MATRIX.read_text(encoding="utf-8")
        cls.rows = rows_from_markdown(cls.content)
        cls.entries = {row[0]: row for row in cls.rows if row}

    def test_cubre_todas_las_fuentes(self) -> None:
        for name, source in (("REQ", REQ), ("STACK", STACK)):
            required = {name + ":" + key for key in ID_RE.findall(source.read_text(encoding="utf-8"))}
            self.assertTrue(required)
            self.assertFalse(required - self.entries.keys())
        headings = re.findall(r"^#{2,4}\s+\S", SPEC.read_text(encoding="utf-8"), re.MULTILINE)
        self.assertTrue(headings)
        self.assertFalse({f"SPEC:{n:03}" for n in range(1, len(headings)+1)} - self.entries.keys())
        self.assertFalse({f"RM-{n}" for n in range(342, 359)} - self.entries.keys())

    def test_sin_cobertura_tiene_issue(self) -> None:
        for row in self.rows:
            if row[2] == "SIN_COBERTURA":
                self.assertRegex(row[4], r"\[#\d+\]\(https://github\.com/pl0n3r/GrindFlow/issues/\d+\)")
                self.assertNotIn("épico", row[4])
            if row[2] == "NO_VERIFICABLE":
                self.assertRegex(row[3], r"(?i:falta)")

    def test_formato_y_estados(self) -> None:
        self.assertIn("| ID/fuente | Requisito resumido | Estado | Evidencia / qué falta | Issue | Código | CI | Hostinger |", self.content)
        self.assertGreaterEqual(len(self.rows), 95)
        self.assertEqual(len(self.entries), len(self.rows))
        for row in self.rows:
            self.assertEqual(len(row), 8, row[0])
            self.assertTrue(all(row), row[0])
            self.assertIn(row[2], ALLOWED)
            self.assertRegex(row[4], r"\[#\d+\]")
            if row[2] == "CUBIERTO":
                self.assertRegex(row[3], r"\[PR #\d+\]\(https://github\.com/pl0n3r/GrindFlow/pull/\d+\)")
                self.assertTrue(row[5].startswith("IMPLEMENTADO"))
                self.assertNotEqual(row[6], "NO_ACREDITADO")
            if row[2] == "DESCARTADO":
                self.assertIn("cerrado", row[3])

    def test_resumen_por_estado(self) -> None:
        counts = Counter(row[2] for row in self.rows)
        names = ["CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"]
        found = re.search(
            r"Resumen: CUBIERTO=(\d+); EN_CURSO=(\d+); SIN_COBERTURA=(\d+); "
            r"DESCARTADO=(\d+); NO_VERIFICABLE=(\d+); total=(\d+); porcentaje=(\d+\.\d{2})%\.",
            self.content,
        )
        self.assertIsNotNone(found)
        values = [int(v) for v in found.groups()[:6]]
        self.assertEqual(values[:5], [counts[x] for x in names])
        self.assertEqual(values[5], len(self.rows))
        self.assertEqual(sum(values[:5]), len(self.rows))
        self.assertEqual(float(found.group(7)), round(100 * counts["CUBIERTO"] / len(self.rows), 2))


if __name__ == "__main__":
    unittest.main()
