#!/usr/bin/env python3
"""GrindFlow #708: cinco brechas REQ de comportamiento Laravel, sin datos reales.

Un método PHPUnit versionado con una aserción ejecutable demuestra una
regresión local declarada, NO que se ejecutó CI, Hostinger o producción.
Esas acreditaciones externas siempre quedan UNKNOWN en este contrato.
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_ASSERTIONS_ONLY"
EXECUTED = "DISPOSABLE_TEST_EXECUTED"
MATRIX = "docs/matriz-cobertura.md"
REQUIREMENTS = "docs/REQUIREMENTS.md"


@dataclass(frozen=True)
class Gap:
    identifier: str
    required_phrases: tuple[str, ...]
    # Los pares archivo/métodos son evidencia de pruebas de comportamiento,
    # no pruebas de producción ni mera existencia de un módulo PHP.
    methods: tuple[tuple[str, tuple[str, ...]], ...]


GAPS = (
    Gap("GF-FR-001", ("cross-organization reads", "cross-organization writes", "queue jobs carry"), (
        ("tests/Feature/OrganizationContextTest.php", (
            "test_queue_context_revalidates_membership_before_running",
            "test_queue_context_rejects_foreign_organization",
        )),
        ("tests/Feature/TenantScopedModelTest.php", (
            "test_tenant_models_fail_closed_without_organization_context",
            "test_context_scopes_reads_and_forces_organization_on_create",
            "test_cross_tenant_create_is_rejected",
        )),
    )),
    Gap("GF-FR-002", ("ingestion is resumable", "ambiguous ownership", "duplicate bytes"), (
        ("tests/Feature/MediaIngestionJobTest.php", (
            "test_same_source_is_queued_only_once",
            "test_staged_source_rejects_invalid_connector_input_before_queueing",
            "test_non_manager_cannot_queue_media_ingestion",
            "test_missing_source_records_safe_failure_state",
        )),
    )),
    Gap("GF-FR-003", ("jobs are idempotent", "retries do not duplicate"), (
        ("tests/Feature/MediaProcessingJobTest.php", (
            "test_canonical_asset_is_queued_once_and_duplicate_is_skipped",
            "test_processing_job_completes_deterministically_and_retry_is_idempotent",
            "test_enabled_ffprobe_metadata_persists_and_retry_is_idempotent",
            "test_failed_processing_is_observable_and_can_retry_without_new_asset",
        )),
    )),
    Gap("GF-FR-004", ("required validation gates", "timezone handling"), (
        ("tests/Feature/SchedulingTest.php", (
            "test_editor_can_schedule_currently_processed_media_with_explicit_timezone",
            "test_incomplete_or_stale_media_cannot_enter_scheduled_state",
            "test_cross_tenant_destination_is_not_visible_to_scheduler_post",
            "test_timezone_must_be_explicit_and_valid",
        )),
    )),
    Gap("GF-FR-004C", ("until delivery begins", "explicit empty selection detaches", "under a transaction"), (
        ("tests/Feature/ScheduleTrackedLinkTest.php", (
            "test_manager_can_attach_swap_and_detach_a_link_without_losing_metrics",
            "test_link_edit_rejects_other_tenant_model_and_invalid_or_disabled_link",
            "test_link_edit_rejects_cancelled_or_claimed_delivery",
            "test_link_edit_requires_existing_link_schema",
        )),
    )),
)
IDS = tuple("REQ:" + gap.identifier for gap in GAPS)
SOURCES = {MATRIX, REQUIREMENTS} | {path for gap in GAPS for path, _ in gap.methods}


def parse_matrix(raw: str) -> dict[str, tuple[str, ...]]:
    """Detecta también duplicados con sangría y filas de ocho columnas erróneas."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        line = line.strip()
        if not line.startswith("| REQ:"):
            continue
        parts = tuple(s.strip() for s in line.strip("|").split("|"))
        if not parts or parts[0] not in IDS:
            continue
        if len(parts) != 8 or parts[0] in rows:
            raise ValueError("UNKNOWN: duplicated or malformed coverage row")
        rows[parts[0]] = parts
    return rows


