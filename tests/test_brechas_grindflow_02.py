#!/usr/bin/env python3
"""GrindFlow #709: trazabilidad fail-closed de cinco brechas funcionales.

Las pruebas Laravel existentes son evidencia local de contratos, no un smoke
real ni un certificado de despliegue en Hostinger. Todo estado operativo
permanece UNKNOWN sin una observacion independiente autenticada.
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"
UNKNOWN = "UNKNOWN"
LOCAL = "PRUEBA_LOCAL_DECLARADA"


@dataclass(frozen=True)
class Brecha:
    row_id: str
    section: str
    php_file: str
    methods: tuple[str, ...]
    contract: str


BRECHAS = (
    Brecha(
        "REQ:GF-FR-004D", "GF-FR-004D", "tests/Feature/SchedulingTest.php",
        ("test_calendar_paginates_all_matching_schedules_without_cross_tenant_leaks",
         "test_calendar_preserves_filters_across_pages_including_disabled_destinations"),
        "server-side pages of 25",
    ),
    Brecha(
        "REQ:GF-FR-004E", "GF-FR-004E",
        "tests/Feature/SchedulerPickerSearchTest.php",
        ("test_media_and_link_search_reach_entries_past_first_hundred_without_tenant_leak",
         "test_picker_keeps_valid_old_and_assigned_items_without_exposing_disabled_or_foreign_links",
         "test_search_and_calendar_filters_coexist_and_missing_link_migration_is_safe"),
        "100-option window",
    ),
    Brecha(
        "REQ:GF-FR-005", "GF-FR-005", "tests/Feature/DistributionTest.php",
        ("test_authentication_failure_is_terminal_and_distinct",
         "test_rate_limit_is_bounded_and_successful_retry_is_idempotent",
         "test_transient_failures_stop_after_bounded_attempts"),
        "idempotency",
    ),
    Brecha(
        "REQ:GF-FR-005D", "GF-FR-005D", "tests/Feature/DistributionTest.php",
        ("test_distribution_history_pages_all_tenant_deliveries_with_stable_order_and_filters",),
        "paginación sql de 25",
    ),
    Brecha(
        "REQ:GF-FR-006", "GF-FR-006",
        "tests/Feature/TrafficAttributionTest.php",
        ("test_public_redirect_is_no_cache_and_queues_only_hash",
         "test_hash_is_scoped_per_link_to_prevent_cross_link_correlation",
         "test_traffic_schema_contains_no_raw_visitor_columns"),
        "hmac",
    ),
)


def parse_matrix(raw: str) -> dict[str, tuple[str, ...]]:
    """Rechazar filas duplicadas/malformadas; no inferir cobertura por ausencia."""
    wanted = {item.row_id for item in BRECHAS}
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        if not line.startswith("| REQ:GF-FR-"):
            continue
        fields = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        row_id = fields[0]
        if row_id not in wanted:
            continue
        if row_id in found:
            raise ValueError("duplicate row: " + row_id)
        if len(fields) != 8:
            raise ValueError("invalid columns: " + row_id)
        found[row_id] = fields
    return found


def requirement_section(raw: str, identifier: str) -> str | None:
    """Una seccion canonica unica; duplicados no son una prueba valida."""
    pattern = r"(?m)^### " + re.escape(identifier) + r"\s+[^\n]*$"
    matches = list(re.finditer(pattern, raw))
    if len(matches) != 1:
        return None
    tail = raw[matches[0].end():]
    return re.split(r"(?m)^###\s+", tail, maxsplit=1)[0]


def has_asserting_method(source: str, method: str) -> bool:
    """No aceptar solo una firma vacia como evidencia de test."""
    match = re.search(r"\bpublic\s+function\s+" + re.escape(method)
                      + r"\s*\(\s*\)\s*:\s*void\b", source)
    if match is None:
        return False
    tail = source[match.end():].split("public function ", 1)[0]
    return re.search(r"\bassert[A-Z][A-Za-z]+\s*\(", tail) is not None


def inspect_local(
    item: Brecha,
    rows: dict[str, tuple[str, ...]],
    requirements: str,
    php_sources: dict[str, str],
) -> dict[str, str]:
    """Evidencia local reversible; NUNCA concede estado operativo verde."""
    result = {"code": UNKNOWN, "production": UNKNOWN}
    row = rows.get(item.row_id)
    if row is None or row[2] not in {
        "NO_VERIFICABLE", "SIN_COBERTURA", "CUBIERTO", "EN_CURSO"
    }:
        return result
    section = requirement_section(requirements, item.section)
    if section is None or item.contract not in section.lower():
        return result
    source = php_sources.get(item.php_file, "")
    if not source or not all(has_asserting_method(source, m) for m in item.methods):
        return result
    result["code"] = LOCAL
    return result


class BrechasGrindflow02Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.requirements = REQUIREMENTS.read_text(encoding="utf-8")
        cls.sources = {
            item.php_file: (ROOT / item.php_file).read_text(encoding="utf-8")
            for item in BRECHAS
        }
        cls.rows = parse_matrix(cls.matrix)

    def test_contrato_principal(self) -> None:
        # AC-01: cinco casos independientes; calendario, pickers, entregas,
        # historial y atribucion tienen contrato y aserciones locales reales.
        self.assertEqual(set(self.rows), {item.row_id for item in BRECHAS})
        for item in BRECHAS:
            with self.subTest(row=item.row_id):
                self.assertEqual(
                    inspect_local(item, self.rows, self.requirements, self.sources)["code"],
                    LOCAL,
                )
                self.assertIn("NO_VERIFICABLE", self.rows[item.row_id][2])

    def test_falla_cerrado(self) -> None:
        # AC-02: fuente, fila, method, formato o identidad ambiguos => UNKNOWN.
        sample = BRECHAS[0]
        no_row = {k: v for k, v in self.rows.items() if k != sample.row_id}
        self.assertEqual(
            inspect_local(sample, no_row, self.requirements, self.sources)["code"], UNKNOWN
        )
        self.assertEqual(
            inspect_local(sample, self.rows, "", self.sources)["code"], UNKNOWN
        )
        incomplete = dict(self.sources)
        incomplete[sample.php_file] = "public function " + sample.methods[0] + "() : void {}"
        self.assertEqual(
            inspect_local(sample, self.rows, self.requirements, incomplete)["code"], UNKNOWN
        )
        malformed = dict(self.rows)
        entry = list(malformed[sample.row_id])
        entry[2] = "PRODUCTION_GREEN"
        malformed[sample.row_id] = tuple(entry)
        self.assertEqual(
            inspect_local(sample, malformed, self.requirements, self.sources)["code"],
            UNKNOWN,
        )
        row_text = next(line for line in self.matrix.splitlines()
                        if line.startswith("| " + sample.row_id + " |"))
        with self.assertRaisesRegex(ValueError, "duplicate row"):
            parse_matrix(self.matrix + "\n" + row_text)
        with self.assertRaisesRegex(ValueError, "invalid columns"):
            parse_matrix("| " + sample.row_id + " | no columns |")
        section_header = "### " + sample.section + " — duplicate"
        self.assertIsNone(
            requirement_section(self.requirements + "\n" + section_header, sample.section)
        )

    def test_sin_datos_reales(self) -> None:
        # AC-03: solo fuentes versionadas locales. Sin red, secretos, PII o
        # fixtures de proveedores; ninguna prueba acciona dispatch/publicacion.
        own_source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own_source, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertNotRegex(own_source, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own_source, r"(?m)^(?:import|from)\s+(?:requests|urllib|httpx|socket|subprocess)\b")
        self.assertNotIn("https://api.", own_source)
        for item in BRECHAS:
            self.assertTrue(item.php_file.startswith("tests/Feature/"))
            self.assertTrue((ROOT / item.php_file).is_file())

    def test_estados_unknown_explicitos(self) -> None:
        # AC-04 del contrato ejecutable: ni tests PHP ni matriz acreditan
        # despliegue/produccion; el gate CI se valida por GitHub exact-HEAD.
        for item in BRECHAS:
            with self.subTest(row=item.row_id):
                row = self.rows[item.row_id]
                self.assertEqual(row[2], "NO_VERIFICABLE")
                self.assertEqual(row[5:8], ("NO_ACREDITADO",) * 3)
                result = inspect_local(item, self.rows, self.requirements, self.sources)
                self.assertEqual(result["production"], UNKNOWN)
                self.assertEqual(result["code"], LOCAL)
        synthetic = dict(self.rows)
        for item in BRECHAS:
            row = list(synthetic[item.row_id])
            row[2] = "CUBIERTO"
            synthetic[item.row_id] = tuple(row)
            self.assertEqual(
                inspect_local(item, synthetic, self.requirements, self.sources)["production"],
                UNKNOWN,
            )


if __name__ == "__main__":
    unittest.main()
