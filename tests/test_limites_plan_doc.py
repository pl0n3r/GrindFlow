"""Contrato documental offline de GrindFlow #650: no acreditar cuotas inventadas."""
from __future__ import annotations

import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/launch/plan-limits.md"
SOURCE = ROOT / "docs/lista-salida-produccion.md"
ROW_NAME = "Límites por plan: storage, ancho de banda, colas y llamadas API"
RESOURCES = {
    "storage", "tamaño de archivo", "publicaciones", "conectores",
    "colas", "ancho de banda", "llamadas API",
}
SCENARIOS = {"A", "B", "C"}
HEADERS = [
    "Escenario", "Recurso", "Unidad de cuota comercial", "Límite comercial",
    "Fuente de la cuota", "Métrica propuesta de control", "Estado de medición",
]


def parse_table(document: str) -> list[list[str]]:
    rows = []
    within = False
    for line in document.splitlines():
        if line.startswith("| Escenario |"):
            within = True
        elif within and not line.startswith("|"):
            break
        if within and line.startswith("|"):
            rows.append([cell.strip() for cell in line.strip().strip("|").split("|")])
    if len(rows) < 3 or rows[0] != HEADERS:
        raise AssertionError("encabezado o tabla de escenarios ausente")
    if any(cell != "---" for cell in rows[1]):
        raise AssertionError("separador de tabla ausente")
    if any(len(row) != len(HEADERS) for row in rows):
        raise AssertionError("columnas incompletas")
    # Fail closed for any Markdown table row outside this single canonical table,
    # including unapproved scenario names (D, E, "Escenario D", etc.). Restricting
    # this detector to A/B/C/D would let a new scenario silently escape validation.
    candidate_rows = [line for line in document.splitlines()
                      if line.lstrip().startswith("|")]
    if len(candidate_rows) != len(rows):
        raise AssertionError("fila tabular fuera de la tabla canónica")
    return rows[2:]


def check_table(document: str) -> None:
    data = parse_table(document)
    expected = {(scenario, resource) for scenario in SCENARIOS for resource in RESOURCES}
    actual = [(row[0], row[1]) for row in data]
    if len(data) != len(expected) or set(actual) != expected or len(set(actual)) != len(actual):
        raise AssertionError("la tabla debe contener exactamente 3 x 7 cuotas, sin duplicados")
    for row in data:
        if row[2] == "" or row[5] == "":
            raise AssertionError("falta unidad o métrica de instrumentación")
        # Unit and metric columns cannot smuggle numeric commercial limits while
        # the authoritative limit/source columns truthfully still say UNKNOWN.
        # This draft does not have any approved numeric commercial thresholds.
        if re.search(r"\d", row[2]) or re.search(r"\d", row[5]):
            raise AssertionError("cifra comercial incrustada en unidad o métrica")
        if row[3] != "UNKNOWN" or row[4] != "UNKNOWN":
            raise AssertionError("cuota o fuente comercial inventada")
        if row[6] != "PROPUESTA / NO ENSAYADO":
            raise AssertionError("se afirma medición no observada")