def requirement_section(raw: str, identifier: str) -> str | None:
    """Una sección no vacía, única y con encabezado exacto GF-FR-* ."""
    # Los comentarios HTML no son texto normativo ejecutable/visible.
    raw = re.sub(r"<!--.*?(?:-->|\Z)", " ", raw, flags=re.DOTALL)
    heading = r"(?m)^### " + re.escape(identifier) + r"\s+[—-]\s+[^\n]+$"
    matches = list(re.finditer(heading, raw))
    if len(matches) != 1:
        return None
    tail = raw[matches[0].end():]
    section = re.split(r"(?m)^#{2,3}\s+", tail, maxsplit=1)[0]
    return section if section.strip() else None


def php_executable_text(source: str) -> str:
    """Enmascarar comentarios/cadenas preservando offsets, saltos y llaves.

    No confundir `https://` en strings con comentarios ni contenido de
    clases/métodos falsos con PHP ejecutable. Un comentario sin cerrar se
    enmascara hasta EOF: falla cerrado para declaraciones posteriores.
    """
    out = list(source)
    i, n = 0, len(source)
    while i < n:
        c = source[i]
        if c in ("'", '"', '`'):
            quote = c
            j = i + 1
            while j < n:
                if source[j] == "\\":
                    j += 2
                    continue
                if source[j] == quote:
                    j += 1
                    break
                j += 1
        elif source.startswith('<<<', i):
            # Heredoc/nowdoc son literales, no PHP ejecutable. Un cierre
            # ilegible enmascara hasta EOF en lugar de acreditar aserciones.
            opener = re.match(
                r"""<<<[ \t]*(?:'([A-Za-z_]\w*)'|"([A-Za-z_]\w*)"|([A-Za-z_]\w*))""",
                source[i:],
            )
            if opener is None:
                i += 1
                continue
            label = next(part for part in opener.groups() if part is not None)
            terminator = re.search(
                r"(?m)^[ \t]*" + re.escape(label) + r"[;,]?[ \t]*\r?$",
                source[i + opener.end():],
            )
            j = i + opener.end() + terminator.end() if terminator else n
        elif source.startswith('/*', i):
            k = source.find('*/', i + 2)
            j = k + 2 if k != -1 else n
        elif source.startswith('//', i) or c == '#':
            k = source.find('\n', i)
            j = k if k != -1 else n
        else:
            i += 1
            continue
        for k in range(i, min(j, n)):
            if source[k] != '\n':
                out[k] = ' '
        i = max(j, i + 1)
    return ''.join(out)


def _balanced_body(masked: str, opening: int) -> str | None:
    """Búsqueda estricta de cierre propio; ningún bloque truncado acredita."""
    if opening >= len(masked) or masked[opening] != '{':
        return None
    depth = 1
    for i in range(opening + 1, len(masked)):
        if masked[i] == '{':
            depth += 1
        elif masked[i] == '}':
            depth -= 1
            if depth == 0:
                return masked[opening + 1:i]
    return None


def _single_phpunit_class(source: str) -> str | None:
    """Una clase real TestCase con RefreshDatabase ejecutable, sin señuelos."""
    masked = php_executable_text(source)
    patterns = list(re.finditer(
        r'\bclass\s+[A-Za-z_]\w*\s+extends\s+(?:\\?[A-Za-z_]\w*\\)*TestCase\b\s*\{',
        masked,
    ))
    if len(patterns) != 1:
        return None
    body = _balanced_body(masked, patterns[0].end() - 1)
    if body is None or not re.search(r'\buse\s+RefreshDatabase\s*;', body):
        return None
    return body


def _brace_depth_before(masked: str, offset: int) -> int:
    """Una firma anidada dentro de otro método/clase no es un test top-level."""
    depth = 0
    for char in masked[:offset]:
        if char == '{':
            depth += 1
        elif char == '}':
            depth -= 1
            if depth < 0:
                return -1
    return depth


