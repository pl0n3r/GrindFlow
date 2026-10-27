"""QA fail-closed del checklist de salida a producción (#632)."""
from __future__ import annotations

import re
import unittest
from collections import Counter
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs" / "lista-salida-produccion.md"
STATES = ("LISTO", "EN_CURSO", "SIN_COBERTURA", "DEL_DUEÑO", "NO_APLICA")
AREAS = {
    "Respaldos y restauración", "Monitoreo y alertas", "Dominios, DNS y TLS",
    "Secretos y accesos", "Costos y límites", "Privacidad y legal",
    "Soporte y operación diaria", "Migración de datos", "Reversión y apagado",
    "Seguridad y pruebas", "Accesibilidad y rendimiento",
    "Smoke de producción", "Almacenamiento media y CORS",
    "Conectores Dropbox y Drive", "Publicación en redes",
    "Soporte y onboarding", "Migración de Hostinger",
    "Auditoría de lanzamiento", "Decisión de lanzamiento",
}
ISSUE = re.compile(
    r"^\[#(?P<id>[1-9]\d*)\]\(https://github\.com/pl0n3r/GrindFlow/issues/(?P=id)\)$"
)
# Only current, executable leaves, not epics or planned backlog.
EXECUTABLE_LEAVES = {575, 630, 632}
EPICS_NOT_EXECUTABLE = {2, 250, 271, 343, 344, 345, 346, 347, 350, 351, 353, 356}


def parse_rows(text: str) -> list[tuple[str, str, str, str, str]]:
    if text.count("## Matriz") != 1 or text.count("## Resumen final") != 1:
        raise ValueError("Matriz y resumen únicos son obligatorios")
    section = text.split("## Matriz", 1)[1].split("## Resumen final", 1)[0]
    lines = [line for line in section.splitlines() if line.startswith("|")]
    if not lines or lines[0] != "| Área | Requisito | Estado | Evidencia o faltante | Issue |":
        raise ValueError("Falta cabecera canónica")
    rows = []
    seen = set()
    for line in lines[1:]:
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if all(set(cell) <= {"-", ":", " "} for cell in cells):
            continue
        if len(cells) != 5 or any(not cell for cell in cells):
            raise ValueError("Fila vacía o mal formada")
        area, requirement, status, evidence, issue = cells
        if status not in STATES:
            raise ValueError("Estado inválido")
        match = ISSUE.fullmatch(issue)
        if match is None:
            raise ValueError("Issue sin URL auténtica")
        number = int(match["id"])
        if status == "EN_CURSO":
            if number not in EXECUTABLE_LEAVES or "Hoja ejecutable" not in evidence:
                raise ValueError("EN_CURSO exige hoja ejecutable real")
        if status == "SIN_COBERTURA":
            if number in EPICS_NOT_EXECUTABLE or "Hoja ejecutable" not in evidence:
                raise ValueError("SIN_COBERTURA requiere nueva hoja ejecutable canónica")
        if status == "DEL_DUEÑO" and "OWNER" not in evidence:
            raise ValueError("DEL_DUEÑO sin titular de la decisión/operación")
        if status == "LISTO" and "VALIDATED_IN_PRODUCTION" not in evidence:
            raise ValueError("LISTO exige prueba productiva terminal")
        if status == "NO_APLICA" and "razón" not in evidence.lower():
            raise ValueError("NO_APLICA sin justificación")
        key = (area, requirement)
        if key in seen:
            raise ValueError("Requisito duplicado")
        seen.add(key)
        rows.append(cells)
    if not rows:
        raise ValueError("Matriz vacía")
    return rows


def summary_counts(text: str) -> dict[str, int]:
    section = text.split("## Resumen final", 1)[1]
    results = {}
    for line in section.splitlines():
        match = re.fullmatch(
            r"\|\s*(LISTO|EN_CURSO|SIN_COBERTURA|DEL_DUEÑO|NO_APLICA|TOTAL)\s*\|\s*(\d+)\s*\|",
            line.strip(),
        )
        if match:
            key = match[1]
            if key in results:
                raise ValueError("Estado de resumen duplicado")
            results[key] = int(match[2])
    if set(results) != set(STATES) | {"TOTAL"}:
        raise ValueError("Resumen incompleto")
    return results


class ListaSalidaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc = DOC.read_text(encoding="utf-8")
        cls.rows = parse_rows(cls.doc)

    def test_cubre_todo_el_alcance(self):
        self.assertEqual({row[0] for row in self.rows}, AREAS)
        self.assertGreaterEqual(len(self.rows), 40)
        for token in (
            "NO-GO", "D-059", "DNS", "TLS", "rollback",
            "OAuth", "Dropbox", "Drive", "Symfony", "Laravel",
            "Hostinger", "CORS", "media", "soporte", "onboarding",
            "PUBLICACIÓN", "CI", "SHA", "datos.yml",
        ):
            with self.subTest(token=token):
                self.assertIn(token.casefold(), self.doc.casefold())

    def test_sin_cobertura_tiene_issue(self):
        for row in self.rows:
            if row[2] == "SIN_COBERTURA":
                self.assertIsNotNone(ISSUE.fullmatch(row[4]))
                self.assertIn("Hoja ejecutable", row[3])
                self.assertNotIn(int(ISSUE.fullmatch(row[4])["id"]), EPICS_NOT_EXECUTABLE)
        invalid = self.doc.replace(
            "| EN_CURSO | Hoja ejecutable #632",
            "| SIN_COBERTURA | Sin hoja para #632",
            1,
        )
        with self.assertRaises(ValueError):
            parse_rows(invalid)

    def test_formato_y_estados(self):
        self.assertTrue(all(ISSUE.fullmatch(row[4]) for row in self.rows))
        self.assertEqual(len(self.rows), len({(row[0], row[1]) for row in self.rows}))
        invalid = self.doc.replace("| DEL_DUEÑO |", "| CI_LISTO |", 1)
        with self.assertRaises(ValueError):
            parse_rows(invalid)
        invalid_link = self.doc.replace("/issues/311)", "/issues/0)", 1)
        with self.assertRaises(ValueError):
            parse_rows(invalid_link)
        false_in_progress = self.doc.replace(
            "| EN_CURSO | Hoja ejecutable #630",
            "| EN_CURSO | Hoja ejecutable #2",
            1,
        ).replace("](https://github.com/pl0n3r/GrindFlow/issues/630)",
                  "](https://github.com/pl0n3r/GrindFlow/issues/2)", 1)
        with self.assertRaises(ValueError):
            parse_rows(false_in_progress)

    def test_resumen_final(self):
        totals = Counter(row[2] for row in self.rows)
        observed = summary_counts(self.doc)
        self.assertEqual(observed["TOTAL"], len(self.rows))
        for status in STATES:
            self.assertEqual(observed[status], totals[status])
        self.assertIn("Bloquea go-live", self.doc)
        self.assertIn("Hojas creadas por falta de cobertura", self.doc)
        corrupt = self.doc.replace(
            "| TOTAL | " + str(len(self.rows)) + " |", "| TOTAL | 999 |", 1
        )
        self.assertNotEqual(summary_counts(corrupt)["TOTAL"], len(self.rows))


if __name__ == "__main__":
    unittest.main()
