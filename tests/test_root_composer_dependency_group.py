"""Regression for root Composer minor dependency group."""
from __future__ import annotations
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPOSER = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "composer.lock").read_text(encoding="utf-8"))
PACKAGES = {p["name"]: p for p in LOCK["packages"] + LOCK["packages-dev"]}


class RootComposerDependencyGroupTests(unittest.TestCase):
    def test_laravel_and_livewire_versions(self):
        self.assertEqual(PACKAGES["laravel/framework"]["version"], "v13.40.0")
        self.assertEqual(PACKAGES["livewire/livewire"]["version"], "v4.4.8")

    def test_larastan_and_phpunit_versions(self):
        self.assertEqual(PACKAGES["larastan/larastan"]["version"], "v3.14.0")
        self.assertEqual(PACKAGES["phpunit/phpunit"]["version"], "12.5.43")

    def test_symfony_console_version(self):
        self.assertEqual(PACKAGES["symfony/console"]["version"], "v8.1.11")

    def test_lock_structure_is_consistent(self):
        installed = LOCK["packages"] + LOCK["packages-dev"]
        self.assertEqual(len(installed), len(PACKAGES))
        self.assertRegex(LOCK["content-hash"], r"^[0-9a-f]{32}$")
        for name in COMPOSER["require"]:
            if name not in {"php"} and not name.startswith("ext-"):
                self.assertIn(name, PACKAGES)
        for name in COMPOSER["require-dev"]:
            self.assertIn(name, PACKAGES)


if __name__ == "__main__":
    unittest.main()