def method_body(source: str, name: str) -> str | None:
    """Aserción dentro del método y de la misma clase TestCase balanceada."""
    body = _single_phpunit_class(source)
    if body is None:
        return None
    pattern = (r'\bpublic\s+(?:static\s+)?function\s+' + re.escape(name)
               + r'\s*\(\s*\)\s*:\s*void\s*\{')
    matches = [match for match in re.finditer(pattern, body)
               if _brace_depth_before(body, match.start()) == 0]
    if len(matches) != 1:
        return None
    return _balanced_body(body, matches[0].end() - 1)


def phpunit_executed_test_count(output: str) -> int | None:
    """Solo recuentos terminales explícitos; `Tests: 0` nunca acredita."""
    lines = re.findall(r'(?m)^\s*Tests:\s*(\d+)\s*,', output)
    lines += re.findall(r'(?m)^\s*OK \(\s*(\d+) tests?\b', output)
    if len(lines) != 1:
        return None
    count = int(lines[0])
    return count if count > 0 else None


def phpunit_summary_is_acceptable(output: str, required: int) -> bool:
    """Un resumen con omitidos o estados anómalos no acredita ejecución."""
    count = phpunit_executed_test_count(output)
    issues = re.search(
        r"\b(?:Skipped|Incomplete|Risky|Errors|Failures):\s*[1-9]\d*\b",
        output,
    )
    return count is not None and count >= required and issues is None


