"""GF-MIG-004 / #913: offline NO-GO contract for every protected legacy path.

No deploys, network, secrets or operational authority. The code inventory and
the previous synthetic gate are reused; this module cannot certify cutover.
"""
from __future__ import annotations

from copy import deepcopy
from pathlib import Path
import runpy
import unittest

ROOT = Path(__file__).resolve().parents[1]
REPORT_PATH = ROOT / "docs/launch/gf350-retiro-legado-evidencia.md"
LEGACY = runpy.run_path(str(ROOT / "tests/test_legacy_retirement_guard.py"))
GATE = runpy.run_path(str(ROOT / "tests/test_migration_parity_exit_gate.py"))
BT = chr(96)

COLUMNS = (
    "Ruta legacy", "Capa", "Función protegida",
    "Candidato Symfony no acreditado", "Paridad", "Dimensiones",
    "Prueba por ruta", "Rollback observado", "Backup restaurado",
    "Cutover real", "Smoke exact-SHA", "Recibo owner",
    "Doble escritor descartado", "Código legacy en Git", "Decisión",
)
PATH, KIND, FUNCTION, CANDIDATE, PARITY, DIMENSIONS, EVIDENCE, ROLLBACK, BACKUP, CUTOVER, SMOKE, OWNER, WRITER, ACTIVE, DECISION = range(15)
NO_GO = {
    PARITY: "UNKNOWN", DIMENSIONS: "PENDIENTE_8_8", EVIDENCE: "-",
    ROLLBACK: "NO_OBSERVADO", BACKUP: "NO_OBSERVADO",
    CUTOVER: "NO_EJECUTADO", SMOKE: "NO_OBSERVADO", OWNER: "-",
    WRITER: "NO_DESCARTADO", ACTIVE: "PRESENTE", DECISION: "NO-GO",
}
KNOWN_CANDIDATES = {
    "routes/web.php": "LoginController /login; HomeController /",
    "app/Http/Controllers/Auth/AuthenticatedSessionController.php": "LoginController /login",
}


def parse_report(markdown: str) -> list[tuple[str, ...]]:
    start = markdown.split("## Registro por ruta")
    if len(start) != 2:
        raise ValueError("Missing or repeated per-route registry")
    section = start[1].split("## Resultado y condiciones para un PR posterior")
    if len(section) != 2:
        raise ValueError("Missing or repeated NO-GO outcome")
    rows = []
    header_seen = False
    for line in section[0].splitlines():
        if not line.startswith("|"):
            continue
        fields = tuple(item.strip() for item in line.strip().strip("|").split("|"))
        if not header_seen:
            if fields != COLUMNS:
                raise ValueError("Per-route columns changed")
            header_seen = True
            continue
        if all(set(x) <= {"-", ":", " "} for x in fields):
            continue
        if len(fields) != len(COLUMNS) or not fields[PATH].startswith(BT) or not fields[PATH].endswith(BT):
            raise ValueError("Malformed or extra route")
        rows.append((fields[PATH][1:-1], *fields[1:]))
    if not header_seen:
        raise ValueError("Missing per-route table")
    if len(rows) != 26 or len({r[PATH] for r in rows}) != 26:
        raise ValueError("Missing, duplicate or extra legacy dependency")
    return rows


def verify_registry(
    *,
    report: str | None = None,
    root: Path = ROOT,
) -> list[tuple[str, ...]]:
    """A negative, source-bound inventory gate; never a production approval."""
    text = REPORT_PATH.read_text(encoding="utf-8") if report is None else report
    rows = parse_report(text)
    inventory = LEGACY["read_inventory"](root)
    source = {entry["path"]: entry for entry in inventory}
    if set(source) != {row[PATH] for row in rows}:
        raise ValueError("Registry differs from protected inventory")
    if "26/26 rutas NO-GO" not in text:
        raise ValueError("Missing conservative reconciliation")
    for row in rows:
        path = row[PATH]
        ref = source[path]
        p = Path(path)
        if p.is_absolute() or ".." in p.parts or not (root / p).is_file() or (root / p).is_symlink():
            raise ValueError("Protected legacy code missing or replaced: " + path)
        if row[KIND] != ref["kind"] or not row[FUNCTION].strip():
            raise ValueError("Incorrect kind or unidentified function: " + path)
        expected_candidate = KNOWN_CANDIDATES.get(path, "NO_IDENTIFICADO")
        if row[CANDIDATE] != expected_candidate:
            raise ValueError("Unproven Symfony candidate: " + path)
        if (ref["parity"], ref["retirement"], ref["evidence"]) != ("UNKNOWN", "NO", "-"):
            raise ValueError("Prior legacy authority not demonstrated: " + path)
        for field, expected in NO_GO.items():
            if row[field] != expected:
                raise ValueError("Unverified retirement evidence promoted: " + path + " / " + COLUMNS[field])
    return rows


