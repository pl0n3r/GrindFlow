#!/usr/bin/env python3
"""Contrato de seguridad del bump Next.js, procedencia Dependabot #410."""
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SECURE_VERSION = "15.5.27"
NEXT_HASH = "sha512-F82CrlPZ8GRxBH9RIIoR3qU1t5RT3dv1K8moe8SjlDEquiFipvEI13R1Om90TaUOEwShkWCMlg8+gvTNzfMhvg=="
ENV_HASH = "sha512-WC5hvVqiuFOcRyz6gKPeJkiVvSMf3scNeAV49CdWtVqxmUlzh8UKR6YHt9yiNkh8LngtzqSlVz07/Ma3ZAzchA=="


# Referencia independiente: manifiesto de main@0760bc1c previo al parche Next.js.
# No se calcula desde el mismo package.json/package-lock que se está validando.
APPROVED_NON_NEXT_DEPENDENCIES = {
    "@aws-sdk/client-s3": "^3.708.0",
    "@aws-sdk/s3-request-presigner": "^3.708.0",
    "@supabase/ssr": "^0.12.7",
    "@supabase/supabase-js": "^2.47.10",
    "class-variance-authority": "^0.7.1",
    "clsx": "^2.1.1",
    "lucide-react": "^0.468.0",
    "next-intl": "^4.9.2",
    "react": "^19.0.0",
    "react-dom": "^19.0.0",
    "tailwind-merge": "^2.6.0",
    "tsx": "^4.23.13",
    "zod": "^3.24.1",
}
APPROVED_DEV_DEPENDENCIES = {
    "@eslint/eslintrc": "^3.2.0",
    "@tailwindcss/postcss": "^4.0.0",
    "@types/node": "^22.10.5",
    "@types/react": "^19.0.2",
    "@types/react-dom": "^19.0.2",
    "eslint": "^9.17.0",
    "eslint-config-next": "^15.1.3",
    "tailwindcss": "^4.0.0",
    "typescript": "^5.7.2",
    "vitest": "^4.1.11",
}
APPROVED_OVERRIDES = {
    "postcss": "8.5.28",
}


class NextSecurityLockContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        cls.lock = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))

    def test_next_manifest_and_lock_are_pinned_to_secure_patch(self):
        self.assertEqual(self.package["dependencies"]["next"], "^" + SECURE_VERSION)
        self.assertEqual(self.lock["packages"][""]["dependencies"]["next"], "^" + SECURE_VERSION)
        self.assertEqual(self.lock["packages"]["node_modules/next"]["version"], SECURE_VERSION)
        names = [k for k in self.lock["packages"] if k == "node_modules/@next/env"
                 or k.startswith("node_modules/@next/swc-")]
        self.assertGreaterEqual(len(names), 2)
        for name in names:
            row = self.lock["packages"][name]
            self.assertEqual(row["version"], SECURE_VERSION, name)
            self.assertTrue(row["integrity"].startswith("sha512-"), name)
            self.assertTrue(row["resolved"].startswith("https://registry.npmjs.org/"), name)

    def test_no_unrelated_dependency_or_symlinks_change(self):
        for name in ("package.json", "package-lock.json", "config/version.php", "README.md"):
            self.assertFalse((ROOT / name).is_symlink(), name)
        dependencies = self.package["dependencies"]
        dev = self.package["devDependencies"]
        # Una edición coincidente en manifest y lock ya no pasa: la baseline
        # independiente incluye TODAS las dependencias fuera del bump Next.
        non_next = {name: version for name, version in dependencies.items() if name != "next"}
        lock_non_next = {
            name: version
            for name, version in self.lock["packages"][""]["dependencies"].items()
            if name != "next"
        }
        self.assertEqual(non_next, APPROVED_NON_NEXT_DEPENDENCIES)
        self.assertEqual(lock_non_next, APPROVED_NON_NEXT_DEPENDENCIES)
        self.assertEqual(dev, APPROVED_DEV_DEPENDENCIES)
        self.assertEqual(self.lock["packages"][""]["devDependencies"], APPROVED_DEV_DEPENDENCIES)
        self.assertEqual(self.package["overrides"], APPROVED_OVERRIDES)
        # npm lockfile v3 no almacena overrides en packages[""]:
        # el manifiesto autorizado conserva esa fuente de verdad.
        self.assertEqual(self.lock["lockfileVersion"], 3)
        self.assertEqual(self.lock["packages"][""]["dependencies"], dependencies)
        self.assertEqual(self.lock["packages"][""]["devDependencies"], dev)
        # npm ci exige este peer opcional de SWC: el bot #410 lo omitía,
        # aunque @swc/core declara >=0.5.17 y el helper raíz es 0.5.15.
        core = self.lock["packages"]["node_modules/next-intl/node_modules/@swc/core"]
        self.assertEqual(core["peerDependencies"]["@swc/helpers"], ">=0.5.17")
        self.assertTrue(core["peerDependenciesMeta"]["@swc/helpers"]["optional"])
        helper = self.lock["packages"]["node_modules/next-intl/node_modules/@swc/helpers"]
        self.assertEqual(helper["version"], "0.5.23")
        self.assertEqual(
            helper["integrity"],
            "sha512-5lSsMOTXURePglDfvuAQUqkGek9Hg2kksOYay2m0+XR++b2NWYL/4sWyuvVBIs8oKnJaxkdi9whaL/sqN13afw==",
        )
        self.assertEqual(self.lock["packages"]["node_modules/@swc/helpers"]["version"], "0.5.15")

    def test_release_identity_matches_current_patch(self):
        # Regresión release-agnostic: #411 garantiza Next.js seguro,
        # mientras cada PR deploy-bound legítimo avanza el patch.
        php = (ROOT / "config/version.php").read_text(encoding="utf-8")
        versions = re.findall(r"'number'\\s*=>\\s*'([0-9]+\\.[0-9]+\\.[0-9]+)'", php)
        self.assertEqual(len(versions), 1)
        current = versions[0]
        self.assertEqual(self.package["version"], current)
        self.assertEqual(self.lock["version"], current)
        self.assertEqual(self.lock["packages"][""]["version"], current)
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        self.assertIn("v" + current, readme)
        self.assertIn("no equivale", readme.lower())

    def test_security_provenance_matches_dependabot_patch(self):
        # Integridad exacta del PR de procedencia #410, sin regenerar lockfile.
        next_row = self.lock["packages"]["node_modules/next"]
        env_row = self.lock["packages"]["node_modules/@next/env"]
        self.assertEqual(next_row["integrity"], NEXT_HASH)
        self.assertEqual(env_row["integrity"], ENV_HASH)
        self.assertEqual(next_row["version"], SECURE_VERSION)
        self.assertEqual(env_row["version"], SECURE_VERSION)
        self.assertEqual(next_row["resolved"],
                         "https://registry.npmjs.org/next/-/next-" + SECURE_VERSION + ".tgz")


if __name__ == "__main__":
    unittest.main()
