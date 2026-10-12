"""AC-01..03: offline structure and fail-closed checks for launch onboarding."""
import re
import unittest
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs/launch/onboarding-support.md"

GO_NO_GO_HEADING = "Puerta go/no-go de preparación (NO es go-live)"
GO_NO_GO_CANONICAL = "**Resultado go/no-go:** NO-GO mientras cualquiera de los siete criterios obligatorios esté UNKNOWN, falte uno, aparezca duplicado, existan incidentes P0/P1 abiertos o no haya evidencia fresca. UNKNOWN nunca significa cero, listo ni aprobado. El contrato offline rechaza cualquier fila `EVIDENCIADO`: autenticar evidencia, fecha y autoridad requiere una puerta independiente, no editar este documento. Este checklist no dispara publicaciones."
GO_NO_GO_LABEL = re.compile(
    r"resultado[ \t]+go[ \t]*/[ \t]*no-go[ \t]*:", re.IGNORECASE
)

def valid_go_no_go_decision(markdown: str) -> bool:
    """A negative readiness decision is unique, canonical and not forgeable by text."""
    gate = section(markdown, GO_NO_GO_HEADING)
    if not gate or len(GO_NO_GO_LABEL.findall(markdown)) != 1:
        return False
    declarations = [line.strip() for line in gate.splitlines()
                    if GO_NO_GO_LABEL.search(line)]
    return declarations == [GO_NO_GO_CANONICAL]

MANDATORY = {
    "Piloto con resultados verificables #271",
    "Prueba de recovery/rollback #311",
    "Seguridad, permisos y ausencia P0/P1",
    "Canales y personal de soporte operativos",
    "SLAs ratificados y cobertura medible",
    "Costos de storage, colas y operación #351",
    "Evidencia legal/privacidad y proveedores",
}


def section(markdown: str, title: str) -> str:
    header = "## " + title
    lines = markdown.splitlines()
    # A duplicate gate heading can hide a contradictory second decision.
    if lines.count(header) != 1:
        return ""
    index = lines.index(header) + 1
    end = next((i for i in range(index, len(lines)) if lines[i].startswith("## ")), len(lines))
    return "\n".join(lines[index:end])


def table(markdown: str, heading: str) -> list[dict[str, str]]:
    contents = section(markdown, heading)
    lines = [line for line in contents.splitlines() if line.strip().startswith("|")]
    if len(lines) < 3:
        return []
    cells = lambda line: [cell.strip() for cell in line.strip().strip("|").split("|")]
    headers = cells(lines[0])
    if len(set(headers)) != len(headers):
        return []
    # Validate Markdown's separator, rather than silently ignoring any row.
    # A broken table is not evidence of a valid readiness checklist.
    separator = cells(lines[1])
    if len(separator) != len(headers) or not all(
        re.fullmatch(r":?-{3,}:?", item) for item in separator
    ):
        return []
    rows = [cells(line) for line in lines[2:]]
    if not all(len(row) == len(headers) for row in rows):
        return []
    return [dict(zip(headers, row)) for row in rows]


def valid_readiness(markdown: str) -> bool:
    rows = table(markdown, "Puerta go/no-go de preparación (NO es go-live)")
    if len(rows) != len(MANDATORY) or {row.get("Criterio") for row in rows} != MANDATORY:
        return False
    for row in rows:
        if not all(row.get(col) for col in ("Criterio", "Evidencia", "Estado", "Owner por rol", "Frescura")):
            return False
        # Un parser local no puede autenticar fuentes, fechas ni autoridades.
        if row["Estado"] not in ("UNKNOWN", "NO-GO"):
            return False
    return valid_go_no_go_decision(markdown)


LIVE_HEADING = "Autorización de live — decisión humana posterior y separada"
LIVE_STATUS = "**Estado actual de live:** NO AUTORIZADO."
LIVE_LABEL = re.compile(r"estado[ \t]+actual[ \t]+de[ \t]+live[ \t]*:", re.IGNORECASE)