class LegacyRetirementEligibilityTests(unittest.TestCase):
    def setUp(self):
        self.document = REPORT_PATH.read_text(encoding="utf-8")
        self.rows = verify_registry()

    def _replace_row(self, route: str, field: int, replacement: str) -> str:
        rows = self.document.splitlines()
        for index, line in enumerate(rows):
            if line.startswith("| " + BT + route + BT + " |"):
                fields = list(line.strip("|").strip().split(" | "))
                if len(fields) != 15:
                    raise AssertionError("Malformed fixture")
                fields[field] = replacement
                rows[index] = "| " + " | ".join(fields) + " |"
                return "\n".join(rows) + "\n"
        raise AssertionError("Missing test row: " + route)

    def test_no_retirement_without_per_path_evidence(self):
        """AC-01: UNKNOWN, absent proofs and an active code tree must be NO-GO."""
        self.assertEqual(26, len(self.rows))
        self.assertEqual({"laravel", "nextjs", "worker", "supabase"}, {r[KIND] for r in self.rows})
        for row in self.rows:
            with self.subTest(path=row[PATH]):
                self.assertEqual("UNKNOWN", row[PARITY])
                self.assertEqual("PENDIENTE_8_8", row[DIMENSIONS])
                self.assertEqual("-", row[EVIDENCE])
                self.assertEqual("-", row[OWNER])
                self.assertEqual("NO_OBSERVADO", row[ROLLBACK])
                self.assertEqual("NO_DESCARTADO", row[WRITER])
                self.assertEqual("PRESENTE", row[ACTIVE])
                self.assertEqual("NO-GO", row[DECISION])

        # Reuse the existing #682 protection and #641 negative gate, not a
        # separate or weaker substitute for either.
        LEGACY["assert_legacy_retirement_guard"](ROOT)
        manifest = GATE["PARITY"]["load_manifest"](
            GATE["MATRIX"].read_text(encoding="utf-8")
        )
        plan = GATE["REVERSAL"]["build_plan"]()
        self.assertFalse(
            GATE["offline_retirement_gate"](
                manifest, GATE["SOURCE"].read_text(encoding="utf-8"),
                plan, {name: True for name in GATE["PROOFS"]}
            )
        )

    def test_missing_duplicate_extra_route_rejected(self):
        first_line = next(line for line in self.document.splitlines() if line.startswith("| " + BT + "routes/web.php" + BT))
        variants = (
            self.document.replace(first_line + "\n", "", 1),
            self.document.replace(first_line, first_line + "\n" + first_line, 1),
            self.document.replace(first_line, first_line.replace("routes/web.php", "routes/fictitious.php"), 1),
            self.document.replace(first_line, first_line + "\n| arbitrary | invalid | unknown |", 1),
        )
        for index, variant in enumerate(variants):
            with self.subTest(mutation=index), self.assertRaises(ValueError):
                verify_registry(report=variant)

    def test_forged_owner_receipt_and_false_parity_fail_closed(self):
        route = "routes/web.php"
        attempts = (
            (PARITY, "VERIFIED"),
            (PARITY, "DEFERRED"),
            (DIMENSIONS, "8/8"),
            (EVIDENCE, "tests/synthetic.php"),
            (OWNER, "owner-approved-fake-receipt"),
            (CUTOVER, "APROBADO"),
            (SMOKE, "SUCCESS"),
            (DECISION, "RETIRABLE"),
        )
        for field, value in attempts:
            mutated = self._replace_row(route, field, value)
            with self.subTest(field=COLUMNS[field]), self.assertRaisesRegex(ValueError, "Unverified"):
                verify_registry(report=mutated)

    def test_active_code_unobserved_rollback_and_double_writer_block(self):
        route = "workers/main.py"
        for field, value in (
            (ACTIVE, "RETIRADO"),
            (ROLLBACK, "PLAN_DOCUMENTADO"),
            (ROLLBACK, "OK"),
            (BACKUP, "OK"),
            (WRITER, "SI"),
            (WRITER, "DOS_ESCRITORES"),
        ):
            mutated = self._replace_row(route, field, value)
            with self.subTest(field=COLUMNS[field], value=value), self.assertRaises(ValueError):
                verify_registry(report=mutated)

    def test_fake_symfony_equivalence_and_category_drift_block(self):
        route = "src/middleware.ts"
        for field, value in (
            (CANDIDATE, "symfony/src/Http/Controller/LoginController.php"),
            (KIND, "laravel"),
            (FUNCTION, ""),
        ):
            mutated = self._replace_row(route, field, value)
            with self.subTest(field=COLUMNS[field]), self.assertRaises(ValueError):
                verify_registry(report=mutated)

    def test_document_is_explicitly_offline_and_links_existing_gates(self):
        self.assertIn("Estado: NO-GO", self.document)
        self.assertIn("NO evidencia de producción", self.document)
        for ref in ("#641", "#681", "#682", "#801", "#882", "#350"):
            self.assertIn(ref, self.document)
        self.assertIn("8 dimensiones", self.document)
        self.assertIn("un único escritor", self.document)
        self.assertIn("NO-GO", self.document)


if __name__ == "__main__":
    unittest.main()
