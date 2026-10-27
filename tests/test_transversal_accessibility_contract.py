"""Regresión transversal S4: contratos de accesibilidad sobre UI y Playwright reales.

Esta auditoría offline verifica que el componente React y las pruebas de navegador
conservan sus aserciones ejecutables de foco, tablas y viewport. No sustituye la
ejecución de Chromium en symfony-preview ni acredita operación productiva.
"""
from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / "symfony/frontend/admin/ManualHandoffPanel.tsx"
TABLE_SPEC = ROOT / "symfony/tests/e2e/manualHandoffPanel.spec.mjs"
KEYBOARD_SPEC = ROOT / "symfony/tests/e2e/keyboard-accessibility.spec.mjs"
LOGIN_SPEC = ROOT / "symfony/tests/e2e/login-accessibility.spec.mjs"
PLAYWRIGHT = ROOT / "symfony/playwright.config.mjs"
CI = ROOT / ".github/workflows/grindflow-ci.yml"

TABLE_NAMES = (
    "Desplazamiento horizontal de entregas manuales",
    "Desplazamiento horizontal de destinos manuales",
)


def require(source: str, phrases: tuple[str, ...], surface: str) -> None:
    """Fail closed when a real implementation or browser assertion disappears."""
    missing = [phrase for phrase in phrases if phrase not in source]
    if missing:
        raise AssertionError(f"{surface}: missing executable contract: {missing}")


def audit_react_tables(source: str) -> None:
    """The keyboard stop must depend on actual overflow, not a static tabindex."""
    component = source.split("function HorizontalScrollRegion(", 1)
    if len(component) != 2:
        raise AssertionError("HorizontalScrollRegion missing")
    component = component[1].split("export function ManualHandoffPanel(", 1)[0]
    require(component, (
        "setHasOverflow(element.scrollWidth > element.clientWidth)",
        "updateOverflow();",
        "new ResizeObserver(updateOverflow)",
        "observer?.observe(element)",
        "window.addEventListener('resize', updateOverflow)",
        "observer?.disconnect()",
        "window.removeEventListener('resize', updateOverflow)",
        "style={{ maxWidth: '100%', overflowX: 'auto' }}",
        "tabIndex={hasOverflow ? 0 : undefined}",
        "role={hasOverflow ? 'region' : undefined}",
        "aria-label={hasOverflow ? label : undefined}",
    ), "React scrolling region")
    require(source, (
        ".manual-handoff-scroll-region:focus-visible",
        "outline: 2px solid currentColor",
        "minWidth: 640",
        "minWidth: 440",
        '<th scope="col">',
    ) + TABLE_NAMES, "Two independent real tables")
    if source.count("<HorizontalScrollRegion label=") != 2:
        raise AssertionError("Both tables must use the dynamic focus component")


def audit_browser_tables(source: str) -> None:
    """Playwright must exercise the mounted UI, including negative/fallback paths."""
    require(source, (
        "for (const width of [360, 820])",
        "await page.setViewportSize({ width, height: 900 })",
        "await page.goto('/preview')",
        "await page.addScriptTag(",
        "const wrappers = section.locator('.manual-handoff-scroll-region')",
        "await expect(wrappers).toHaveCount(2)",
        "element.scrollWidth > element.clientWidth",
        "if (width === 360) expect(overflowing).toBe(true)",
        ".toBe(overflowing ? '0' : null)",
        "await expect(wrapper).toHaveAttribute('role', 'region')",
        "await expect(wrapper).not.toHaveAttribute('role')",
        "await expect(wrapper).toHaveAttribute('aria-label', labels[index])",
        "await expect(wrapper).not.toHaveAttribute('aria-label')",
        "await page.keyboard.press('Tab')",
        "await expect(wrapper).toBeFocused()",
        "getComputedStyle(element).outlineStyle",
        "expect(visibleOutline).not.toBe('none')",
        "await page.keyboard.press('ArrowRight')",
        ".toBeGreaterThan(before)",
        "await page.keyboard.press('Shift+Tab')",
        "document.documentElement.scrollWidth <= window.innerWidth",
        "await page.setViewportSize({ width: 360, height: 900 })",
        "request.path === path && request.method === 'GET'",
        "expect(requested.filter((request) => request.method !== 'GET'",
        "&& request.method !== 'HEAD')).toEqual([])",
        "for (const scenario of ['lista', 'vacio', '403', 'red', 'desconocido'])",
    ) + TABLE_NAMES, "Playwright real-table scenarios")
    if source.count("for (const width of [360, 820])") != 1:
        raise AssertionError("Viewport test loop must be unique")


