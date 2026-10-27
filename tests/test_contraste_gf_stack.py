"""GrindFlow #663: auditar 35 contratos STACK sin inventar cobertura."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
CONTRAST = ROOT / "docs/contraste-gf_stack.md"
SOURCE = ROOT / "docs/STACK-TRANSITION-SYMFONY.md"
SOURCE_SHA = "af275aef3ae1e0b8d4bd2eca6a1a646cac312795"
STATES = {"CUBIERTO", "NO_VERIFICABLE", "SIN_COBERTURA", "DESCARTADO"}
SOURCE_ID = re.compile(r"(?m)^### (GF-[A-Z]+-\d{3})\b")
PR_LINK = re.compile(r"\[PR #(\d+)\]\(https://github\.com/pl0n3r/GrindFlow/pull/\1\)")


def rows_from(text: str, prefix: str) -> dict[str, list[str]]:
    rows: dict[str, list[str]] = {}
    for line in text.splitlines():
        if not line.startswith("| " + prefix):
            continue
        fields = [x.strip() for x in line[1:-1].split("|")]
        name = fields[0]
        if name in rows:
            raise AssertionError("ID duplicado: " + name)
        rows[name] = fields
    return rows


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = SOURCE.read_text(encoding="utf-8")
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.doc = CONTRAST.read_text(encoding="utf-8")
        cls.rows = rows_from(cls.matrix, "STACK:")
        cls.contracts = rows_from(cls.doc, "STACK:")

    def test_cada_fila_tiene_veredicto(self) -> None:
        required = {"STACK:" + key for key in SOURCE_ID.findall(self.source)}
        self.assertEqual(len(required), 35)
        self.assertEqual(set(self.rows), required)
        self.assertEqual(set(self.contracts), required)
        for key in sorted(required):
            with self.subTest(key=key):
                matrix = self.rows[key]
                doc = self.contracts[key]
                self.assertEqual(len(matrix), 8)
                self.assertEqual(len(doc), 7)
                self.assertEqual(matrix[2], doc[2])
                self.assertIn(doc[2], STATES)
                match = re.search(r"STACK-TRANSITION-SYMFONY\.md:L(\d+)", matrix[3])
                self.assertIsNotNone(match)
                line = int(match.group(1))
                self.assertIn(key[6:], self.source.splitlines()[line - 1])
                self.assertIn("/blob/" + SOURCE_SHA + "/", matrix[3])
                self.assertIn("/blob/" + SOURCE_SHA + "/", doc[1])
                self.assertIn("contraste #663", matrix[3])
                self.assertGreater(len(doc[6]), 12)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        totals = Counter(row[2] for row in self.contracts.values())
        self.assertEqual(totals, {"CUBIERTO": 22, "NO_VERIFICABLE": 13})
        for key, row in self.contracts.items():
            with self.subTest(key=key):
                if row[2] != "CUBIERTO":
                    self.assertEqual(row[3:6], ["—", "—", "—"])
                    self.assertIn("falta", self.rows[key][3])
                    continue
                match = PR_LINK.search(row[5])
                self.assertIsNotNone(match)
                self.assertIn(row[5], self.rows[key][3])
                for index in (3, 4):
                    path = ROOT / row[index]
                    self.assertTrue(path.is_file(), str(path))
                self.assertEqual(self.rows[key][5], "IMPLEMENTADO_CON_PRUEBA")
                self.assertEqual(self.rows[key][6], "CI_HISTORICO_POR_REVALIDAR")
                self.assertEqual(self.rows[key][7], "NO_ACREDITADO")

    def test_faltante_tiene_issue(self) -> None:
        found = []
        for key, row in self.contracts.items():
            if row[2] == "SIN_COBERTURA":
                found.append(key)
                match = re.search(
                    r"https://github\.com/pl0n3r/GrindFlow/issues/(\d+)", row[6]
                )
                self.assertIsNotNone(match, key)
                self.assertNotEqual(match.group(1), "2", key)
        self.assertEqual(found, [], "No marcar faltante sin evidencia positiva")
        with self.assertRaises(AssertionError):
            self.assertRegex("épico #2", r"/issues/(?!2\b)\d+")

    def test_matriz_actualizada(self) -> None:
        self.assertIn("## Contraste STACK #663", self.matrix)
        self.assertIn("contraste-gf_stack.md", self.matrix)
        self.assertEqual(len(self.rows), 35)
        for key, row in self.contracts.items():
            self.assertEqual(self.rows[key][2], row[2])
            self.assertTrue(self.rows[key][3].startswith("[STACK-TRANSITION-SYMFONY.md:L"))
        self.assertIn(
            "Resumen: CUBIERTO=41; EN_CURSO=0; SIN_COBERTURA=17; "
            "DESCARTADO=1; NO_VERIFICABLE=81; total=140; porcentaje=29.29%.",
            self.matrix,
        )
        self.assertGreaterEqual(
            sum(line.startswith("| REQ:") or line.startswith("| SPEC:")
                or line.startswith("| STACK:") or line.startswith("| RM-")
                for line in self.matrix.splitlines()), 140
        )


if __name__ == "__main__":
    unittest.main()
