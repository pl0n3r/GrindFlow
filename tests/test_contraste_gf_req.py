#!/usr/bin/env python3
"""#664: contrato fail-closed para contraste conservador de 28 REQ:GF-*."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
DOC = ROOT / "docs/contraste-gf_req.md"
DETAIL = "[Detalle](contraste-gf_req.md)"
ALLOWED = {"CUBIERTO", "SIN_COBERTURA", "DESCARTADO", "NO_APLICA", "NO_VERIFICABLE"}


def parse_table(document: str, columns: int) -> list[list[str]]:
    rows = []
    for line in document.splitlines():
        if not line.startswith("| REQ:"):
            continue
        cells = [cell.strip() for cell in re.split(r"(?<!\\)\|", line.strip("|"))]
        if len(cells) != columns:
            raise AssertionError(f"Columnas inválidas para {cells[0]}")
        rows.append(cells)
    return rows


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.document = DOC.read_text(encoding="utf-8")
        cls.all_rows = parse_table(cls.matrix, 8)
        cls.target_rows = [r for r in cls.all_rows
                           if r[0].startswith("REQ:GF-") and DETAIL in r[3]]
        cls.doc_rows = parse_table(cls.document, 5)
        cls.target_by_id = {r[0]: r for r in cls.target_rows}
        cls.doc_by_id = {r[0]: r for r in cls.doc_rows}

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(len(self.target_rows), 28)
        self.assertEqual(len(self.doc_rows), 28)
        self.assertEqual(len(self.doc_by_id), 28, "IDs duplicados en contraste")
        self.assertEqual(len(self.target_by_id), 28, "IDs duplicados en matriz")
        self.assertEqual(set(self.doc_by_id), set(self.target_by_id))
        for identifier, doc in self.doc_by_id.items():
            with self.subTest(id=identifier):
                self.assertIn(doc[2], ALLOWED)
                self.assertEqual(doc[2], self.target_by_id[identifier][2])
                self.assertIn("github.com/pl0n3r/GrindFlow", doc[1])
                self.assertGreater(len(doc[3]), 20)
                self.assertGreater(len(doc[4]), 40)
                self.assertNotEqual(doc[3], "NO_ACREDITADO")

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        for identifier, row in self.doc_by_id.items():
            if row[2] != "CUBIERTO":
                continue
            with self.subTest(id=identifier):
                refs = re.findall(r"tests/(?:Feature|Unit)/[\w/]+\.php::test_[A-Za-z_0-9]+", row[3])
                self.assertTrue(refs, "CUBIERTO sin método de prueba")
                self.assertRegex(row[4], r"PR\s*#\d+")
                for reference in refs:
                    filename, method = reference.split("::", 1)
                    source = (ROOT / filename)
                    self.assertTrue(source.is_file(), filename)
                    self.assertRegex(source.read_text(encoding="utf-8"),
                                     r"\bfunction\s+" + re.escape(method) + r"\s*\(")

    def test_faltante_tiene_issue(self) -> None:
        for identifier, row in self.doc_by_id.items():
            if row[2] != "SIN_COBERTURA":
                continue
            with self.subTest(id=identifier):
                linked = {int(n) for n in re.findall(r"(?<!\w)#(\d+)", row[4])}
                self.assertTrue(linked - {2, 664}, "Falta hoja GAP propia")

    def test_resumen_global_coincide_con_140_filas(self) -> None:
        rows = [line.split("|")[3].strip()
                for line in self.matrix.splitlines()
                if line.startswith(("| REQ:", "| STACK:", "| SPEC:", "| RM-"))]
        self.assertEqual(len(rows), 140)
        actual = Counter(rows)
        fields = re.search(
            r"Resumen: CUBIERTO=(\\d+); EN_CURSO=(\\d+); "
            r"SIN_COBERTURA=(\\d+); DESCARTADO=(\\d+); "
            r"NO_VERIFICABLE=(\\d+); total=(\\d+);",
            self.matrix,
        )
        self.assertIsNotNone(fields)
        expected = dict(zip(
            ("CUBIERTO", "EN_CURSO", "SIN_COBERTURA",
             "DESCARTADO", "NO_VERIFICABLE"),
            map(int, fields.groups()[:5]),
        ))
        self.assertEqual(dict(actual), {k: v for k, v in expected.items() if v})
        self.assertEqual(sum(actual.values()), int(fields.group(6)))

    def test_matriz_actualizada(self) -> None:
        self.assertEqual(sum(line.startswith("| ") for line in self.matrix.splitlines()) - 2, 140)
        self.assertEqual(len(self.target_rows), 28)
        self.assertEqual(sum(DETAIL in row[3] for row in self.all_rows), 28)
        self.assertEqual(Counter(row[2] for row in self.target_rows),
                         {"NO_VERIFICABLE": 28})
        self.assertEqual(self.matrix.count("; " + DETAIL), 28)
        # El consolidador #949 podrá ampliar evidencias sin cambiar estados.
        ids = [line.split("|")[1].strip() for line in self.matrix.splitlines()
               if line.startswith(("| REQ:", "| STACK:", "| SPEC:", "| RM-"))]
        self.assertEqual(len(ids), 140)
        self.assertEqual(len(set(ids)), 140)
        for row in self.target_rows:
            self.assertIn("verificación 0", row[3])
            self.assertRegex(row[3], r"PR #(?:952|953) fusionado")



if __name__ == "__main__":
    unittest.main()
