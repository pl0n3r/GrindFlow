import fnmatch
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
README = ROOT / "README.md"
METADATA = ROOT / "readme" / "project.json"
WORKFLOW = ROOT / ".github" / "workflows" / "readme-contract.yml"
CI_WORKFLOW = ROOT / ".github" / "workflows" / "grindflow-ci.yml"
VERSION_PHP = ROOT / "config" / "version.php"
PACKAGE_JSON = ROOT / "package.json"
PACKAGE_LOCK = ROOT / "package-lock.json"

class ReadmeContractAdoptionTests(unittest.TestCase):
    def test_project_metadata_is_stable_and_complete(self):
        metadata = json.loads(METADATA.read_text(encoding="utf-8"))
        required = {"name", "tagline", "role", "phase", "roadmap", "stack"}
        forbidden = {
            "main_sha", "version", "ci", "release", "health", "smoke",
            "quality", "active_issue", "active_pr", "last_release",
        }
        self.assertEqual(set(metadata), required)
        self.assertEqual(metadata["name"], "GrindFlow")
        self.assertEqual(metadata["role"], "product")
        self.assertEqual(metadata["phase"], "construction")
        self.assertIn("#2", metadata["roadmap"])
        self.assertTrue(forbidden.isdisjoint(metadata))
        self.assertTrue(all(isinstance(value, str) and value.strip() for value in metadata.values()))

    def test_readme_is_contract_v2_without_unknown_blocks(self):
        readme = README.read_text(encoding="utf-8")
        headings = [
            "## Operational Cockpit",
            "## Work Queue",
            "## Qué hace el producto",
            "## Arquitectura en 60 segundos",
            "## Stack e infraestructura",
            "## Ciclo de entrega",
            "## Calidad y seguridad",
            "## Roadmap y fuentes de verdad",
            "## Desarrollo local",
            "## Mapa de la fábrica",
        ]
        self.assertTrue(readme.startswith("# GrindFlow\n"))
        positions = []
        for heading in headings:
            self.assertEqual(readme.count(heading), 1, heading)
            positions.append(readme.index(heading))
        self.assertEqual(positions, sorted(positions))
        for stale in (
            "UNKNOWN", "factory:status", "factory:progress-readiness",
            "Progress + Readiness", "| Señal | Estado |",
            "| Dimensión | Progress | Readiness |",
        ):
            self.assertNotIn(stale, readme, stale)
        cockpit = readme.split("## Operational Cockpit", 1)[1].split("## Work Queue", 1)[0]
        self.assertIn(
            "https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main",
            cockpit,
        )
        self.assertIn(
            "https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml?query=branch%3Amain",
            cockpit,
        )
        self.assertIn("https://img.shields.io/github/v/release/pl0n3r/GrindFlow", cockpit)
        self.assertIn("https://github.com/pl0n3r/GrindFlow/releases", cockpit)
        self.assertIn("https://control.condorapp.com.co/", cockpit)
        self.assertIn("acceso restringido", cockpit)
        self.assertNotIn("| --- |", cockpit)

    def test_readme_v2_keeps_operational_authority_outside_the_cover(self):
        readme = README.read_text(encoding="utf-8")
        self.assertIn("config/version.php", readme)
        self.assertIn("no demuestra el SHA desplegado", readme)
        self.assertIn("https://github.com/pl0n3r/GrindFlow/issues/2", readme)
        self.assertIn("https://github.com/pl0n3r/GrindFlow/issues/414", readme)
        self.assertIn("https://github.com/pl0n3r/GrindFlow/issues/407", readme)
        self.assertIn("https://github.com/pl0n3r/GrindFlow/issues/271", readme)
        self.assertIn("https://github.com/pl0n3r/GrindFlow/issues/453", readme)
        self.assertNotIn("## Archivos modificados en esta entrega candidata", readme)
        self.assertNotIn("## Qué se hizo", readme)

    def test_consumer_workflow_uses_factory_v1(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("uses: pl0n3r/factory/.github/workflows/readme.yml@v1", workflow)
        self.assertIn("readme_path: README.md", workflow)
        self.assertIn("metadata_path: readme/project.json", workflow)
        self.assertIn("permissions:\n  contents: read\n", workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertNotIn("@main", workflow)
        self.assertNotIn("secrets:", workflow)

    @staticmethod
    def _f2b_event_blocks():
        workflow = WORKFLOW.read_text(encoding="utf-8")
        event_yaml = workflow.split("\non:\n", 1)[1].split("\npermissions:\n", 1)[0]
        names = [line.strip().split(":", 1)[0] for line in event_yaml.splitlines()
                 if line.startswith("  ") and not line.startswith("    ")]
        if names != ["pull_request", "push", "workflow_dispatch"]:
            raise AssertionError(f"Eventos no canónicos: {names}")
        pr = event_yaml.split("  pull_request:\n", 1)[1].split("  push:\n", 1)[0]
        push = event_yaml.split("  push:\n", 1)[1].split("  workflow_dispatch:\n", 1)[0]
        return workflow, pr, push

    @staticmethod
    def _f2b_paths(block):
        paths = [line.split("'")[1] for line in block.splitlines()
                 if line.startswith("      - '") and line.endswith("'")]
        if len(paths) != 4 or block.count("    paths:\n") != 1:
            raise AssertionError("Filtro paths ausente, no literal o inesperado")
        return paths

    def test_f2b_readme_workflow_events_and_positive_paths(self):
        workflow, pr, push = self._f2b_event_blocks()
        expected = [
            "README.md", "readme/**",
            ".github/workflows/readme-contract.yml",
            "tests/test_readme_contract_adoption.py",
        ]
        for event in (pr, push):
            self.assertIn("    branches: [main]\n", event)
            self.assertEqual(self._f2b_paths(event), expected)
        self.assertIn("    types: [opened, synchronize, reopened, ready_for_review]\n", pr)
        self.assertNotIn("    types:", push)
        self.assertEqual(workflow.count("  workflow_dispatch:\n"), 1)

    def test_f2b_readme_workflow_rejects_unrelated_paths(self):
        _, pr, push = self._f2b_event_blocks()
        relevant = [
            "README.md", "readme/project.json",
            ".github/workflows/readme-contract.yml",
            "tests/test_readme_contract_adoption.py",
        ]
        unrelated = [
            "src/App.php", "symfony/frontend/src/main.tsx",
            "tests/test_login.py", "composer.json",
            ".github/workflows/grindflow-ci.yml",
        ]
        for event in (pr, push):
            paths = self._f2b_paths(event)
            for path in relevant:
                self.assertTrue(any(fnmatch.fnmatchcase(path, rule)
                                    for rule in paths), path)
            for path in unrelated:
                self.assertFalse(any(fnmatch.fnmatchcase(path, rule)
                                     for rule in paths), path)
            self.assertNotIn("**", paths)
            self.assertNotIn("src/**", paths)
            self.assertNotIn("tests/**", paths)
            self.assertNotIn("symfony/**", paths)

    def test_f2b_readme_reusable_security_is_unchanged(self):
        workflow, _, _ = self._f2b_event_blocks()
        self.assertEqual(workflow.count("  validate:\n"), 1)
        self.assertIn("permissions:\n  contents: read\n", workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertNotIn("secrets:", workflow)
        self.assertIn("  cancel-in-progress: true\n", workflow)
        self.assertIn(
            "    if: github.event_name != 'pull_request' || github.event.pull_request.draft == false\n",
            workflow,
        )
        self.assertIn("    uses: pl0n3r/factory/.github/workflows/readme.yml@v1\n", workflow)
        self.assertIn("      readme_path: README.md\n", workflow)
        self.assertIn("      metadata_path: readme/project.json\n", workflow)
        self.assertIn("  workflow_dispatch:\n", workflow)

    def test_release_version_artifacts_stay_aligned(self):
        version_php = VERSION_PHP.read_text(encoding="utf-8")
        package = json.loads(PACKAGE_JSON.read_text(encoding="utf-8"))
        package_lock = json.loads(PACKAGE_LOCK.read_text(encoding="utf-8"))
        marker = "'number' => '"
        self.assertIn(marker, version_php)
        version = version_php.split(marker, 1)[1].split("'", 1)[0]
        self.assertEqual(package["version"], version)
        self.assertEqual(package_lock["version"], version)
        self.assertEqual(package_lock["packages"][""]["version"], version)

    def test_grindflow_ci_delegates_contract_v1_without_legacy_dashboard(self):
        workflow = CI_WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("if [[ -f readme/project.json ]]; then", workflow)
        self.assertIn(
            "README Contract v1 validation is delegated to the dedicated reusable workflow.",
            workflow,
        )
        delegated = workflow.split("if [[ -f readme/project.json ]]; then", 1)[1].split("fi", 1)[0]
        self.assertNotIn("scripts/readme-dashboard.py", delegated)
        self.assertIn("python3 scripts/readme-dashboard.py --update", workflow)
        self.assertIn(
            "python3 -m unittest tests/test_readme_contract_adoption.py",
            workflow,
        )

    def test_work_queue_links_canonical_roadmap(self):
        readme = README.read_text(encoding="utf-8")
        queue = readme.split("## Work Queue", 1)[1].split("## Qué hace el producto", 1)[0]
        for label in ("NOW", "NEXT", "LATER", "BLOCKED"):
            self.assertEqual(queue.count(f"**{label}:**"), 1)
        self.assertIn("https://github.com/pl0n3r/GrindFlow/issues/2", queue)
        self.assertIn("Esta vista resume", queue)
        self.assertNotIn("## Huella del cambio", queue)

    def test_factory_map_preserves_roles(self):
        readme = README.read_text(encoding="utf-8")
        factory_map = readme.split("## Mapa de la fábrica", 1)[1]
        expected = (
            "**Factory:** governance/kit",
            "**ControlBot:** control plane privado",
            "**FactoryRunner:** execution plane",
            "**GrindFlow:** **producto actual**",
            "**AutoFactory:** herramienta local/manual",
        )
        for role in expected:
            self.assertIn(role, factory_map)
        self.assertIn("**Condor:** producto", factory_map)
        self.assertIn("**BRVTAL:** producto", factory_map)

if __name__ == "__main__":
    unittest.main()
