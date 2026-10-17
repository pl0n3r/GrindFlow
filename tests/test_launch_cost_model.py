"""Pruebas offline para impedir costos inventados y una promoción de readiness."""
from pathlib import Path
import re
import unittest

DOC = Path(__file__).resolve().parents[1] / "docs/launch/cost-model.md"
COLUMNS = (
    "ID", "Área", "Rubro", "Unidad de consumo", "Cantidad",
    "Tarifa", "Subtotal mensual", "Referencia de requisito",
    "Fuente de medición", "Motivo de ausencia", "Estado",
)
REQUIRED = {
    **{f"GF-K{i:02d}": "Storage" for i in (1, 2, 3)},
    **{f"GF-K{i:02d}": "Colas" for i in (4, 5, 6)},
    **{f"GF-K{i:02d}": "Operación" for i in (7, 8, 9, 10)},
    **{f"GF-K{i:02d}": "Proveedores" for i in (11, 12)},
}
EXPECTED_RUBROS = {
    "GF-K01": "Objetos multimedia del piloto",
    "GF-K02": "Copias y versiones de respaldo",
    "GF-K03": "Egreso de archivos y CDN",
    "GF-K04": "Trabajos encolados y ejecutados",
    "GF-K05": "Reintentos y mensajes fallidos",
    "GF-K06": "Procesamiento de contenido y workers",
    "GF-K07": "Hosting y runtime de aplicación",
    "GF-K08": "Persistencia de base de datos",
    "GF-K09": "Observabilidad y alertas",
    "GF-K10": "Atención y soporte humano",
    "GF-K11": "Solicitudes a APIs de redes sociales",
    "GF-K12": "Servicios externos de comunicación",
}

SOURCE = "[#351](https://github.com/pl0n3r/GrindFlow/issues/351)"
UNKNOWN_SOURCE = "UNKNOWN (sin medición autenticada)"
STATUS = {
    "Estado de costos": "UNKNOWN mientras no existan consumos y tarifas autenticados.",
    "Precios finales": "NO DEFINIDOS por esta matriz sin evidencia y aprobación independientes.",
    "Decisión de compra": "NO AUTORIZADA por esta matriz; requiere aprobación independiente.",
    "Activación / go-live": "NO AUTORIZADO por esta matriz; requiere autorización independiente.",
}


def parse_matrix(text: str) -> list[dict[str, str]]:
    """Acepta solo el inventario completo, inequívoco, sin precios simulados."""
    lines = text.splitlines()
    heading = "| " + " | ".join(COLUMNS) + " |"
    if lines.count(heading) != 1:
        raise ValueError("cabecera única obligatoria")
    offset = lines.index(heading)
    if offset + 1 >= len(lines):
        raise ValueError("separador ausente")
    parts = [s.strip() for s in lines[offset + 1].strip().strip("|").split("|")]
    if len(parts) != len(COLUMNS) or not all(re.fullmatch(r":?-{3,}:?", s) for s in parts):
        raise ValueError("separador inválido")
    records = []
    for line in lines[offset + 2:]:
        if not line.startswith("|"):
            break
        values = [s.strip() for s in line.strip().strip("|").split("|")]
        if len(values) != len(COLUMNS):
            raise ValueError("columnas incompletas")
        if not all(values):
            raise ValueError("campo vacío")
        row = dict(zip(COLUMNS, values))
        records.append(row)
    # A blank line may not conceal additional rows, including non-GF IDs.
    # End of the cost section is the next Markdown heading, not whitespace.
    for remaining in lines[offset + 2 + len(records):]:
        if re.match(r"^#{1,6}[ \t]+", remaining):
            break
        if re.match(r"^[ \t]*\|", remaining):
            raise ValueError("fila de costos fuera de la matriz")
    ids = [r["ID"] for r in records]
    if len(ids) != len(REQUIRED) or set(ids) != set(REQUIRED) or len(set(ids)) != len(ids):
        raise ValueError("identidad de rubros incompleta o duplicada")
    for row in records:
        if row["Área"] != REQUIRED[row["ID"]]:
            raise ValueError("clasificación de área alterada")
        if row["Rubro"] != EXPECTED_RUBROS[row["ID"]]:
            raise ValueError("identidad de rubro alterada")
        if row["Unidad de consumo"] in ("UNKNOWN", "-", "N/A", "0"):
            raise ValueError("unidad de consumo ausente")
        if len(row["Unidad de consumo"]) < 4 or re.search(r"[|<>{}]", row["Unidad de consumo"]):
            raise ValueError("unidad de consumo inválida")
        if row["Referencia de requisito"] != SOURCE:
            raise ValueError("origen normativo no acreditado")
        if row["Fuente de medición"] != UNKNOWN_SOURCE:
            raise ValueError("origen real de medida sin evidencia autenticada")
        if len(row["Motivo de ausencia"]) < 12 or row["Motivo de ausencia"] == "UNKNOWN":
            raise ValueError("motivo de incertidumbre ausente")
        for field in ("Cantidad", "Tarifa", "Subtotal mensual", "Estado"):
            if row[field] != "UNKNOWN":
                raise ValueError("valores no acreditados no pueden tener cifras, cero ni READY")
    return records


