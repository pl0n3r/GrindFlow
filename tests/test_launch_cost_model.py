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
SOURCE = "[#351](https://github.com/pl0n3r/GrindFlow/issues/351)"
UNKNOWN_SOURCE = "UNKNOWN (sin medición autenticada)"
STATUS = {
    "Estado de costos": "UNKNOWN — sin consumos ni tarifas autenticados.",
    "Precios finales": "NO DEFINIDOS.",
    "Decisión de compra": "NO AUTORIZADA.",
    "Activación / go-live": "NO AUTORIZADO.",
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
    ids = [r["ID"] for r in records]
    if len(ids) != len(REQUIRED) or set(ids) != set(REQUIRED) or len(set(ids)) != len(ids):
        raise ValueError("identidad de rubros incompleta o duplicada")
    for row in records:
        if row["Área"] != REQUIRED[row["ID"]]:
            raise ValueError("clasificación de área alterada")
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
        occurrences = [
            line.strip() for line in text.splitlines()
            if re.search(re.escape(label) + r"\s*:", line, re.IGNORECASE)
            and line.lstrip().startswith("**")
        ]
        if occurrences != [declaration]:
            raise ValueError("decisión ambigua, duplicada o falsamente aprobada")
    if not text.endswith("**Resultado:** UNKNOWN / NO-GO documental. No hay precio definitivo, contrato autorizado, gasto, transferencia de datos ni activación de infraestructura.\n"):
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
        with self.assertRaisesRegex(ValueError, "NO-GO"):
            verify_decisions(self.doc + "\n**Resultado:** GO\n")


if __name__ == "__main__":
    unittest.main()
