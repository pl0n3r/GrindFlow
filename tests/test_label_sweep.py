"""Contratos offline del barrido diario de clasificación."""
import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "scripts/label-sweep.py"
SPEC = importlib.util.spec_from_file_location("grindflow_label_sweep", PATH)
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


def item(
    number,
    labels,
    *,
    pr=False,
    body="",
    title="NO_PUBLICAR",
    user="human-user",
):
    value = {
        "number": number,
        "labels": [{"name": label} for label in labels],
        "body": body,
        "title": title,
        "user": {"login": user},
    }
    if pr:
        value["pull_request"] = {"url": "metadata-only"}
    return value


class LabelSweepTests(unittest.TestCase):
    def test_report_is_sanitized_and_covers_issues_and_prs(self):
        rows = [
            item(10, ["tipo: producto", "estado: disponible"], title="cliente secreto"),
            item(11, ["tipo: infraestructura", "prioridad: alta"], pr=True, title="otro secreto"),
            item(12, ["tipo: producto", "prioridad: media", "estado: bloqueado"]),
        ]
        report = module.build_report(rows)
        self.assertEqual(report["count"], 2)
        self.assertIn("#10", report["body"])
        self.assertIn("#11", report["body"])
        self.assertNotIn("cliente secreto", report["body"])
        self.assertNotIn("otro secreto", report["body"])
        self.assertIn("prioridad", report["body"])
        self.assertIn("estado", report["body"])

    def test_existing_auto_issue_is_reused_and_excluded(self):
        auto = item(
            90,
            ["tipo: documentación", "prioridad: media", "estado: disponible"],
            body=module.MARKER + "\nold",
            title=module.AUTO_TITLE,
            user=module.BOT_LOGIN,
        )
        incomplete = item(91, ["tipo: producto", "estado: disponible"])
        report = module.build_report([auto, incomplete])
        self.assertEqual(report["existing_number"], 90)
        self.assertEqual(report["duplicate_numbers"], [])
        self.assertEqual(report["count"], 1)
        self.assertNotIn("#90", report["body"])

    def test_duplicate_auto_issues_are_reported_for_cleanup(self):
        first = item(
            90,
            ["tipo: documentación", "prioridad: media", "estado: disponible"],
            body=module.MARKER,
            title=module.AUTO_TITLE,
            user=module.BOT_LOGIN,
        )
        second = item(
            92,
            ["tipo: documentación", "prioridad: media", "estado: disponible"],
            body=module.MARKER,
            title=module.AUTO_TITLE,
            user=module.BOT_LOGIN,
        )
        report = module.build_report([second, first])
        self.assertEqual(report["existing_number"], 90)
        self.assertEqual(report["duplicate_numbers"], [92])

    def test_zero_items_can_close_existing_report(self):
        auto = item(
            90,
            ["tipo: documentación", "prioridad: media", "estado: disponible"],
            body=module.MARKER,
            title=module.AUTO_TITLE,
            user=module.BOT_LOGIN,
        )
        report = module.build_report([auto])
        self.assertEqual(report["count"], 0)
        self.assertEqual(report["existing_number"], 90)
        self.assertIn("No hay ítems abiertos", report["body"])

    def test_human_issue_with_marker_is_never_adopted_as_auto_report(self):
        human = item(
            80,
            ["tipo: documentación", "estado: disponible"],
            body=module.MARKER + "\nquoted",
            title=module.AUTO_TITLE,
            user="pl0n3r",
        )
        report = module.build_report([human])
        self.assertIsNone(report["existing_number"])
        self.assertEqual(report["duplicate_numbers"], [])
        self.assertEqual(report["count"], 1)
        self.assertIn("#80", report["body"])

    def test_cli_rejects_oversize_without_echo(self):
        result = subprocess.run(
            [sys.executable, str(PATH)],
            input=b"x" * 4_000_001,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 2)
        self.assertNotIn(b"x" * 100, result.stderr)

    def test_workflow_is_daily_idempotent_metadata_only(self):
        workflow = (ROOT / ".github/workflows/barrido-etiquetas.yml").read_text()
        self.assertIn("schedule:", workflow)
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("issues: write", workflow)
        self.assertIn("cancel-in-progress: false", workflow)
        self.assertNotIn("pull_request_target", workflow)
        self.assertNotIn("issue_comment", workflow)
        self.assertIn("user: {login: .user.login}", workflow)
        self.assertIn("startsWith", workflow.replace("startswith", "startsWith"))


if __name__ == "__main__":
    unittest.main()