def audit_other_surfaces(keyboard: str, login: str) -> None:
    """Transversal focus and viewport checks cannot regress outside tables."""
    require(keyboard, (
        "for (const width of [360, 820])",
        "await page.keyboard.press('Tab')",
        "await page.keyboard.press('Enter')",
        "await expect(skip).toBeFocused()",
        "await expect(page.locator('main#contenido[tabindex=\"-1\"]')).toBeFocused()",
        "outlineStyle: getComputedStyle(node).outlineStyle",
        "outlineWidth: getComputedStyle(node).outlineWidth",
        "document.documentElement.scrollWidth",
    ), "Keyboard navigation and skip link")
    require(login, (
        "for (const width of [360, 820])",
        "await expect(error).toHaveAttribute('role', 'alert')",
        "await expect(input).toHaveAttribute('aria-invalid', 'true')",
        "await expect(input).toHaveAccessibleDescription(",
        "await expect(password).toHaveValue('')",
        "document.documentElement.scrollWidth",
    ), "Login form and error semantics")


def audit_ci(playwright: str, workflow: str) -> None:
    require(playwright, (
        "testDir: './tests/e2e'",
        "browserName: 'chromium'",
        "retries: 0",
    ), "Real Playwright execution")
    require(workflow, (
        "  symfony-preview:",
        "npm run test:e2e",
        "npx playwright install",
        "  validate:",
        "SYMFONY_RESULT:",
    ), "Required symfony-preview browser gate")


class TransversalAccessibilityTests(unittest.TestCase):
    def test_focus_tables_and_viewport_contract(self) -> None:
        audit_react_tables(PANEL.read_text(encoding="utf-8"))
        audit_browser_tables(TABLE_SPEC.read_text(encoding="utf-8"))
        audit_other_surfaces(
            KEYBOARD_SPEC.read_text(encoding="utf-8"),
            LOGIN_SPEC.read_text(encoding="utf-8"),
        )
        audit_ci(
            PLAYWRIGHT.read_text(encoding="utf-8"),
            CI.read_text(encoding="utf-8"),
        )

    def test_negative_mutations_fail_closed(self) -> None:
        sources = (
            (audit_react_tables, PANEL, (
                "setHasOverflow(element.scrollWidth > element.clientWidth)",
                "tabIndex={hasOverflow ? 0 : undefined}",
                "observer?.disconnect()",
                "aria-label={hasOverflow ? label : undefined}",
            )),
            (audit_browser_tables, TABLE_SPEC, (
                "await expect(wrapper).toBeFocused()",
                "await page.keyboard.press('ArrowRight')",
                "await page.keyboard.press('Shift+Tab')",
                "document.documentElement.scrollWidth <= window.innerWidth",
                "expect(requested.filter((request) => request.method !== 'GET'",
            )),
        )
        for auditor, path, tokens in sources:
            original = path.read_text(encoding="utf-8")
            for token in tokens:
                with self.subTest(source=path.name, removed=token):
                    self.assertIn(token, original)
                    with self.assertRaises(AssertionError):
                        auditor(original.replace(token, "REGRESSION_REMOVED", 1))

    def test_missing_ci_or_cross_module_focus_fails_closed(self) -> None:
        keyboard = KEYBOARD_SPEC.read_text(encoding="utf-8")
        login = LOGIN_SPEC.read_text(encoding="utf-8")
        with self.assertRaises(AssertionError):
            audit_other_surfaces(keyboard.replace("await page.keyboard.press('Tab')", "", 1), login)
        with self.assertRaises(AssertionError):
            audit_other_surfaces(keyboard, login.replace(
                "await expect(input).toHaveAccessibleDescription(", "", 1
            ))
        workflow = CI.read_text(encoding="utf-8")
        with self.assertRaises(AssertionError):
            audit_ci(
                PLAYWRIGHT.read_text(encoding="utf-8"),
                workflow.replace("npm run test:e2e", "true", 1),
            )


if __name__ == "__main__":
    unittest.main()
