"""Contracts that keep high-volume GitHub event surfaces explicit and bounded."""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"


def read(name: str) -> str:
    return (WORKFLOWS / name).read_text(encoding="utf-8")


class ActionEventLoadTests(unittest.TestCase):
    def test_production_bridges_are_explicit_owner_dispatches(self):
        diagnostics = read("production-diagnostics.yml")
        migration = read("production-migration.yml")

        for source in (diagnostics, migration):
            self.assertIn("  workflow_dispatch:", source)
            self.assertNotIn("  issue_comment:", source)
            self.assertIn("github.actor == github.repository_owner", source)
            self.assertNotIn("issues: write", source)
            self.assertNotIn("gh issue comment", source)
            self.assertIn("GITHUB_STEP_SUMMARY", source)

        self.assertIn("expected_pending:", migration)
        self.assertNotIn("backup_receipt:", migration)
        self.assertNotIn("BACKUP_RECEIPT", migration)
        self.assertNotIn("backup_verified:", migration)
        self.assertIn("EXPECTED_PENDING: ${{ inputs.expected_pending }}", migration)
        self.assertIn("github.triggering_actor == github.repository_owner", migration)

        job_header = migration.split("    steps:", 1)[0]
        self.assertNotIn("PRODUCTION_E2E_PASSWORD", job_header)
        self.assertEqual(
            migration.count("E2E_USER_PASSWORD: ${{ secrets.PRODUCTION_E2E_PASSWORD }}"),
            2,
        )

    def test_global_check_run_sonar_relay_is_removed(self):
        self.assertFalse((WORKFLOWS / "sonar-pr-details.yml").exists())

    def test_agents_document_low_noise_collaboration(self):
        agents = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
        for rule in (
            "No hacer polling de checks, PRs ni comentarios",
            "Agrupar cambios locales y hacer un solo push por bloque lógico",
            "Publicar un solo comentario por hito",
            "No exceder dos agentes simultáneos en GrindFlow",
        ):
            self.assertIn(rule, agents)


if __name__ == "__main__":
    unittest.main()