def phpunit_executed_for_file(root: Path, file: str, names: tuple[str, ...]) -> bool | None:
    """Si no hay dependencia local: UNKNOWN, nunca ejecución inventada.

    Si PHPUnit está disponible, usar el archivo exacto y exigir que el
    resumen informe al menos tantos tests como métodos requeridos.
    """
    executable = root / 'vendor/bin/phpunit'
    if not executable.is_file():
        return None
    regex = '(' + '|'.join(re.escape(x) for x in names) + ')'
    try:
        result = subprocess.run(
            ['php', str(executable), file, '--filter', regex, '--no-ansi'],
            cwd=root, capture_output=True, text=True, timeout=90, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    output = result.stdout + '\n' + result.stderr
    return result.returncode == 0 and phpunit_summary_is_acceptable(output, len(names))


def assertion_present(body: str | None) -> bool:
    '''Do not credit assert calls in comments or literal strings.'''
    return body is not None and bool(re.search(
        r"(?:(?:->|::)assert[A-Z]\w*|\$this->expectException)\s*\(",
        php_executable_text(body),
    ))

def inspect(gap: Gap, rows: dict[str, tuple[str, ...]],
            requirements: str, sources: dict[str, str]) -> dict[str, str]:
    """La verificación del código jamás otorga permisos ni readiness operativo."""
    result = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = rows.get("REQ:" + gap.identifier)
    if row is None or len(row) != 8:
        return result
    section = requirement_section(requirements, gap.identifier)
    if not section or not all(phrase in section.casefold() for phrase in gap.required_phrases):
        return result
    for path, names in gap.methods:
        source = sources.get(path)
        if not source or not names:
            return result
        if any(not assertion_present(method_body(source, name)) for name in names):
            return result
    result["code"] = LOCAL
    return result


class BrechasGrindflow01Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}
        cls.rows = parse_matrix(cls.sources[MATRIX])

    def test_contrato_principal(self) -> None:
        """AC-01: cinco IDs, fuente, 21 métodos PHPUnit con aserción real."""
        self.assertEqual(len(GAPS), 5)
        self.assertEqual(set(self.rows), set(IDS))
        pairs = [(path, name) for gap in GAPS for path, names in gap.methods for name in names]
        self.assertEqual(len(pairs), 21)
        self.assertEqual(len(set(pairs)), 21)
        for gap in GAPS:
            with self.subTest(requirement=gap.identifier):
                for path, names in gap.methods:
                    for method in names:
                        self.assertTrue(assertion_present(method_body(self.sources[path], method)),
                                        f"{gap.identifier}: {path}::{method} UNKNOWN")
                self.assertEqual(LOCAL, inspect(gap, self.rows, self.sources[REQUIREMENTS], self.sources)["code"])
                # PHPUnit real solo si el checkout instaló dependencias. El
                # reporte debe incluir el recuento; cero nunca acredita.
                for path, names in gap.methods:
                    executed = phpunit_executed_for_file(ROOT, path, names)
                    if executed is not None:
                        self.assertTrue(executed, f"{path}: PHPUnit no acreditó {len(names)} tests")

    def test_falla_cerrado(self) -> None:
        """AC-02: fuente ausente, duplicada, cambio de matriz o aserción ficticia."""
        for gap in GAPS:
            with self.subTest(requirement=gap.identifier):
                rid = "REQ:" + gap.identifier
                without = {key: row for key, row in self.rows.items() if key != rid}
                self.assertEqual(UNKNOWN, inspect(gap, without, self.sources[REQUIREMENTS], self.sources)["code"])
                self.assertEqual(UNKNOWN, inspect(gap, self.rows, "", self.sources)["code"])
                broken = dict(self.sources)
                broken[gap.methods[0][0]] = ""
                self.assertEqual(UNKNOWN, inspect(gap, self.rows, self.sources[REQUIREMENTS], broken)["code"])
                changed = dict(self.rows)
                changed[rid] = (*changed[rid][:2], "CUBIERTO", *changed[rid][3:])
                self.assertEqual(LOCAL, inspect(gap, changed, self.sources[REQUIREMENTS], self.sources)["code"])
        first = next(line for line in self.sources[MATRIX].splitlines() if line.startswith("| " + IDS[0] + " |"))
        with self.assertRaises(ValueError):
            parse_matrix(self.sources[MATRIX] + "\n  " + first)
        # Las frases normativas solo pueden existir fuera de comentarios.
        commented_req = ("### GF-FR-001 — Ejemplo\n"
                         "<!-- cross-organization reads, cross-organization writes, "
                         "queue jobs carry -->\n")
        self.assertIsNone(requirement_section(commented_req, "GF-FR-001"))
        self.assertIsNone(requirement_section(
            "<!--\n### GF-FR-001 — Encabezado falso\n" +
            "cross-organization reads\n-->\n", "GF-FR-001"))
        self.assertFalse(assertion_present(" // $this->assertTrue();\n"))
        self.assertFalse(assertion_present(' $sample = "->assertOk()";'))
        self.assertFalse(assertion_present(" $value = 1; // $this->assertSame(1, 1);"))
        self.assertFalse(assertion_present(" $value = 1; # $this->assertTrue(true);"))
        self.assertIsNone(method_body("public function another(): void {}", "missing"))
        # A commented-out method with a real-looking assert must fail closed.
        phantom = ("<?php\nclass TestExample extends TestCase {\n    use RefreshDatabase;\n/*\n"
                   "    public function test_phantom(): void {\n"
                   "        $this->assertSame(1, 1);\n    }\n*/\n}\n")
        self.assertIsNone(method_body(phantom, "test_phantom"))
        for marker in ("//", "#"):
            commented = ("<?php\nclass TestExample extends TestCase {\n    use RefreshDatabase;\n"
                         f"    {marker} public function test_phantom(): void {{\n"
                         f"    {marker} $this->assertSame(1, 1);\n"
                         f"    {marker} }}\n}}\n")
            self.assertIsNone(method_body(commented, "test_phantom"))
        # Positive control: real method remains accepted after an in-line comment.
        live = ("<?php\nclass TestExample extends TestCase {\n    use RefreshDatabase;\n"
                "    public function test_real(): void {\n"
                "        $value = 'https://example.invalid/test'; // comment\n"
                "        $this->assertSame(1, 1);\n    }\n}\n")
        self.assertTrue(assertion_present(method_body(live, "test_real")))
        # Una aserción escrita dentro de un literal heredoc/nowdoc es ficticia.
        for opener in ("<<<'NOW'", '<<<"NOW"', "<<<NOW"):
            literal = ("<?php\nclass TestExample extends TestCase {\n"
                       "use RefreshDatabase;\n"
                       "public function test_literal(): void {\n"
                       f"  $value = {opener};\n"
                       "  ->assertTrue(true);\n"
                       "NOW;\n"
                       "}\n}\n")
            self.assertFalse(assertion_present(method_body(literal, "test_literal")))
        nested = ("<?php\nclass TestExample extends TestCase {\n"
                  "use RefreshDatabase;\n"
                  "public function test_outer(): void {\n"
                  "  $anonymous = new class {\n"
                  "    public function test_nested(): void { self::assertTrue(true); }\n"
                  "  };\n"
                  "}\n}\n")
        self.assertIsNone(method_body(nested, "test_nested"))
        self.assertEqual([None, None, None, 1, 3], [
            phpunit_executed_test_count(text) for text in (
                "Tests: 0, Assertions: 0", "OK (0 tests, 0 assertions)",
                "No tests executed!", "Tests: 1, Assertions: 3",
                "OK (3 tests, 6 assertions)",
            )
        ])
        self.assertIsNone(phpunit_executed_test_count("Tests: 2, Assertions: 5\nTests: 3, Assertions: 7"))
        self.assertTrue(phpunit_summary_is_acceptable("Tests: 3, Assertions: 7", 3))
        for extra in ("Skipped", "Incomplete", "Risky", "Errors", "Failures"):
            self.assertFalse(phpunit_summary_is_acceptable(
                f"Tests: 3, Assertions: 7, {extra}: 1", 3))
        self.assertFalse(phpunit_summary_is_acceptable("Tests: 0, Assertions: 0", 1))
        source = ("<?php\nclass Real extends TestCase {\nuse RefreshDatabase;\n"
                  " public function test_empty(): void {}\n}\n"
                  "class Other { public function test_empty(): void { self::assertTrue(true); } }")
        self.assertFalse(assertion_present(method_body(source, "test_empty")))
        self.assertIsNone(method_body(source.replace("class Real extends TestCase", "class Real"), "test_empty"))
        self.assertIsNone(method_body(source.replace("use RefreshDatabase;", "/* use RefreshDatabase; */"), "test_empty"))
        self.assertIsNone(method_body(source.replace("}\nclass Other", "\nclass Other"), "test_empty"))
        bad = ("<?php\n/* class Fake extends TestCase { use RefreshDatabase; "
               "public function test_empty(): void { self::assertTrue(true); } } */")
        self.assertIsNone(method_body(bad, "test_empty"))
        b = ("<?php\nclass Real extends TestCase { use RefreshDatabase; "
             "public function test_first(): void { } "
             "public\nstatic function test_next(): void { self::assertTrue(true); } }")
        self.assertFalse(assertion_present(method_body(b, "test_first")))
        self.assertTrue(assertion_present(method_body(b, "test_next")))
        self.assertIsNone(method_body(b[:-2], "test_first"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: solamente fixtures/fuentes de repo, sin red o credenciales."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:from|import)\s+(?:requests|httpx|socket)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(SOURCES, set(self.sources))
        for path in SOURCES:
            self.assertFalse(Path(path).is_absolute())
            self.assertTrue((ROOT / path).is_file())

    def test_estados_unknown_explicitos(self) -> None:
        """AC-05: la evidencia local no certifica CI, merge ni Hostinger."""
        for gap in GAPS:
            with self.subTest(requirement=gap.identifier):
                row = self.rows["REQ:" + gap.identifier]
                self.assertEqual(len(row), 8)
                result = inspect(gap, self.rows, self.sources[REQUIREMENTS], self.sources)
                self.assertEqual(LOCAL, result["code"])
                self.assertEqual(UNKNOWN, result["ci"])
                self.assertEqual(UNKNOWN, result["production"])
                obscured = dict(self.sources)
                first_path = gap.methods[0][0]
                obscured[first_path] = "// unknown evidence"
                self.assertEqual(UNKNOWN, inspect(gap, self.rows, self.sources[REQUIREMENTS], obscured)["code"])


if __name__ == "__main__":
    unittest.main()
