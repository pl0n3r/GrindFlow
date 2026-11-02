#!/usr/bin/env python3
"""GrindFlow #722: five documented STACK gaps, never operational certification.

Evidence is read-only. A documented requirement is not E2E, CI, ACL,
commercial approval, backup, deployment or production proof.
"""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAP = "DOCUMENTED_GAP"
MATRIX = "docs/matriz-cobertura.md"
CONTRAST = "docs/contraste-gf_stack.md"
SPEC = "docs/STACK-TRANSITION-SYMFONY.md"

# ID -> (immutable source anchor, exact heading, normative tokens, diagnosis)
CONTRACTS = {
    "STACK:GF-ARCH-003": (
        214, "### GF-ARCH-003",
        ("GrindFlow CI / validate", "Doctrine/MariaDB", "Playwright",
         "CI exact-main", "smoke", "version.php"),
        "falta E2E de coexistencia/rollback"),
    "STACK:GF-FR-008": (
        263, "### GF-FR-008",
        ("persona autorizada selecciona organización", "recorrido E2E aislado",
         "fallo parcial/reintento", "Nunca llamar publicación real"),
        "falta recorrido E2E completo"),
    "STACK:GF-FR-009": (
        270, "### GF-FR-009",
        ("cinco participantes", "30 días/dos redes", "aprobación"),
        "falta evaluación comercial humana"),
    "STACK:GF-FR-010": (
        306, "### GF-FR-010",
        ("280 caracteres", "CSRF", "papelera", "membresía",
         "No exponer notas en enlaces públicos"),
        "falta prueba específica de crear/editar/borrar notas"),
    "STACK:GF-FR-011": (
        338, "### GF-FR-011",
        ("unclassified", "internal_only", "needs_review",
         "Ningún valor confirma derechos", "reautorización SQL"),
        "falta test completo de clasificación conservadora"),
}
OPERATIONAL_GATES = (
    "code", "ci", "e2e", "tenant_acl", "commercial", "owner_approval",
    "backup", "deployment", "production", "hostinger", "release", "smoke",
)


def read_sources(root: Path) -> dict[str, str]:
    """No network, credentials, requests, live fixtures or external processes."""
    result = {}
    for path in (MATRIX, CONTRAST, SPEC):
        try:
            result[path] = (root / path).read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            result[path] = ""
    return result


def extract_rows(source: str, ids: set[str], width: int):
    """Reject duplicate/incomplete ID rows; do not conflate evidence tables."""
    rows = {}
    for line in source.splitlines():
        # Whitespace before a Markdown row does not grant a second, hidden
        # evidence claim. Treat malformed/indented duplicates as ambiguity.
        normalized = line.strip()
        if not (normalized.startswith("|") and normalized.endswith("|")):
            continue
        cells = tuple(part.strip() for part in normalized.strip("|").split("|"))
        if not cells or cells[0] not in ids:
            continue
        ident = cells[0]
        if ident in rows or len(cells) != width:
            return None
        rows[ident] = cells
    return rows if set(rows) == ids else None


def normative_section(source: str, heading: str):
    """Match a single section; tokens in other sections cannot count."""
    matches = list(re.finditer(r"(?m)^" + re.escape(heading) + r"(?:[ \t]+[^\n]*)?$", source))
    if len(matches) != 1:
        return None
    start = matches[0].end()
    remaining = source[start:]
    level = len(heading) - len(heading.lstrip("#"))
    boundary = re.search(r"(?m)^#{1," + str(level) + r"} ", remaining)
    return remaining[:boundary.start()] if boundary else remaining