def verify_decisions(text: str) -> None:
    for label, expected in STATUS.items():
        declaration = f"**{label}:** {expected}"
        # Only a line-initial decision declaration is authoritative.
        # Both plain and Markdown-emphasized labels must be inspected: an
        # unformatted duplicate cannot silently authorize purchase/go-live.
        marker = re.compile(
            r"^[ \t]*(?:[-*+>][ \t]+|#{1,6}[ \t]+){0,2}(?:\*\*)?"
            + re.escape(label) + r"[ \t]*(?:\*\*)?[ \t]*:(?:\*\*)?",
            re.IGNORECASE,
        )
        occurrences = [line.strip() for line in text.splitlines()
                       if marker.match(line)]
        if occurrences != [declaration]:
            raise ValueError("decisión ambigua, duplicada o falsamente aprobada")
    if not text.endswith("**Resultado:** UNKNOWN / NO-GO documental hasta acreditar evidencia suficiente. Esta matriz no autoriza precios definitivos, contratación, gasto, transferencia de datos ni activación de infraestructura.\n"):
        raise ValueError("resultado documental no conserva NO-GO")


class LaunchCostModelTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc = DOC.read_text(encoding="utf-8")

    def test_each_cost_line_has_unit_and_source_or_unknown(self):
        rows = parse_matrix(self.doc)
        self.assertEqual(len(rows), 12)
        self.assertEqual({r["Área"] for r in rows}, set(REQUIRED.values()))
        for original, mutated in (
            ("GiB·mes | UNKNOWN", "UNKNOWN | UNKNOWN"),
            (SOURCE, "fuente aún no acordada"),
            ("UNKNOWN (sin medición autenticada)", "datos medidos"),
            ("Volumen, retención y restore no acreditados", "UNKNOWN"),
            ("| GF-K12 |", "| GF-K01 |"),
        ):
            with self.subTest(field=original):
                candidate = self.doc.replace(original, mutated, 1)
                self.assertNotEqual(candidate, self.doc)
                with self.assertRaises(ValueError):
                    parse_matrix(candidate)

    def test_rows_after_blank_line_cannot_escape_cost_validation(self):
        # Regression for the real CodeRabbit finding on PR #484: a second
        # cost row after the blank line following GF-K12 was silently ignored.
        boundary = "\n\n## Evidencia mínima para sustituir UNKNOWN"
        extra_row = (
            "| GF-K13 | Proveedores | Rubro adicional no inventariado | "
            "mensajes/mes | UNKNOWN | $12/mes | UNKNOWN | " + SOURCE
            + " | " + UNKNOWN_SOURCE
            + " | No existe medición ni cotización acreditada | UNKNOWN |"
        )
        candidate = self.doc.replace(boundary, "\n\n" + extra_row + boundary, 1)
        self.assertNotEqual(candidate, self.doc)
        with self.assertRaisesRegex(ValueError, "fuera de la matriz"):
            parse_matrix(candidate)
        # Noncanonical IDs can hide an invented price just as effectively.
        for extra_id in ("COST-X13", "GF-K14"):
            with self.subTest(extra_id=extra_id):
                variant = candidate.replace("| GF-K13 |", f"| {extra_id} |", 1)
                self.assertNotEqual(variant, candidate)
                with self.assertRaisesRegex(ValueError, "fuera de la matriz"):
                    parse_matrix(variant)

    def test_cost_item_names_are_pinned_to_ids(self):
        # A valid GF-K01/Storage row must not silently replace its cost item.
        baseline = "| GF-K01 | Storage | Objetos multimedia del piloto |"
        forged = "| GF-K01 | Storage | Rubro ajeno no inventariado |"
        candidate = self.doc.replace(baseline, forged, 1)
        self.assertNotEqual(candidate, self.doc)
        with self.assertRaisesRegex(ValueError, "identidad de rubro alterada"):
            parse_matrix(candidate)

    def test_unknown_never_becomes_zero_or_free(self):
        rows = parse_matrix(self.doc)
        self.assertTrue(all(r[f] == "UNKNOWN" for r in rows
                            for f in ("Cantidad", "Tarifa", "Subtotal mensual")))
        for value in ("0", "0 USD", "gratis", "incluido", "$12", "READY"):
            with self.subTest(value=value):
                baseline = "| UNKNOWN | UNKNOWN | UNKNOWN | " + SOURCE
                changed = "| UNKNOWN | " + value + " | UNKNOWN | " + SOURCE
                candidate = self.doc.replace(baseline, changed, 1)
                self.assertNotEqual(candidate, self.doc)
                with self.assertRaisesRegex(ValueError, "no pueden"):
                    parse_matrix(candidate)

    def test_no_final_prices_or_fictitious_positive_readiness(self):
        parse_matrix(self.doc)
        verify_decisions(self.doc)
        for label, expected in STATUS.items():
            with self.subTest(label=label):
                candidate = self.doc.replace(
                    f"**{label}:** {expected}", f"**{label}:** APROBADO.", 1
                )
                with self.assertRaises(ValueError):
                    verify_decisions(candidate)
                candidate = self.doc.replace(
                    f"**{label}:** {expected}",
                    f"**{label}:** {expected}\n**{label}:** GO", 1,
                )
                with self.assertRaises(ValueError):
                    verify_decisions(candidate)
        # Plain/quoted declarations are not harmless prose, and must
        # be rejected even when the canonical Markdown line remains present.
        buy = "**Decisión de compra:** " + STATUS["Decisión de compra"]
        for forged in ("Decisión de compra: AUTORIZADA.",
                       "> Activación / go-live: AUTORIZADO.",
                       "### Precios finales: $12/mes."):
            with self.subTest(forged=forged):
                candidate = self.doc.replace(buy, buy + "\n" + forged, 1)
                self.assertNotEqual(candidate, self.doc)
                with self.assertRaisesRegex(ValueError, "decisión ambigua"):
                    verify_decisions(candidate)
        # Prose mentions may document hazards without creating a decision.
        prose = ("El ejemplo menciona Decisión de compra: AUTORIZADA "
                 "para explicar una prohibición.")
        candidate = self.doc.replace("## Método sin suposiciones monetarias",
                                     prose + "\n\n## Método sin suposiciones monetarias", 1)
        verify_decisions(candidate)

        with self.assertRaisesRegex(ValueError, "NO-GO"):
            verify_decisions(self.doc + "\n**Resultado:** GO\n")


if __name__ == "__main__":
    unittest.main()
