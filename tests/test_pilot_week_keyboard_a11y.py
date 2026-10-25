"""Acceptance for the S5 pilot weekly selector's real Playwright spec.

Static discovery is separate from actual browser CI execution:
symfony-preview must run the Playwright suite on the exact PR HEAD.
"""
from __future__ import annotations

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]
SPEC = ROOT / "symfony/tests/e2e/pilot-week-keyboard-a11y.spec.mjs"
WORKFLOW = ROOT / ".github/workflows/grindflow-ci.yml"
PLAYWRIGHT = ROOT / "symfony/playwright.config.mjs"


class PilotWeekKeyboardA11yTests(unittest.TestCase):
    @staticmethod
    def _check_scenarios(source: str) -> None:
        required = (
            "for (const width of [360, 820])",
            "page.getByRole('navigation', { name: 'Semanas del piloto' })",
            "getByRole('button', { name: 'Semana anterior' })",
            "getByRole('button', { name: 'Semana siguiente' })",
            "toHaveAccessibleName",
            "page.keyboard.press('Tab')",
            "page.keyboard.press('Shift+Tab')",
            "page.keyboard.press('Enter')",
            "page.keyboard.press('Space')",
            "toBeFocused()",
            "focusVisible",
            "outlineWidth",
            "page.getByRole('status')",
            "getByRole('alert')",
            "status: 503",
            "document.documentElement.scrollWidth",
            "unsafeRequests",
            "request.method()",
            "route.fulfill",
            "'**/api/admin/context'",
            "'**/api/admin/pilot/weekly-summary?week=*'",
        )
        for token in required:
            if token not in source:
                raise AssertionError(f"Missing real-browser scenario requirement: {token}")
        if not re.search(r"test\('S5 pilot week selector", source):
            raise AssertionError("Playwright must discover an executable S5 test")
        if "locator.click(" in source or "previous.click(" in source:
            raise AssertionError("The selector must be exercised using keyboard events")

    def test_acceptance(self) -> None:
        self.assertTrue(SPEC.is_file(), "Playwright spec is missing")
        self.assertTrue(WORKFLOW.is_file(), "Canonical CI workflow is missing")
        self.assertTrue(PLAYWRIGHT.is_file(), "Playwright discovery config is missing")
        self._check_scenarios(SPEC.read_text(encoding="utf-8"))

        config = PLAYWRIGHT.read_text(encoding="utf-8")
        self.assertIn("testDir: './tests/e2e'", config)
        self.assertIn("browserName: 'chromium'", config)
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("  symfony-preview:", workflow)
        symfony = workflow.split("  symfony-preview:", 1)[1].split("\n  validate:", 1)[0]
        self.assertIn("npm run test:e2e", symfony)
        self.assertIn("npx playwright install", symfony)
        self.assertIn("  browser:", workflow)
        self.assertIn("    name: validate", workflow)

    def test_missing_keyboard_or_failure_state_fails_closed(self) -> None:
        source = SPEC.read_text(encoding="utf-8")
        for token in ("page.keyboard.press('Space')", "status: 503", "toBeFocused()"):
            with self.subTest(missing=token):
                with self.assertRaisesRegex(AssertionError, "Missing real-browser"):
                    self._check_scenarios(source.replace(token, "removed"))


if __name__ == "__main__":
    unittest.main()
