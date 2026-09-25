"""Contrato de carga del relay Sonar: filtrar antes del runner y deduplicar por check_run."""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/sonar-pr-details.yml"


def validate_relay(source: str) -> None:
    required = (
        "  check_run:\n    types: [completed]\n",
        "github.event.check_run.app.slug == 'sonarqubecloud'",
        "github.event.check_run.name == 'SonarCloud Code Analysis'",
        "    timeout-minutes: 5\n",
        "    concurrency:\n      group: grindflow-sonar-relay-${{ github.event.check_run.id }}\n"
        "      cancel-in-progress: true\n",
        "    runs-on: ubuntu-latest\n",
        "          ref: main\n",
    )
    for snippet in required:
        if source.count(snippet) != 1:
            raise ValueError(f"Contrato del relay Sonar alterado: {snippet!r}")
    if "  issue_comment:" in source or "  pull_request_target:" in source:
        raise ValueError("El relay Sonar no debe consumir comentarios ni PR sin revisar")
    if "    secrets: inherit" in source:
        raise ValueError("No heredar secretos adicionales")


class SonarRelayLoadTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = WORKFLOW.read_text(encoding="utf-8")

    def test_sonar_relay_filters_and_serializes_by_check_run(self):
        validate_relay(self.source)

    def test_unrelated_events_or_broader_concurrency_are_rejected(self):
        mutations = (
            ("  check_run:\n", "  issue_comment:\n  check_run:\n"),
            ("    types: [completed]", "    types: [created, completed]"),
            ("github.event.check_run.app.slug == 'sonarqubecloud'", "true"),
            ("github.event.check_run.name == 'SonarCloud Code Analysis'", "true"),
            ("grindflow-sonar-relay-${{ github.event.check_run.id }}",
             "grindflow-sonar-relay"),
            ("      cancel-in-progress: true", "      cancel-in-progress: false"),
        )
        for before, after in mutations:
            with self.subTest(before=before):
                candidate = self.source.replace(before, after, 1)
                self.assertNotEqual(candidate, self.source)
                with self.assertRaises(ValueError):
                    validate_relay(candidate)


if __name__ == "__main__":
    unittest.main()
