#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/seguridad.yml"


class FakeIssueApi:
    def __init__(self) -> None:
        self.labels = {"decisión: dueño"}

    def remove_decision_label(self) -> None:
        self.labels.discard("decisión: dueño")


def cleanup_should_run(
    *,
    actor_outcome: str,
    trusted: str,
    gate_outcome: str,
    gate_status: str,
) -> bool:
    return (
        actor_outcome == "success"
        and (
            trusted != "true"
            or (gate_outcome == "success" and gate_status != "gate")
        )
    )


class SecurityWorkflowContractTests(unittest.TestCase):
    def source(self) -> str:
        return WORKFLOW.read_text(encoding="utf-8")

    def job_block(self, job_name: str) -> str:
        lines = self.source().splitlines()
        marker = f"  {job_name}:"
        start = lines.index(marker)
        end = len(lines)
        for index in range(start + 1, len(lines)):
            line = lines[index]
            if line.startswith("  ") and not line.startswith("    ") and line.endswith(":"):
                end = index
                break
        return "\n".join(lines[start:end])

    def step_block(self, job_name: str, step_name: str) -> str:
        lines = self.job_block(job_name).splitlines()
        marker = f"      - name: {step_name}"
        start = lines.index(marker)
        end = len(lines)
        for index in range(start + 1, len(lines)):
            if lines[index].startswith("      - name: "):
                end = index
                break
        return "\n".join(lines[start:end])

    def test_owner_exact_decision_commands_use_versioned_factory_security(self) -> None:
        source = self.source()
        materialize = self.job_block("materializar-respuesta")
        self.assertIn("issue_comment:", source)
        self.assertIn("github.event.comment.author_association == 'OWNER'", materialize)
        self.assertIn("github.event.comment.user.type != 'Bot'", materialize)
        for option in ("A", "B", "C", "D"):
            self.assertIn(
                f"github.event.comment.body == '/decidir {option}'",
                materialize,
            )
        self.assertEqual(2, source.count("repository: pl0n3r/factory"))
        self.assertEqual(2, source.count("ref: v1"))
        self.assertIn(".factory/seguridad/sincronizar_puerta.py", source)
        self.assertIn(".factory/seguridad/decision_respuesta.py", source)
        self.assertIn(".factory/seguridad/puertas_humanas.py", source)

    def test_consumer_code_is_never_checked_out_or_executed(self) -> None:
        source = self.source()
        self.assertEqual(2, source.count("uses: actions/checkout@"))
        self.assertEqual(2, source.count("repository: pl0n3r/factory"))
        self.assertEqual(2, source.count("path: .factory"))
        self.assertEqual(2, source.count("persist-credentials: false"))
        self.assertNotIn("repository: pl0n3r/GrindFlow", source)
        self.assertNotIn("repository: $" + "{{ github.repository }}", source)
        self.assertNotIn("working-directory:", source)

    def test_issue_routing_uses_minimal_permissions_and_fail_closed_triggers(self) -> None:
        source = self.source()
        sync = self.job_block("sincronizar-decision")
        cleanup = self.step_block(
            "sincronizar-decision",
            "Retirar de la cola si no es puerta válida y confiable",
        )
        self.assertIn("types: [opened, edited, reopened]", source)
        self.assertIn("types: [created]", source)
        self.assertIn("permissions:\n  contents: read", source)
        self.assertEqual(2, source.count("issues: write"))
        self.assertIn("cancel-in-progress: false", source)
        self.assertIn("if: github.event_name == 'issues' && github.event.issue.state == 'open'", sync)
        self.assertIn("steps.actor.outcome == 'success'", cleanup)
        self.assertIn("steps.gate.outcome == 'success'", cleanup)
        self.assertIn("steps.actor.outputs.trusted != 'true'", cleanup)
        self.assertIn("steps.gate.outputs.status != 'gate'", cleanup)
        self.assertIn("steps.gate_sync.outputs.canonical == 'true'", sync)
        self.assertIn("status == 'invalid-gate'", sync)

    def test_cleanup_state_machine_is_fail_closed(self) -> None:
        cases = (
            (
                "untrusted issue",
                dict(
                    actor_outcome="success",
                    trusted="false",
                    gate_outcome="skipped",
                    gate_status="",
                ),
                True,
                False,
            ),
            (
                "canonical gate",
                dict(
                    actor_outcome="success",
                    trusted="true",
                    gate_outcome="success",
                    gate_status="gate",
                ),
                False,
                True,
            ),
            (
                "checkout or actor failure",
                dict(
                    actor_outcome="failure",
                    trusted="",
                    gate_outcome="skipped",
                    gate_status="",
                ),
                False,
                True,
            ),
            (
                "classification failure",
                dict(
                    actor_outcome="success",
                    trusted="true",
                    gate_outcome="failure",
                    gate_status="",
                ),
                False,
                True,
            ),
        )
        for label, inputs, expected_cleanup, expected_label_present in cases:
            with self.subTest(label=label):
                api = FakeIssueApi()
                should_cleanup = cleanup_should_run(**inputs)
                self.assertIs(should_cleanup, expected_cleanup)
                if should_cleanup:
                    api.remove_decision_label()
                self.assertIs(
                    "decisión: dueño" in api.labels,
                    expected_label_present,
                )

    def test_historical_or_untrusted_comments_are_not_replayed(self) -> None:
        source = self.source()
        for forbidden in (
            "workflow_run:",
            "schedule:",
            "repository_dispatch:",
            "comments?per_page=",
            "issues/comments?per_page=",
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("github.event_name == 'issue_comment'", source)
        self.assertEqual(
            1,
            source.count("github.event.comment.author_association == 'OWNER'"),
        )
        self.assertIn("OWNER|MEMBER|COLLABORATOR", source)
        self.assertNotIn("author_association == 'MEMBER'", source)
        self.assertNotIn("author_association == 'COLLABORATOR'", source)


if __name__ == "__main__":
    unittest.main()
