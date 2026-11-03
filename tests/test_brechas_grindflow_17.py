#!/usr/bin/env python3
"""GrindFlow #724: contrato offline de STACK:GF-UX-003.

La revisión de fuentes versionadas acredita únicamente cobertura local:
no ejecuta Chromium, no cambia la matriz y no implica CI exact-HEAD,
despliegue ni validación de producción.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDENTIFIER = "STACK:GF-UX-003"
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/STACK-TRANSITION-SYMFONY.md"
NAV = "symfony/frontend/admin/WorkspaceNavigation.tsx"
PREVIEW = "symfony/frontend/admin/PreviewApp.tsx"
ADMIN = "symfony/frontend/admin/AdminApp.tsx"
CSS = "symfony/frontend/admin/workspace-navigation.css"
BROWSER = "symfony/tests/e2e/preview.spec.mjs"
FILES = (MATRIX, SPEC, NAV, PREVIEW, ADMIN, CSS, BROWSER)
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"


def matrix_row(document: str) -> tuple[str, ...]:
    """Una sola fila de ocho columnas; duplicados, ausencia o rotura son UNKNOWN."""
    rows = []
    for line in document.splitlines():
        if not line.startswith(f"| {IDENTIFIER} |"):
            continue
        fields = tuple(field.strip() for field in line.strip("|").split("|"))
        if len(fields) != 8:
            raise ValueError("UNKNOWN: fila incompleta")
        rows.append(fields)
    if len(rows) != 1:
        raise ValueError("UNKNOWN: falta fila o está duplicada")
    return rows[0]


def scenario(document: str, title: str) -> str:
    """Recortar la prueba Playwright concreta; no usar otra prueba como evidencia."""
    marker = "test('" + title + "'"
    if document.count(marker) != 1:
        return ""
    tail = document.split(marker, 1)[1]
    return tail.split("\ntest(", 1)[0]


def inspect_local(row: object, files: dict[str, str]) -> dict[str, str]:
    """Los checks estáticos nunca conceden estado de ejecución o producción."""
    result = {"code": UNKNOWN, "browser": UNKNOWN, "deployment": UNKNOWN,
              "production": UNKNOWN}
    if (not isinstance(row, tuple) or len(row) != 8
            or row[0] != IDENTIFIER or row[2] != "NO_VERIFICABLE"
            or row[5:8] != ("NO_ACREDITADO",) * 3
            or "STACK-TRANSITION-SYMFONY.md:L109" not in row[3]):
        return result
    required = {
        SPEC: ("### GF-UX-003 — Navegación coherente entre espacios Symfony",
               "360 y 820 px", "aria-pressed", "aria-current"),
        NAV: ("export function WorkspaceNavigation(", "mode === 'preview'",
              "aria-pressed={selected}", "aria-current={selected ?",
              "useLayoutEffect(", "scrollLeft +=", "window.addEventListener('resize'"),
        PREVIEW: ('<WorkspaceNavigation mode="preview"', 'onSelect={(id) =>',
                  'aria-live="polite"', 'aria-atomic="true"'),
        ADMIN: ('<WorkspaceNavigation mode="admin"', "href: '/admin'",
                "href: '#programacion'"),
        CSS: (".workspace-navigation nav{display:flex", "overflow-x:auto",
              ".workspace-navigation-scroll-hint{display:block"),
    }
    if not all(token in files.get(path, "") for path, tokens in required.items()
               for token in tokens):
        return result
    tests = files.get(BROWSER, "")
    keyboard = scenario(tests, "React navigation changes visible section without claiming real data")
    mobile = scenario(tests, "mobile navigation reveals active preview and private sections without page overflow")
    if not all(token in keyboard for token in (
        "page.keyboard.press('Enter')", "page.keyboard.press('Space')",
        "toHaveAttribute('aria-pressed', 'true')", "Concepto de Tráfico",
    )):
        return result
    if not all(token in mobile for token in (
        "width: 360", "width: 820", "fullyVisible(preview)",
        "fullyVisible(admin)", "scrollLeft", "document.documentElement.scrollWidth",
        "'Navegación administrativa'", "'Explorador de secciones'",
        "'#programacion'", "beforeDocumentScroll",
    )):
        return result
    result["code"] = LOCAL
    return result


def operational_attestation(claim: object = None) -> str:
    """Un test sintético, check verde o supuesto smoke no acredita Hostinger."""
    return UNKNOWN


class BrechasGrindflow17Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {path: (ROOT / path).read_text(encoding="utf-8")
                       for path in FILES}
        cls.row = matrix_row(cls.sources[MATRIX])

    def test_contrato_principal(self) -> None:
        """AC-01: código compartido, teclado, móvil y admin sintético cubiertos."""
        self.assertEqual(self.row[0], IDENTIFIER)
        self.assertEqual(self.row[1], "Navegación coherente entre espacios Symfony — Enunciado: la vista previa React y el admin privado comparten un componente de navegación y una convención visual:")
        self.assertEqual(inspect_local(self.row, self.sources)["code"], LOCAL)
        nav = self.sources[NAV]
        self.assertIn("return mode === 'preview' ? (", nav)
        self.assertIn("<button key={item.id}", nav)
        self.assertIn("<a key={item.id} href={item.href ?? '#contenido'}", nav)
        self.assertIn("Desliza para explorar más secciones", nav)

    def test_falla_cerrado(self) -> None:
        """AC-02: ambiguo, incompleto, sin navegación o sin test -> UNKNOWN."""
        line = next(line for line in self.sources[MATRIX].splitlines()
                    if line.startswith(f"| {IDENTIFIER} |"))
        for broken in (self.sources[MATRIX].replace(line + "\n", "", 1),
                       self.sources[MATRIX] + "\n" + line,
                       self.sources[MATRIX].replace(line, f"| {IDENTIFIER} | roto |", 1)):
            with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                matrix_row(broken)
        altered = list(self.row)
        altered[2] = "CUBIERTO"
        self.assertEqual(inspect_local(tuple(altered), self.sources)["code"], UNKNOWN)
        for name, token in (
            (NAV, "aria-current={selected ?"),
            (PREVIEW, 'aria-live="polite"'),
            (CSS, "overflow-x:auto"),
            (BROWSER, "fullyVisible(admin)"),
            (SPEC, "### GF-UX-003 — Navegación coherente entre espacios Symfony"),
        ):
            with self.subTest(path=name):
                modified = dict(self.sources)
                modified[name] = modified[name].replace(token, "CONTRATO_ELIMINADO")
                self.assertEqual(inspect_local(self.row, modified)["code"], UNKNOWN)
        self.assertEqual(inspect_local((), self.sources)["code"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fuentes públicas locales, sin red, ejecución externa o PII."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|urllib|socket|subprocess|httpx)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(set(self.sources), set(FILES))
        for name in FILES:
            self.assertFalse(Path(name).is_absolute())
            self.assertTrue((ROOT / name).is_file())
        self.assertEqual(operational_attestation({"tenant": "synthetic"}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: el CI y Chromium se verifican por separado, nunca se infieren."""
        self.assertEqual(self.row[2], "NO_VERIFICABLE")
        self.assertEqual(self.row[5:8], ("NO_ACREDITADO",) * 3)
        proof = inspect_local(self.row, self.sources)
        self.assertEqual(proof, {"code": LOCAL, "browser": UNKNOWN,
                                 "deployment": UNKNOWN, "production": UNKNOWN})
        for claim in (None, {}, True, {"ci": "success"},
                      {"chromium": "passed", "synthetic": True},
                      {"state": "VALIDATED_IN_PRODUCTION", "sha": "a" * 40}):
            self.assertEqual(operational_attestation(claim), UNKNOWN)
        self.assertEqual(scenario("", "no existe"), "")


if __name__ == "__main__":
    unittest.main()