def assess(sources: dict[str, str]) -> dict[str, dict[str, str]]:
    """Positive result only means the five gaps are properly documented."""
    output = {
        ident: {"documentation": UNKNOWN, **{key: UNKNOWN for key in OPERATIONAL_GATES}}
        for ident in CONTRACTS
    }
    rows = extract_rows(sources.get(MATRIX, ""), set(CONTRACTS), 8)
    findings = extract_rows(sources.get(CONTRAST, ""), set(CONTRACTS), 7)
    if rows is None or findings is None:
        return output
    spec = sources.get(SPEC, "")
    for ident, (anchor, heading, clauses, diagnosis) in CONTRACTS.items():
        matrix, contrast = rows[ident], findings[ident]
        anchor_ref = f"STACK-TRANSITION-SYMFONY.md:L{anchor}"
        if (matrix[2] != "NO_VERIFICABLE"
                or matrix[5:] != ("NO_ACREDITADO",) * 3
                or anchor_ref not in matrix[3]
                or contrast[2] != "NO_VERIFICABLE"
                or anchor_ref not in contrast[1]
                or contrast[3:6] != ("—", "—", "—")
                or diagnosis not in contrast[6]):
            continue
        clause = normative_section(spec, heading)
        if clause is None or not all(token in clause for token in clauses):
            continue
        output[ident]["documentation"] = GAP
    return output


class BrechasGrindflow15Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sources = read_sources(ROOT)

    def test_contrato_principal(self):
        result = assess(self.sources)
        self.assertEqual(set(result), set(CONTRACTS))
        for ident, status in result.items():
            with self.subTest(ident=ident):
                self.assertEqual(status["documentation"], GAP)

    def test_falla_cerrado(self):
        for ident, (anchor, heading, tokens, _) in CONTRACTS.items():
            with self.subTest(ident=ident):
                for file, old in ((MATRIX, f"| {ident} |"),
                                  (CONTRAST, f"| {ident} |")):
                    changed = dict(self.sources)
                    changed[file] = changed[file].replace(old, "| UNKNOWN-ID |", 1)
                    self.assertEqual(assess(changed)[ident]["documentation"], UNKNOWN)
                    # Duplicated row must never be treated as independent evidence.
                    row = next((r for r in self.sources[file].splitlines()
                                if r.startswith(old)), "")
                    duplicated = dict(self.sources)
                    duplicated[file] += "\n" + row
                    self.assertEqual(assess(duplicated)[ident]["documentation"], UNKNOWN)
                    # Spaced, tab-indented and tight-pipe duplicates must
                    # likewise fail closed rather than certifying one row.
                    for variant in ("   " + row, "\t" + row,
                                    row.replace("| ", "|", 1)):
                        obfuscated = dict(self.sources)
                        obfuscated[file] += "\n" + variant
                        self.assertEqual(
                            assess(obfuscated)[ident]["documentation"], UNKNOWN)
                source = self.sources[SPEC]
                body = normative_section(source, heading)
                self.assertIsNotNone(body)
                token = tokens[0]
                self.assertIn(token, body)
                altered = dict(self.sources)
                # Change token only within the named section; earlier sections may reuse it.
                altered[SPEC] = source.replace(body, body.replace(token, "MISSING", 1), 1)
                self.assertEqual(assess(altered)[ident]["documentation"], UNKNOWN)
                promoted = dict(self.sources)
                original = next(r for r in self.sources[MATRIX].splitlines()
                                if r.startswith(f"| {ident} |"))
                promoted[MATRIX] = self.sources[MATRIX].replace(
                    original, original.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)
                self.assertEqual(assess(promoted)[ident]["documentation"], UNKNOWN)

    def test_sin_datos_reales(self):
        self.assertEqual(set(read_sources(ROOT)), {MATRIX, CONTRAST, SPEC})
        for ident, value in assess(self.sources).items():
            with self.subTest(ident=ident):
                self.assertEqual(value["documentation"], GAP)
                self.assertNotIn("token", value)
                self.assertNotIn("password", value)
                self.assertNotIn("customer", value)

    def test_estados_unknown_explicitos(self):
        result = assess(self.sources)
        for ident, status in result.items():
            with self.subTest(ident=ident):
                self.assertEqual(set(status), {"documentation", *OPERATIONAL_GATES})
                self.assertEqual(status["documentation"], GAP)
                self.assertTrue(all(status[gate] == UNKNOWN for gate in OPERATIONAL_GATES))
        for path in (MATRIX, CONTRAST, SPEC):
            missing = dict(self.sources)
            missing[path] = ""
            self.assertTrue(all(v["documentation"] == UNKNOWN
                                for v in assess(missing).values()))


if __name__ == "__main__":
    unittest.main()