def valid_live_authorization(markdown: str) -> bool:
    """One canonical, unauthorised live status; reject aliases and hidden duplicates."""
    live = section(markdown, LIVE_HEADING)
    if not live or len(LIVE_LABEL.findall(markdown)) != 1:
        return False
    status_lines = [line.strip() for line in live.splitlines() if LIVE_LABEL.search(line)]
    return (status_lines == [LIVE_STATUS]
            and "solo una instrucción explícita del propietario" in live
            and "#356" in live)


class LaunchOnboardingSupportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.content = DOC.read_text(encoding="utf-8")

    def test_onboarding_and_support_have_owner_and_process(self):
        for title in ("Onboarding: independiente", "Onboarding: Studio",
                      "Soporte: responsables, canales y proceso"):
            content = section(self.content, title)
            self.assertIn("Owner de proceso:", content, title)
            self.assertIn("Proceso", content, title)
            self.assertGreaterEqual(sum(line.lstrip().startswith(f"{n}.") for line in content.splitlines()
                                        for n in range(1, 6)), 5, title)
        self.assertIn("canal de recepción = UNKNOWN", self.content)
        self.assertIn("persona asignada: UNKNOWN", self.content)

    def test_go_no_go_is_separate_from_go_live(self):
        gate = section(self.content, "Puerta go/no-go de preparación (NO es go-live)")
        live = section(self.content, "Autorización de live — decisión humana posterior y separada")
        self.assertTrue(valid_readiness(self.content))
        self.assertIn("NO-GO", gate)
        self.assertIn("UNKNOWN nunca significa cero", gate)
        self.assertIn("solo una instrucción explícita del propietario", live)
        self.assertIn("NO AUTORIZADO", live)
        self.assertIn("#356", live)
        self.assertTrue(valid_live_authorization(self.content))
        mutated = self.content.replace("| Piloto con resultados verificables #271 | UNKNOWN | UNKNOWN |",
                                       "| Piloto con resultados verificables #271 | UNKNOWN | EVIDENCIADO |")
        self.assertNotEqual(mutated, self.content)
        self.assertFalse(valid_readiness(mutated))
        stale = self.content.replace(
            "| Piloto con resultados verificables #271 | UNKNOWN | UNKNOWN | Producto | UNKNOWN |",
            "| Piloto con resultados verificables #271 | Evidencia piloto #271 | EVIDENCIADO | Producto | UNKNOWN |",
        )
        self.assertNotEqual(stale, self.content)
        self.assertFalse(valid_readiness(stale), "La frescura UNKNOWN nunca acredita el go")
        missing_owner = self.content.replace(
            "| Piloto con resultados verificables #271 | UNKNOWN | UNKNOWN | Producto | UNKNOWN |",
            "| Piloto con resultados verificables #271 | Evidencia piloto #271 | EVIDENCIADO | UNKNOWN | Verificado el 2026-10-11 |",
        )
        self.assertNotEqual(missing_owner, self.content)
        self.assertFalse(valid_readiness(missing_owner), "El owner UNKNOWN tampoco es válido")
        forged = self.content.replace(
            "| Piloto con resultados verificables #271 | UNKNOWN | UNKNOWN | Producto | UNKNOWN |",
            "| Piloto con resultados verificables #271 | Acta supuestamente aprobada | EVIDENCIADO | Producto | 2026-10-11T11:00:00Z |",
        )
        self.assertNotEqual(forged, self.content)
        self.assertFalse(valid_readiness(forged), "Una cadena de texto no autentica evidencia")
        mandatory_row = "| Seguridad, permisos y ausencia P0/P1 | UNKNOWN | UNKNOWN | Seguridad | UNKNOWN |"
        self.assertIn(mandatory_row, self.content)
        removed = self.content.replace(mandatory_row + "\n", "", 1)
        self.assertFalse(valid_readiness(removed), "Un criterio crítico no puede omitirse")
        duplicated = self.content.replace(mandatory_row, mandatory_row + "\n" + mandatory_row, 1)
        self.assertFalse(valid_readiness(duplicated), "Los criterios duplicados no acreditan completitud")
        # A malformed Markdown separator must not pass as a structured table.
        canonical_header = "| Criterio | Evidencia | Estado | Owner por rol | Frescura |"
        separator = "| --- | --- | --- | --- | --- |"
        self.assertIn(canonical_header + "\n" + separator, self.content)
        broken_separator = self.content.replace(
            canonical_header + "\n" + separator,
            canonical_header + "\n| invalid | invalid | invalid | invalid | invalid |",
            1,
        )
        self.assertFalse(valid_readiness(broken_separator))

    def test_live_declaration_is_unique_canonical_and_not_hidden(self):
        self.assertTrue(valid_live_authorization(self.content))
        self.assertEqual(self.content.count(LIVE_STATUS), 1)
        mutations = (
            LIVE_STATUS + "\n**Estado actual de live:** AUTORIZADO.",
            "**Estado actual de live:** AUTORIZADO.",
            LIVE_STATUS + "\n" + LIVE_STATUS,
            LIVE_STATUS + "\n<!-- **Estado actual de live:** AUTORIZADO. -->",
            "<!-- " + LIVE_STATUS + " -->",
            "**ESTADO ACTUAL DE LIVE:** NO AUTORIZADO.",
            LIVE_STATUS + " AUTORIZADO.",
        )
        for invalid in mutations:
            with self.subTest(status=invalid):
                candidate = self.content.replace(LIVE_STATUS, invalid, 1)
                self.assertNotEqual(candidate, self.content)
                self.assertFalse(valid_live_authorization(candidate))

    def test_go_no_go_decision_rejects_contradictions_and_hidden_aliases(self):
        self.assertTrue(valid_go_no_go_decision(self.content))
        self.assertTrue(valid_readiness(self.content))
        for variant in (
            "**Resultado go/no-go:** GO.",
            GO_NO_GO_CANONICAL + "\n**Resultado go/no-go:** GO.",
            GO_NO_GO_CANONICAL + "\n<!-- **Resultado go/no-go:** GO. -->",
            GO_NO_GO_CANONICAL + "\nResultado  go/no-go : GO.",
            GO_NO_GO_CANONICAL + "\nNota: **Resultado go/no-go:** GO.",
            GO_NO_GO_CANONICAL + "\n" + GO_NO_GO_CANONICAL,
            GO_NO_GO_CANONICAL.replace("**Resultado", "**RESULTADO", 1),
            GO_NO_GO_CANONICAL + " AUTORIZADO.",
        ):
            with self.subTest(variant=variant):
                mutated = self.content.replace(GO_NO_GO_CANONICAL, variant, 1)
                self.assertNotEqual(mutated, self.content)
                self.assertFalse(valid_go_no_go_decision(mutated))
                self.assertFalse(valid_readiness(mutated))

    def test_duplicate_gate_sections_cannot_hide_contradictory_decisions(self):
        self.assertTrue(valid_readiness(self.content))
        self.assertTrue(valid_live_authorization(self.content))
        for heading, second_decision, validator in (
            (GO_NO_GO_HEADING, "**Decisión de preparación:** GO.", valid_readiness),
            (LIVE_HEADING, "**Autorización concedida:** SÍ (go-live).", valid_live_authorization),
        ):
            with self.subTest(heading=heading):
                candidate = self.content + "\n## " + heading + "\n\n" + second_decision + "\n"
                self.assertEqual(section(candidate, heading), "")
                self.assertFalse(validator(candidate))

    def test_sla_is_proposed_not_approved(self):
        title = "SLAs iniciales — propuesta, NO aprobación contractual"
        text = section(self.content, title)
        rows = table(self.content, title)
        self.assertGreaterEqual(len(rows), 3)
        self.assertTrue(all(row.get("Estado") == "PROPUESTA_NO_APROBADA" for row in rows))
        self.assertTrue(all("propuesta" in row["Objetivo de primera respuesta propuesto"] for row in rows))
        self.assertTrue(all("UNKNOWN" in row["Fuente"] for row in rows))
        self.assertIn("No existe SLA contractual", text)
        changed = self.content.replace("PROPUESTA_NO_APROBADA |", "APROBADA |")
        self.assertNotEqual(changed, self.content)
        self.assertFalse(all(row["Estado"] == "PROPUESTA_NO_APROBADA" for row in table(changed, title)))


if __name__ == "__main__":
    unittest.main()