def check_honesty(document: str) -> None:
    # A valid declaration cannot excuse a second, conflicting one. Examine the
    # entire document, not just the first authoritative-looking metadata block.
    # Accept either Markdown emphasis or a plain label; reject *duplicates*,
    # including a harmless-looking second UNKNOWN (ambiguous authority).
    declarations = {
        "Estado": "PROPUESTA / NO ENSAYADO.",
        "Decisión de salida": "NO-GO.",
        "Autoridad comercial": "DEL_DUEÑO.",
        "Disponibilidad comercial": "UNKNOWN.",
        "Validación de planes y aprobación del operador": "UNKNOWN.",
    }
    for label, expected in declarations.items():
        marker = re.compile(
            r"(?im)(?:^|[ \t])(?:\*\*)?" + re.escape(label)
            + r"(?:\*\*)?:[ \t]*(?:\*\*)?"
        )
        found = list(marker.finditer(document))
        if len(found) != 1:
            raise AssertionError(f"declaración ausente o duplicada: {label}")
        value = document[found[0].end():].lstrip()
        if not value.startswith(expected) or (len(value) > len(expected) and
                                               not value[len(expected)].isspace()):
            raise AssertionError(f"declaración cambiada sin autoridad: {label}")
    # Detect affirmative metadata labels, including inserted contradictory declarations.
    disallowed = re.compile(
        r"(?im)^\s*\*{0,2}(?:Disponibilidad(?: comercial)?|Estado(?: comercial)?|"
        r"Decisi[oó]n de salida|Validaci[oó]n de planes y aprobaci[oó]n del operador|"
        r"Oferta comercial|Resultado|Aprobaci[oó]n|Autorizaci[oó]n|"
        r"Activaci[oó]n|Lanzamiento(?: comercial)?|Publicaci[oó]n|"
        r"Go[- ]?live|Comercializaci[oó]n|Disponible para venta)"
        r"\*{0,2}\s*:\*{0,2}\s*\*{0,2}"
        r"(?:GO|LIVE|VIGENTE|APROBADO|APROBADA|AUTORIZADO|AUTORIZADA|"
        r"HABILITADO|HABILITADA|ACTIVO|ACTIVA|PUBLICADO|PUBLICADA|"
        r"DISPONIBLE|COMERCIALIZADO|COMERCIALIZADA|LISTO|LISTA)\b"
    )
    if disallowed.search(document):
        raise AssertionError("declaración afirmativa sin autoridad")
    # An invented price/quota outside the canonical 3x7 table must not bypass
    # the UNKNOWN check merely because it uses a different Markdown label or
    # a natural-language sentence. The documented 8 MB / 2 GiB are *technical*
    # transport limits, not commercial quotas; keep those explicit facts valid.
    numeric_commercial_claim = re.compile(
        r"(?im)^\s*(?:[-*]\s*)?\*{0,2}"
        r"(?:precio|tarifa|cuota|cota|l[ií]mite)[^\n:]{0,100}"
        r"\*{0,2}\s*:\*{0,2}\s*[$€£]?\s*\d"
        r"|^\s*\*{0,2}plan\s+[ABC]\*{0,2}\s*:\*{0,2}\s*[$€£]?\s*\d"
        r"|^\s*(?:el|la)\s+(?:precio|tarifa|cuota|cota|l[ií]mite)\b"
        r"[^\n]{0,100}\b(?:es|ser[aá]|cuesta|vale)\s*[$€£]?\s*\d"
    )
    if numeric_commercial_claim.search(document):
        raise AssertionError("cifra comercial no aprobada fuera de la matriz")
    check_table(document)


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = DOC.read_text(encoding="utf-8")

    @staticmethod
    def _check_sections(document: str) -> None:
        if not re.search(r"(?m)^# Límites por plan\b", document):
            raise AssertionError("título ausente")
        for heading in (
            "## Fuentes y alcance", "## Escenarios comparables",
            "## Condiciones para sustituir UNKNOWN", "## Reversión",
        ):
            count = len(re.findall(r"(?m)^" + re.escape(heading) + r"(?:\b|$)", document))
            if count != 1:
                raise AssertionError(f"sección ausente o duplicada: {heading}")
        check_table(document)

    def test_existe_y_tiene_secciones(self) -> None:
        self._check_sections(self.doc)

    def test_sin_datos_sensibles(self) -> None:
        self.assertNotRegex(self.doc, r"(?i)-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(self.doc, r"(?i)\b(?:ghp_|github_pat_|sk_live_|AKIA)[a-z0-9]{10,}")
        self.assertNotRegex(self.doc, r"(?i)\b(?:password|access_token|client_secret|api_key)\s*[:=]\s*[\"']?[a-z0-9+/_-]{10,}")
        self.assertNotRegex(self.doc, r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b")
        self.assertIn("NO-GO", self.doc)
        self.assertIn("datos reales", self.doc)

    def test_estado_honesto(self) -> None:
        check_honesty(self.doc)
        for addition in (
            "\n**Disponibilidad:** VIGENTE\n",
            "\n**Resultado:** GO\n",
            "\n**Aprobación:** APROBADO\n",
            "\n**Autorización:** AUTORIZADO\n",
            "\n**Activación:** LIVE\n",
            "\n**Lanzamiento comercial:** HABILITADO\n",
            "\n**Estado comercial:** HABILITADO\n",
            "\n**Disponibilidad:** ACTIVO\n",
            "\n**Oferta comercial:** PUBLICADO\n",
            "\n**Go-live:** APROBADO\n",
            "\n**Comercialización:** HABILITADA\n",
            "\n**Disponible para venta:** GO\n",
            "\n**Estado:** APROBADO\n",
            "\n**Decisión de salida:** GO\n",
            "\n**Validación de planes y aprobación del operador:** APROBADO\n",
            "\n**Lanzamiento:** DISPONIBLE\n",
            "\n**Precio mensual:** $25\n",
            "\n**Cuota de storage del Plan A:** 10 GiB\n",
            "\n**Plan A:** 25 GiB de storage\n",
            "\n**Límite comercial:** 100 publicaciones/mes\n",
            "\n**Tarifa mensual:** 20 USD\n",
            "\nEl precio mensual del plan A es $25.\n",
            "\nLa cuota del plan B es 100 GiB.\n",
            # Una declaración válida no puede ocultar una segunda autoridad.
            "\n**Autoridad comercial:** APROBADO\n",
            "\n**Autoridad comercial:** NO APLICA\n",
            "\n**Disponibilidad comercial:** UNKNOWN\n",
            "\n**Validación de planes y aprobación del operador:** NO ENSAYADO\n",
            "\n**Decisión de salida:** NO-GO\n",
            "\n**Estado:** PROPUESTA / NO ENSAYADO\n",
            # Tampoco se puede esquivar el detector omitiendo las negritas.
            "\nAutoridad comercial: APROBADA\n",
            "\nEstado: PROPUESTA / NO ENSAYADO\n",
        ):
            with self.subTest(addition=addition.strip()[:45]), self.assertRaises(AssertionError):
                check_honesty(self.doc + addition)
        # Una fila intrusa, dentro o fuera de la tabla, no debe pasar por omisión.
        marker = "\n\n## Condiciones para sustituir UNKNOWN"
        for alien in (
            "| D | storage | GiB por tenant | UNKNOWN | UNKNOWN | medir | PROPUESTA / NO ENSAYADO |",
            "| E | storage | GiB por tenant | 10 GiB | UNKNOWN | medir | ACTIVA |",
            "| Escenario D | storage | GiB por tenant | 20 GiB | UNKNOWN | medir | ACTIVA |",
        ):
            for mutated in (
                self.doc.replace(marker, "\n" + alien + marker, 1),
                self.doc + "\n" + alien + "\n",
            ):
                self.assertNotEqual(mutated, self.doc)
                with self.subTest(fila=alien, posicion="tabla" if marker in mutated else "final"):
                    with self.assertRaises(AssertionError):
                        check_honesty(mutated)
        for heading in (
            "## Fuentes y alcance",
            "## Escenarios comparables: todas las cuotas comerciales pendientes",
            "## Condiciones para sustituir UNKNOWN",
            "## Reversión",
        ):
            with self.subTest(heading=heading), self.assertRaises(AssertionError):
                DocContractTests._check_sections(self.doc + "\n" + heading)
        for removed, replacement in (
            ("| A | storage |", "| A | publicación errónea |"),
            ("| C | llamadas API |", "| C | storage |"),
            ("| UNKNOWN | UNKNOWN |", "| 100 GiB | UNKNOWN |"),
            # Numeric commercial thresholds in supposedly descriptive columns
            # must not bypass the UNKNOWN-only contract of the canonical table.
            ("GiB por tenant", "100 GiB por tenant"),
            ("bytes retenidos por organización, versiones y backups",
             "bytes retenidos por organización, cuota comercial 100 GiB"),
        ):
            mutated = self.doc.replace(removed, replacement, 1)
            if mutated != self.doc:
                with self.subTest(replaced=removed), self.assertRaises(AssertionError):
                    check_honesty(mutated)

    def test_enlaza_fila_de_salida(self) -> None:
        self.assertIn("[Lista de salida a producción](../lista-salida-produccion.md)", self.doc)
        self.assertIn(ROW_NAME, self.doc)
        source = SOURCE.read_text(encoding="utf-8")
        found = [line for line in source.splitlines() if line.startswith("|") and ROW_NAME in line]
        self.assertEqual(len(found), 1, "la fila normativa debe existir exactamente una vez")
        parts = [p.strip() for p in found[0].strip().strip("|").split("|")]
        self.assertGreaterEqual(len(parts), 4)
        self.assertEqual(parts[2], "DEL_DUEÑO", "no convertir una decisión humana en completada")
        self.assertIn("NO-GO", self.doc)


if __name__ == "__main__":
    unittest.main()
