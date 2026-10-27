#!/usr/bin/env python3
"""GF351/#683: contrato offline fail-closed del paquete comercial pre-live.

Solo lee Markdown versionado; no ejecuta compras, HTTP, lectura de PII ni despliegues.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs/launch/commercial-readiness.md"

HEADINGS = (
    "Inventario de evidencias obligatorias",
    "Economía y costos del piloto",
    "Métricas de piloto",
    "Onboarding y soporte",
    "Riesgos y motivos NO-GO",
    "Registro mínimo de evidencia futura",
    "Puertas independientes",
)

FIELDS = ("ID", "Criterio", "Fuente normativa", "Evidencia operativa",
          "Fecha observada UTC", "Owner por rol", "Estado")
COST_FIELDS = ("ID", "Rubro", "Unidad", "Cantidad", "Tarifa",
               "Moneda", "Total", "Evidencia fechada")

# Las fuentes son documentos de diseño, NUNCA prueba operativa.
ISSUE271 = "[#271](https://github.com/pl0n3r/GrindFlow/issues/271)"
ISSUE351 = "[#351](https://github.com/pl0n3r/GrindFlow/issues/351)"
ISSUE356 = "[#356](https://github.com/pl0n3r/GrindFlow/issues/356)"
ISSUE311 = "[#311](https://github.com/pl0n3r/GrindFlow/issues/311)"
COSTDOC = "[cost-model.md](cost-model.md)"
EXPECTED = {
    "GF-R01": (ISSUE271, "Producto"),
    "GF-R02": (ISSUE271, "Analítica y QA"),
    "GF-R03": (COSTDOC, "Infraestructura y Finanzas"),
    "GF-R04": (COSTDOC, "Infraestructura y Finanzas"),
    "GF-R05": (COSTDOC, "Finanzas y Operaciones"),
    "GF-R06": ("[onboarding-support.md](onboarding-support.md)", "Producto y Soporte"),
    "GF-R07": ("[support-sla-draft.md](support-sla-draft.md)", "Soporte y Operaciones"),
    "GF-R08": (ISSUE356, "Seguridad y QA"),
    "GF-R09": (ISSUE311, "SRE"),
    "GF-R10": (ISSUE356, "QA y SRE"),
    "GF-R11": (ISSUE351, "Producto y Propietario"),
    "GF-R12": ("[commercial-readiness-checklist.md](commercial-readiness-checklist.md)",
               "Propietario"),
}
COSTS = {
    "GF-K01": "Storage, versiones y backup",
    "GF-K02": "Egreso, CDN y transferencias",
    "GF-K03": "Jobs, reintentos y colas",
    "GF-K04": "Transcodificación y procesamiento",
    "GF-K05": "Hosting, base de datos, observabilidad y soporte",
}
DECISIONS = {
    "Estado del paquete": "UNKNOWN.",
    "Corte de evidencia UTC": "UNKNOWN (sin observación autenticada).",
    "Resultado del paquete": "UNKNOWN / NO-GO.",
    "Decisión go/no-go comercial": "NO-GO (sin evidencias autenticadas).",
    "Autorización go-live": "NO AUTORIZADO.",
    "Precios definitivos": "NO DEFINIDOS.",
    "Costos totales": "UNKNOWN.",
}


def section(markdown: str, heading: str) -> str:
    """Section strictly limited by the next level-2 heading."""
    start = "## " + heading
    lines = markdown.splitlines()
    if lines.count(start) != 1:
        raise ValueError("Sección ausente o duplicada: " + heading)
    offset = lines.index(start) + 1
    end = next((i for i in range(offset, len(lines))
                if lines[i].startswith("## ")), len(lines))
    contents = "\n".join(lines[offset:end])
    if not contents.strip():
        raise ValueError("Sección vacía: " + heading)
    return contents


def parse_table(md: str, heading: str, columns: tuple[str, ...]) -> list[dict[str, str]]:
    contents = section(md, heading)
    table_lines = [l for l in contents.splitlines() if l.lstrip().startswith("|")]
    if len(table_lines) < 3:
        raise ValueError("Matriz ausente: " + heading)
    cells = lambda line: [value.strip() for value in line.strip().strip("|").split("|")]
    header = cells(table_lines[0])
    if header != list(columns):
        raise ValueError("Columnas alteradas o duplicadas")
    sep = cells(table_lines[1])
    if len(sep) != len(header) or not all(
        re.fullmatch(r":?-{3,}:?", part) for part in sep
    ):
        raise ValueError("Separador de tabla inválido")
    out = []
    for line in table_lines[2:]:
        row = cells(line)
        if len(row) != len(header) or not all(row):
            raise ValueError("Fila incompleta/ambigua")
        out.append(dict(zip(header, row)))
    return out


def _exact_ids(rows: list[dict[str, str]], expected: set[str]) -> None:
    ids = [row["ID"] for row in rows]
    if len(ids) != len(expected) or len(set(ids)) != len(ids) or set(ids) != expected:
        raise ValueError("IDs requeridos ausentes, inesperados o duplicados")


def validate_package(markdown: str) -> None:
    if not markdown.startswith("# Paquete de readiness comercial — GrindFlow (pre-live)\n"):
        raise ValueError("Identidad de paquete incorrecta")
    if not markdown.endswith("\n"):
        raise ValueError("Truncamiento del archivo")
    for label, value in DECISIONS.items():
        # No hidden alternative status, including plain and Markdown versions.
        marker = re.compile(r"^\s*(?:[*_]{0,2})" + re.escape(label)
                            + r"\s*:(?:[*_]{0,2})", flags=re.M | re.I)
        declared = [line.strip() for line in markdown.splitlines()
                    if marker.match(line)]
        if declared != ["**" + label + ":** " + value]:
            raise ValueError("Declaración de autoridad duplicada o falsa: " + label)

    for name in HEADINGS:
        section(markdown, name)
    rows = parse_table(markdown, HEADINGS[0], FIELDS)
    _exact_ids(rows, set(EXPECTED))
    for row in rows:
        src, owner = EXPECTED[row["ID"]]
        if row["Fuente normativa"] != src or row["Owner por rol"] != owner:
            raise ValueError("Procedencia u owner alterados")
        if not row["Criterio"] or row["Criterio"].upper() in {"UNKNOWN", "GO", "READY"}:
            raise ValueError("Criterio vacío o inventado")
        if row["Evidencia operativa"] != "NO ADJUNTA":
            raise ValueError("Evidencia externa nunca autentificada por un test offline")
        if row["Fecha observada UTC"] != "UNKNOWN":
            raise ValueError("Fecha sin recibo autenticado")
        if row["Estado"] != "UNKNOWN":
            raise ValueError("Readiness afirmada sin evidencia")

    costs = parse_table(markdown, HEADINGS[1], COST_FIELDS)
    _exact_ids(costs, set(COSTS))
    for row in costs:
        if row["Rubro"] != COSTS[row["ID"]]:
            raise ValueError("Rubro alterado")
        if row["Unidad"] not in {"GiB·mes", "GiB/mes", "trabajos/mes",
                                  "minutos CPU/mes", "horas/mes"}:
            raise ValueError("Unidad desconocida")
        if row["Evidencia fechada"] != "NO ADJUNTA":
            raise ValueError("Se fingió evidencia económica")
        for field in ("Cantidad", "Tarifa", "Moneda", "Total"):
            if row[field] != "UNKNOWN":
                raise ValueError("UNKNOWN no puede convertirse en cero o presupuesto")

    operational = section(markdown, "Onboarding y soporte")
    for token in ("**Independiente:**", "**Studio:**", "**Soporte:**", "**SLA:**",
                  "persona asignada UNKNOWN", "PROPUESTA_NO_APROBADA"):
        if token not in operational:
            raise ValueError("Falta el flujo de onboarding, soporte u owner")

    if operational.count("persona asignada UNKNOWN") != 2:
        raise ValueError("No se permite asignar responsables sin evidencia")

    risk = section(markdown, "Riesgos y motivos NO-GO")
    for index in range(1, 8):
        if risk.count("**GF-N" + f"{index:02d}" + ":**") != 1:
            raise ValueError("Riesgo obligatorio duplicado o ausente")
    if "NO-GO" not in risk:
        raise ValueError("Riesgos no condicionan salida")

    evidence = section(markdown, "Registro mínimo de evidencia futura")
    for word in ("URI", "UTC", "SHA", "responsable humano", "vigencia", "PII"):
        if word not in evidence:
            raise ValueError("Recibo insuficiente: " + word)
    if "no se afirma que hayan participado" not in markdown:
        raise ValueError("El piloto se presenta falsamente como completado")
    if "no equivale a 0" not in markdown:
        raise ValueError("Costo UNKNOWN interpretado como cero")
    gates = section(markdown, "Puertas independientes")
    for token in ("**Evaluación go/no-go comercial:**", "**Versión 1.0.0:**",
                  "**Go-live/producción:**", "NO AUTORIZADO"):
        if token not in gates:
            raise ValueError("Go/no-go, promoción y go-live no están separados")


class CommercialReadinessContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.original = DOC.read_text(encoding="utf-8")

    def test_complete_synthetic_readiness_is_no_go(self) -> None:
        validate_package(self.original)
        self.assertEqual(len(parse_table(self.original, HEADINGS[0], FIELDS)), 12)
        self.assertEqual(len(parse_table(self.original, HEADINGS[1], COST_FIELDS)), 5)
        # All references to neighbouring templates resolve within the repository.
        for filename in ("cost-model.md", "commercial-readiness-checklist.md",
                         "onboarding-support.md", "support-sla-draft.md"):
            self.assertTrue((DOC.parent / filename).is_file(), filename)

    def test_forged_ready_receipts_and_duplicate_criteria_fail_closed(self) -> None:
        mutations = (
            ("| NO ADJUNTA | UNKNOWN | Producto | UNKNOWN |",
             "| fake:receipt | UNKNOWN | Producto | READY |"),
            ("| NO ADJUNTA | UNKNOWN | Producto | UNKNOWN |",
             "| NO ADJUNTA | 2026-10-27T20:00:00Z | Producto | UNKNOWN |"),
            ("| GF-R01 |", "| GF-R02 |"),
            (ISSUE271, "[#271](https://example.invalid/pilot)"),
            ("| GF-R11 |", "| GF-R99 |"),
            ("## Inventario de evidencias obligatorias", "## Inventario incompleto"),
        )
        for original, replacement in mutations:
            with self.subTest(replacement=replacement):
                self.assertIn(original, self.original)
                with self.assertRaises(ValueError):
                    validate_package(self.original.replace(original, replacement, 1))

    def test_missing_price_and_owner_are_not_implicitly_zero(self) -> None:
        mutations = (
            ("| GiB·mes | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |",
             "| GiB·mes | 0 | UNKNOWN | UNKNOWN | 0 | NO ADJUNTA |"),
            ("| GiB·mes | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |",
             "| GiB·mes | UNKNOWN | 120 | COP | 120 | NO ADJUNTA |"),
            ("| UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |",
             "| UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |"),
            ("persona asignada UNKNOWN", "persona asignada pl0n3r"),
            ("**GF-N07:**", "**GF-NXX:**"),
        )
        for original, replacement in mutations:
            if original == replacement:
                continue
            with self.subTest(replacement=replacement):
                self.assertIn(original, self.original)
                with self.assertRaises(ValueError):
                    validate_package(self.original.replace(original, replacement, 1))

    def test_decisions_cannot_be_redeclared_as_go_live_or_prices(self) -> None:
        mutations = (
            ("**Decisión go/no-go comercial:** NO-GO (sin evidencias autenticadas).",
             "**Decisión go/no-go comercial:** GO."),
            ("**Autorización go-live:** NO AUTORIZADO.",
             "**Autorización go-live:** AUTORIZADO."),
            ("**Precios definitivos:** NO DEFINIDOS.",
             "**Precios definitivos:** $0."),
            ("**Costos totales:** UNKNOWN.", "**Costos totales:** 0."),
            ("**Resultado del paquete:** UNKNOWN / NO-GO.",
             "**Resultado del paquete:** READY."),
        )
        for original, replacement in mutations:
            with self.subTest(replacement=replacement):
                self.assertIn(original, self.original)
                with self.assertRaises(ValueError):
                    validate_package(self.original.replace(original, replacement, 1))
        forged = self.original + "\nAutorización go-live: AUTORIZADO.\n"
        with self.assertRaises(ValueError):
            validate_package(forged)


    def test_future_dated_header_and_missing_sla_cannot_authorize_sale(self) -> None:
        for old, replacement in (
            ("**Corte de evidencia UTC:** UNKNOWN (sin observación autenticada).",
             "**Corte de evidencia UTC:** 2026-10-27T20:00:00Z."),
            ("**SLA:**", "**Acuerdo:**"),
            ("**Decisión go/no-go comercial:** NO-GO (sin evidencias autenticadas).",
             "**Decisión go/no-go comercial:** GO."),
        ):
            with self.subTest(replacement=replacement):
                self.assertIn(old, self.original)
                with self.assertRaises(ValueError):
                    validate_package(self.original.replace(old, replacement, 1))


if __name__ == "__main__":
    unittest.main()
