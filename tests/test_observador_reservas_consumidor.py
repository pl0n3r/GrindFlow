"""Regresión del caller de observación local de reservas (Factory#1382, Fase A)."""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CALLER = ROOT / ".github/workflows/observador-reservas-consumidor.yml"

REFERENCE = "name: Observación local de reservas V3 (Fase A, solo lectura)\n\n# Instalar por PR/V3 en cada consumidor, tras publicar Factory@v1 con\n# el reusable. El schedule de Coordinación normal NO sustituye este caller.\non:\n  schedule:\n    - cron: '*/10 * * * *'\n  workflow_dispatch:\n\npermissions:\n  contents: read\n  issues: read\n  pull-requests: read\n  checks: read\n  actions: read\n\nconcurrency:\n  group: lease-consumer-caller-${{ github.repository_id }}\n  cancel-in-progress: false\n\njobs:\n  observe:\n    name: Observar reservas locales, sin liberar\n    # El token del caller solo permite GET; cualquier fuente incompleta\n    # produce UNKNOWN y no acredita ningún ciclo de Fase A.\n    uses: pl0n3r/Factory/.github/workflows/observador-reservas-consumidor.yml@v1\n    permissions:\n      contents: read\n      issues: read\n      pull-requests: read\n      checks: read\n      actions: read\n"


def verify_caller(text: str) -> None:
    """Rechaza cualquier desviación del caller GET-only auditado."""
    if text != REFERENCE:
        raise ValueError("caller de reservas diverge del contrato GET-only Factory@v1")


class ObservadorReservasConsumidorTests(unittest.TestCase):
    def test_caller_matches_read_only_factory_v1(self):
        self.assertTrue(CALLER.is_file())
        verify_caller(CALLER.read_text(encoding="utf-8"))
        self.assertEqual(REFERENCE.count("      issues: read"), 1)
        self.assertNotIn("secrets:", REFERENCE)
        self.assertNotIn("write", REFERENCE)

    def test_mutation_and_scope_regressions_are_rejected(self):
        variants = (
            REFERENCE.replace("  issues: read", "  issues: write", 1),
            REFERENCE.replace("      pull-requests: read", "      pull-requests: write"),
            REFERENCE.replace("  workflow_dispatch:\n", "  workflow_dispatch:\n  push:\n"),
            REFERENCE.replace("  workflow_dispatch:\n", "  workflow_dispatch:\n  pull_request_target:\n"),
            REFERENCE.replace("@v1\n    permissions", "@main\n    permissions"),
            REFERENCE.replace("    permissions:\n", "    secrets: inherit\n    permissions:\n"),
            REFERENCE.replace("  cancel-in-progress: false", "  cancel-in-progress: true"),
            REFERENCE.replace("      actions: read", "      actions: write"),
        )
        for bad in variants:
            with self.subTest(change=bad[-100:]):
                self.assertNotEqual(bad, REFERENCE)
                with self.assertRaises(ValueError):
                    verify_caller(bad)


if __name__ == "__main__":
    unittest.main()
