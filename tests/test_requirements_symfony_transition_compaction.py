from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"
CANONICAL = ROOT / "docs/STACK-TRANSITION-SYMFONY.md"
VERSION = ROOT / "config/version.php"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"

TRANSITION_HEADING = "## Transición al stack Symfony aprobada · nuevos contratos"
FUNCTIONAL_HEADING = "## Functional requirements"
EXPECTED_IDS = {
    "GF-ARCH-001",
    "GF-ARCH-002",
    "GF-ARCH-003",
    "GF-FR-008",
    "GF-FR-009",
    "GF-FR-010",
    "GF-FR-011",
    "GF-FR-012",
    "GF-FR-013",
    "GF-FR-014",
    "GF-FR-015",
    "GF-FR-016",
    "GF-FR-017",
    "GF-FR-018",
    "GF-FR-019",
    "GF-FR-020",
    "GF-FR-021",
    "GF-OPS-010",
    "GF-OPS-011",
    "GF-OPS-012",
    "GF-OPS-013",
    "GF-OPS-014",
    "GF-SEC-005",
    "GF-SEC-006",
    "GF-SEC-007",
    "GF-SEC-008",
    "GF-SEC-009",
    "GF-UX-001",
    "GF-UX-002",
    "GF-UX-003",
    "GF-UX-004",
    "GF-UX-005",
    "GF-UX-006",
    "GF-UX-007",
    "GF-UX-008",
}


class RequirementsSymfonyTransitionCompactionTests(unittest.TestCase):
    def test_transition_content_lives_only_in_canonical_document(self):
        requirements = REQUIREMENTS.read_text(encoding="utf-8")
        canonical = CANONICAL.read_text(encoding="utf-8")

        self.assertNotIn(TRANSITION_HEADING, requirements)
        self.assertEqual(canonical.count(TRANSITION_HEADING), 1)
        self.assertIn(
            "## 7. Contratos normativos consolidados desde REQUIREMENTS",
            canonical,
        )

    def test_transition_requirement_ids_are_preserved_once(self):
        requirements = REQUIREMENTS.read_text(encoding="utf-8")
        canonical = CANONICAL.read_text(encoding="utf-8")

        definitions = re.findall(
            r"^### (GF-[A-Z]+-\d{3})\b",
            canonical,
            flags=re.MULTILINE,
        )
        transition_ids = {requirement_id for requirement_id in definitions if requirement_id in EXPECTED_IDS}

        self.assertEqual(transition_ids, EXPECTED_IDS)
        for requirement_id in EXPECTED_IDS:
            self.assertEqual(
                len(
                    re.findall(
                        rf"^### {re.escape(requirement_id)}\b",
                        canonical,
                        flags=re.MULTILINE,
                    )
                ),
                1,
            )
            self.assertNotRegex(
                requirements,
                rf"(?m)^### {re.escape(requirement_id)}\b",
            )

    def test_requirements_links_transition_and_keeps_functional_requirements(self):
        requirements = REQUIREMENTS.read_text(encoding="utf-8")

        self.assertIn(
            "[STACK-TRANSITION-SYMFONY.md](STACK-TRANSITION-SYMFONY.md#transición-al-stack-symfony-aprobada--nuevos-contratos)",
            requirements,
        )
        self.assertEqual(requirements.count(FUNCTIONAL_HEADING), 1)
        functional = requirements.split(FUNCTIONAL_HEADING, 1)[1]
        self.assertIn("### GF-FR-001 — Organization isolation", functional)

    def test_transition_structure_is_not_duplicated(self):
        requirements = REQUIREMENTS.read_text(encoding="utf-8")
        canonical = CANONICAL.read_text(encoding="utf-8")

        self.assertEqual(
            requirements.count(TRANSITION_HEADING)
            + canonical.count(TRANSITION_HEADING),
            1,
        )
        self.assertNotIn("### GF-ARCH-001", requirements)
        self.assertIn("### GF-ARCH-001", canonical)
        self.assertIn("### GF-FR-021", canonical)

    def test_release_identity_matches_across_manifests(self):
        version_text = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        lock = json.loads(LOCK.read_text(encoding="utf-8"))

        match = re.search(r"'number'\s*=>\s*'([^']+)'", version_text)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertRegex(release, r"^\d+\.\d+\.\d+$")
        self.assertEqual(package["version"], release)
        self.assertEqual(lock["version"], release)
        self.assertEqual(lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
