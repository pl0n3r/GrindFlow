#!/usr/bin/env python3
"""Regresiones de readiness operativa de privacidad para el pre-piloto."""

from __future__ import annotations

import json
import unittest
from pathlib import Path


class PrivacyPilotReadinessTests(unittest.TestCase):
    PLACEHOLDER = "[COMPLETAR POR EL DUEÑO]"

    @classmethod
    def setUpClass(cls) -> None:
        cls.root = Path(__file__).resolve().parents[1]
        cls.runbook = (
            cls.root / "docs/privacidad/operacion-derechos-incidentes.md"
        ).read_text(encoding="utf-8")
        cls.data = json.loads(
            (cls.root / "datos.yml").read_text(encoding="utf-8")
        )

    def test_missing_real_controller_or_rights_channel_blocks_real_data_pilot_without_inventing_owner_data(
        self,
    ) -> None:
        self.assertEqual(self.data["phase"], "construccion")
        self.assertEqual(
            set(self.data["controller"].values()),
            {self.PLACEHOLDER},
        )
        self.assertIn("Estado operativo: `BLOCKED_REAL_DATA`", self.runbook)
        self.assertIn(
            "piloto con datos personales reales: bloqueado",
            self.runbook,
        )
        self.assertIn(
            "no se deben completar ni sustituir aquí los datos del responsable",
            self.runbook,
        )
        self.assertIn(
            "desarrollo, CI y fixtures sintéticos: permitido",
            self.runbook,
        )

    def test_rights_procedure_covers_access_rectification_portability_and_deletion_with_minimized_traceability(
        self,
    ) -> None:
        for right in ("acceso", "rectificación", "portabilidad", "eliminación"):
            self.assertIn(right, self.runbook)

        for state in (
            "received",
            "verification_required",
            "in_review",
            "fulfilled",
            "rejected",
            "legal_review_required",
        ):
            self.assertIn(state, self.runbook)

        for field in (
            "request_ref",
            "request_type",
            "received_at",
            "status",
            "closed_at",
            "outcome_code",
            "evidence_ref",
        ):
            self.assertIn(field, self.runbook)

        self.assertIn(
            "GitHub/Issues/logs: prohibido copiar PII",
            self.runbook,
        )
        self.assertIn(
            "verificar identidad y representación por un canal privado aprobado",
            self.runbook,
        )

    def test_privacy_incident_runbook_fails_closed_and_escalates_uncertain_scope_without_sensitive_github_evidence(
        self,
    ) -> None:
        for step in (
            "**Detectar**",
            "**Contener**",
            "**Preservar evidencia segura**",
            "**Escalar**",
            "**Revisión jurídica**",
            "**Reanudar**",
        ):
            self.assertIn(step, self.runbook)

        self.assertIn("Fail-closed:", self.runbook)
        self.assertIn("legal_review_required", self.runbook)
        self.assertIn(
            "identificadores opacos, hashes, timestamps",
            self.runbook,
        )
        self.assertIn(
            "no se presume seguridad ni cumplimiento",
            self.runbook,
        )

    def test_legal_states_remain_review_required_and_gate_185_is_explicitly_pre_live(
        self,
    ) -> None:
        self.assertEqual(self.data["phase"], "construccion")
        for treatment in self.data["treatments"]:
            self.assertEqual(treatment["basis"], "review_required")
            self.assertEqual(treatment["consent"], "review_required")
            expected_retention = (
                "dedupe_24h"
                if treatment["id"] == "traffic_dedupe"
                else "review_required"
            )
            self.assertEqual(treatment["retention"], expected_retention)

        policy = (
            self.root / "docs/privacidad/politica-tratamiento.md"
        ).read_text(encoding="utf-8")
        self.assertIn("no constituye aprobación jurídica", policy)
        self.assertIn(
            "Estado jurídico: `documented_not_legally_approved`",
            self.runbook,
        )
        self.assertIn("Gate humano pre-live: `GrindFlow#185`", self.runbook)
        self.assertIn(
            "`APP_PHASE=live`: no autorizado por este runbook",
            self.runbook,
        )


if __name__ == "__main__":
    unittest.main()
