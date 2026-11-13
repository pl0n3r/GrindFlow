"""Regresiones de versiones relacionadas del frontend Symfony (npm)."""
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "symfony"
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))


class SymfonyNpmDependencyGroupTests(unittest.TestCase):
    def test_react_versions_are_aligned(self):
        packages = LOCK["packages"]
        self.assertEqual(packages["node_modules/react"]["version"], "19.3.1")
        self.assertEqual(packages["node_modules/react-dom"]["version"], "19.3.1")
        self.assertEqual(
            packages["node_modules/react-dom"]["peerDependencies"]["react"], "^19.3.1"
        )

    def test_playwright_packages_are_aligned(self):
        packages = LOCK["packages"]
        for package in ("@playwright/test", "playwright", "playwright-core"):
            self.assertEqual(packages[f"node_modules/{package}"]["version"], "1.64.1")
        self.assertEqual(
            packages["node_modules/@playwright/test"]["dependencies"]["playwright"],
            "1.64.1",
        )
        self.assertEqual(
            packages["node_modules/playwright"]["dependencies"]["playwright-core"],
            "1.64.1",
        )

    def test_package_manifest_matches_lock(self):
        root = LOCK["packages"][""]
        for key in ("react", "react-dom"):
            self.assertEqual(PACKAGE["dependencies"][key], "^19.3.1")
            self.assertEqual(root["dependencies"][key], PACKAGE["dependencies"][key])
        self.assertEqual(PACKAGE["devDependencies"]["@playwright/test"], "1.64.1")
        self.assertEqual(
            root["devDependencies"]["@playwright/test"],
            PACKAGE["devDependencies"]["@playwright/test"],
        )


if __name__ == "__main__":
    unittest.main()
