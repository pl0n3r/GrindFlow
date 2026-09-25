"""Offline contract for the Issue label notice; no GitHub writes."""
import importlib.util
import unittest
from unittest.mock import patch
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "scripts/issue-label-notice.py"
SPEC = importlib.util.spec_from_file_location("grindflow_issue_notice", PATH)
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class IssueLabelNoticeTests(unittest.TestCase):
    def test_valid_dimensions_have_no_notice(self):
        names = {"tipo: producto", "tipo: seguridad", "prioridad: alta", "estado: reservado"}
        self.assertEqual(module.missing(names), ())
        self.assertEqual(module.missing(names | {"rol: qa"}), ())

    def test_missing_dimensions_and_unknown_values(self):
        self.assertEqual(module.missing(set()), (
            "tipo (1–2)", "prioridad (exactamente 1)", "estado (exactamente 1)",
        ))
        self.assertEqual(module.missing({
            "tipo: seguridad", "prioridad: baja", "estado: bloqueado", "tipo: desconocido",
        }), ("etiquetas de dimensión no canónicas",))
        self.assertIn("prioridad (exactamente 1)", module.missing({
            "tipo: error", "prioridad: alta", "prioridad: baja", "estado: disponible",
        }))

    def test_existing_state_is_never_replaced(self):
        for state in module.STATES:
            with self.subTest(state=state):
                self.assertEqual(module.missing({
                    "tipo: infraestructura", "prioridad: media", state,
                }), ())

    def test_notice_stable_and_does_not_reflect_arbitrary_labels(self):
        current = module.notice(module.missing({"tipo: producto"}))
        self.assertEqual(current, module.notice(module.missing({"tipo: producto"})))
        self.assertEqual(current.count(module.MARKER), 1)
        self.assertNotIn("user-controlled-value", current)
        self.assertIn("Clasificación completa", module.notice(()))

    def test_default_state_and_one_editable_notice_across_repeat_events(self):
        issue = {"labels": [{"name": "tipo: producto"}]}
        comments = []
        calls = []

        def fake_gh(*args):
            calls.append(args)
            target = args[2] if args[:2] == ("--method", "POST") else args[0]
            if target.endswith("/labels"):
                issue["labels"].append({"name": module.DEFAULT_STATE})
                return {}
            if target.endswith("/comments?per_page=100"):
                return [comments]
            if "/comments/" in target and args[:2] == ("--method", "PATCH"):
                comments[0]["body"] = args[-1][5:]
                return {}
            if target.endswith("/comments") and args[:2] == ("--method", "POST"):
                comments.append({
                    "id": 7, "body": args[-1][5:],
                    "user": {"login": "github-actions[bot]"},
                })
                return {}
            return issue

        with patch.dict(module.os.environ, {
            "GITHUB_REPOSITORY": "pl0n3r/GrindFlow", "ISSUE_NUMBER": "125",
        }), patch.object(module, "gh", fake_gh):
            self.assertEqual(module.main(), 0)
            self.assertEqual(module.main(), 0)
            self.assertEqual(len(comments), 1)
            self.assertEqual(sum("/labels" in " ".join(x) for x in calls), 1)
            issue["labels"].append({"name": "prioridad: media"})
            self.assertEqual(module.main(), 0)
            self.assertEqual(len(comments), 1)
            self.assertIn("Clasificación completa", comments[0]["body"])

    def test_workflow_triggers_only_issue_metadata_and_limited_permissions(self):
        workflow = (ROOT / ".github/workflows/aviso-etiquetas-issues.yml").read_text()
        self.assertIn("types: [opened, edited, labeled, unlabeled, reopened]", workflow)
        self.assertNotIn("pull_request_target", workflow)
        self.assertNotIn("issue_comment:", workflow)
        self.assertIn("issues: write", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("cancel-in-progress: false", workflow)
        self.assertIn("ref: ${{ github.event.repository.default_branch }}", workflow)
        self.assertIn("python3 scripts/issue-label-notice.py", workflow)


if __name__ == "__main__":
    unittest.main()
