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

    def test_readme_has_contract_v1_anatomy(self):
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
        for heading in headings:
            self.assertEqual(readme.count(heading), 1, heading)
        self.assertNotIn("# GrindFlow — Último deploy", readme)
        self.assertNotIn("## Archivos modificados en esta entrega candidata", readme)
        self.assertNotIn("## Qué se hizo", readme)

    def test_derived_blocks_fail_closed_without_evidence(self):
        readme = README.read_text(encoding="utf-8")
        status_start = "<!-- factory:status:start -->"
        status_end = "<!-- factory:status:end -->"
        progress_start = "<!-- factory:progress-readiness:start -->"
        progress_end = "<!-- factory:progress-readiness:end -->"
        self.assertEqual(readme.count(status_start), 1)
        self.assertEqual(readme.count(status_end), 1)
        self.assertEqual(readme.count(progress_start), 1)
        self.assertEqual(readme.count(progress_end), 1)

        status = readme.split(status_start, 1)[1].split(status_end, 1)[0]
        for label in (
            "main SHA", "versión", "CI", "release", "health", "smoke/observer",
            "quality/security", "Issue activo", "PR activo", "último release",
        ):
            self.assertIn(f"| {label} | UNKNOWN |", status)
        self.assertNotIn("| GREEN |", status)
        self.assertNotIn("| DEGRADED |", status)

        progress = readme.split(progress_start, 1)[1].split(progress_end, 1)[0]
        for label in (
            "Target", "Progress", "Readiness", "Evidence freshness",
            "Critical blockers", "Trend",
        ):
            self.assertIn(f"| {label} | UNKNOWN |", progress)
        self.assertIn("| UNKNOWN | UNKNOWN | UNKNOWN |", progress)

    def test_consumer_workflow_uses_factory_v1(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("uses: pl0n3r/factory/.github/workflows/readme.yml@v1", workflow)
        self.assertIn("readme_path: README.md", workflow)
        self.assertIn("metadata_path: readme/project.json", workflow)
        self.assertIn("permissions:\n  contents: read\n", workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertNotIn("@main", workflow)
        self.assertNotIn("secrets:", workflow)

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
