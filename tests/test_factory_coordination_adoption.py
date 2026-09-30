import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/work-coordination.yml"


class FactoryCoordinationAdoptionTests(unittest.TestCase):
    def setUp(self):
        self.text = WORKFLOW.read_text(encoding="utf-8")

    def job_block(self, name):
        match = re.search(
            rf"(?ms)^  {re.escape(name)}:\n(?P<body>.*?)(?=^  [a-z0-9-]+:\n|\Z)",
            self.text,
        )
        self.assertIsNotNone(match, f"job ausente: {name}")
        return match.group("body")

    def test_caller_uses_factory_v1_spanish_profile_only(self):
        self.assertIn("pl0n3r/factory/.github/workflows/coordinacion.yml@v1", self.text)
        self.assertIn("profile: es", self.text)
        self.assertNotIn("@main", self.text)
        self.assertNotIn("coordinar_trabajo.py", self.text)
        self.assertIn("require_reservation: true", self.text)
        self.assertIn("queue: max", self.text)

    def test_pull_request_updates_revalidate(self):
        self.assertIn(
            "types: [opened, synchronize, edited, ready_for_review, converted_to_draft, closed]",
            self.text,
        )
        validar = self.job_block("validar-pr")
        self.assertIn("github.event_name == 'pull_request'", validar)
        self.assertIn("operation: validate", validar)
        self.assertIn("require_reservation: true", validar)

    def test_bootstrap_exception_is_exact_and_same_repository_only(self):
        validar = self.job_block("validar-pr")
        self.assertIn("github.event.pull_request.head.repo.full_name == github.repository", validar)
        self.assertIn("github.event.pull_request.head.ref == 'factory/bootstrap-coordination-187'", validar)
        self.assertNotIn("startsWith(github.event.pull_request.head.ref, 'factory/bootstrap-coordination-')", validar)

    def test_comment_command_delegates_tomar_whitespace_to_factory_parser(self):
        comentario = self.job_block("comentario")
        self.assertIn("startsWith(github.event.comment.body, '/tomar')", comentario)
        self.assertIn("github.event.issue.pull_request == null", comentario)
        self.assertIn("operation: comment", comentario)

    def test_each_coordination_job_keeps_condition_and_operation(self):
        expected = {
            "comentario": ("github.event_name == 'issue_comment'", "operation: comment"),
            "etiqueta": ("github.event_name == 'issues'", "operation: label"),
            "pr": ("github.event_name == 'pull_request'", "operation: pr"),
            "validar-pr": ("github.event_name == 'pull_request'", "operation: validate"),
            "issue": ("github.event_name == 'issues'", "operation: issue"),
            "sweep": ("github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'", "operation: sweep"),
        }
        for job, (condition, operation) in expected.items():
            with self.subTest(job=job):
                block = self.job_block(job)
                self.assertIn("if:", block)
                self.assertIn(condition, block)
                self.assertIn(operation, block)

    def test_required_events_remain_declared(self):
        for event in ("issue_comment:", "issues:", "pull_request:", "workflow_dispatch:", "schedule:"):
            with self.subTest(event=event):
                self.assertIn(event, self.text)


if __name__ == "__main__":
    unittest.main()
