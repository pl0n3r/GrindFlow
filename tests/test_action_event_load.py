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
            "telemetría CI se muestrea una vez por hora",
        ):
            with self.subTest(rule=rule):
                self.assertIn(rule, agents)
        self.assertNotIn("No exceder dos agentes simultáneos en GrindFlow", agents)
        self.assertNotIn("Se permiten hasta **4 lineas de trabajo concurrentes**", agents)

    def test_agents_capacity_is_claim_based_not_fixed(self):
        agents = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
        required = (
            "Capacidad por claims, sin tope fijo de agentes por repo",
            "claims exactos de archivos disjuntos",
            "reserva V3 `active:true`",
            "nunca tomar una ruta ya reclamada",
            "ni reservar un directorio completo",
            "1200 solicitudes",
            "cupo restante de la API de GitHub autenticada",
            "si el PLAN-AGENTES global impone un límite menor, prevalece",
            "Un cupo UNKNOWN no autoriza nuevas reservas",
            "claims de archivo disjuntos",
            "sin tope fijo de agentes por repo",
            "El cupo GitHub y cualquier límite menor del PLAN-AGENTES global prevalecen",
        )
        for rule in required:
            with self.subTest(rule=rule):
                self.assertIn(rule, agents)
        self.assertNotIn("No exceder dos agentes simultáneos en GrindFlow", agents)
        self.assertNotIn("Se permiten hasta **4 lineas de trabajo concurrentes**", agents)
        # Veta variantes de topes locales aun cuando cambie su redacción.
        for forbidden in (
            "dos agentes simultáneos",
            "4 lineas de trabajo concurrentes",
            "máximo uno por repo",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, agents)


if __name__ == "__main__":
    unittest.main()
