#!/usr/bin/env python3
"""Contrato del parche source-map-js Symfony, sin publicación ni red."""
from __future__ import annotations

import hashlib
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATCHED_VERSION = "1.2.2"
PATCHED_INTEGRITY = "sha512-KGj/8Y43x35aZVDtt+J4mK1hoLGHULMYfSkODJNQjNDC3oW1PqPoxMwo0pLUsWM/UEGzON/NxeHywEfNXNP3Vw=="
SYMFONY_MANIFEST_BLOB = "abac23238924adff5ede9a64c936d421169bdc67"


def read_json(path: str) -> dict:
    return json.loads((ROOT / path).read_text(encoding="utf-8"))


class SymfonySourceMapPatchTests(unittest.TestCase):
    def test_symfony_lockfile_uses_patched_source_map_js(self):
        symfony = read_json("symfony/package-lock.json")
        row = symfony["packages"]["node_modules/source-map-js"]
        self.assertEqual(row["version"], PATCHED_VERSION)
        self.assertEqual(row["resolved"], "https://registry.npmjs.org/source-map-js/-/source-map-js-1.2.2.tgz")
        self.assertEqual(row["integrity"], PATCHED_INTEGRITY)
        self.assertTrue(row["dev"])
        self.assertEqual(row["license"], "BSD-3-Clause")
        self.assertEqual(row["engines"], {"node": ">=0.10.0"})
        self.assertEqual(symfony["lockfileVersion"], 3)
        # Git blob SHA de main anterior al parche: se prohíbe editar el manifest Symfony.
        original = (ROOT / "symfony/package.json").read_bytes()
        digest = hashlib.sha1(b"blob " + str(len(original)).encode() + b"\x00" + original).hexdigest()
        self.assertEqual(digest, SYMFONY_MANIFEST_BLOB)

    def test_main_root_lock_is_not_downgraded(self):
        root = read_json("package-lock.json")
        row = root["packages"]["node_modules/source-map-js"]
        self.assertEqual(row["version"], PATCHED_VERSION)
        self.assertEqual(row["resolved"], "https://registry.npmjs.org/source-map-js/-/source-map-js-1.2.2.tgz")
        self.assertEqual(row["integrity"], PATCHED_INTEGRITY)
        self.assertEqual(root["lockfileVersion"], 3)

    def test_release_metadata_is_consistent(self):
        expected = "0.1.218"
        package = read_json("package.json")
        lock = read_json("package-lock.json")
        self.assertEqual(package["version"], expected)
        self.assertEqual(lock["version"], expected)
        self.assertEqual(lock["packages"][""]["version"], expected)
        php = (ROOT / "config/version.php").read_text(encoding="utf-8")
        versions = re.findall(r"'number'\s*=>\s*'([0-9.]+)'", php)
        self.assertEqual(versions, [expected])
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        self.assertIn("V" + expected, readme)
        self.assertIn("v" + expected, readme)
        self.assertIn("no equivale", readme.lower())


if __name__ == "__main__":
    unittest.main()
