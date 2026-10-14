#!/usr/bin/env python3
"""Contratos de readiness: negativos deterministas, sin red ni publicación."""
from __future__ import annotations

import json
import tempfile
import unittest
from contextlib import redirect_stdout
from copy import deepcopy
from io import StringIO
from pathlib import Path

from scripts.gf_readiness_matrix import (
    MILESTONES, SIGNALS, evaluate, main, render_markdown,
)


class GrindFlowReadinessMatrixTests(unittest.TestCase):
    def setUp(self) -> None:
        entry = {"status": "PASS", "source": "fixture:readiness",
                 "observed_at": "2026-10-13T19:00:00Z"}
        self.manifest = {
            "as_of": "2026-10-13T20:00:00Z",
            "milestones": {name: dict(entry) for name in MILESTONES},
            "signals": {name: dict(entry) for name in SIGNALS},
            "risks": [],
        }

    def test_required_milestones_have_evidence_freshness_or_unknown(self) -> None:
        data = deepcopy(self.manifest)
        del data["milestones"]["271"]
        data["milestones"]["security"]["observed_at"] = "2026-10-11T20:00:00Z"
        data["milestones"]["quality"]["observed_at"] = "2026-10-14T20:00:00Z"
        result = evaluate(data)
        self.assertEqual(tuple(result["milestones"]), MILESTONES)
        self.assertEqual(result["milestones"]["271"]["status"], "UNKNOWN")
        self.assertEqual(result["milestones"]["security"]["freshness"], "STALE")
        self.assertEqual(result["milestones"]["quality"]["reason"], "future_observation")
        self.assertEqual(result["readiness"], "NO_GO")
        with self.assertRaises(ValueError):
            evaluate({**data, "as_of": "2026-10-13T20:00:00"})

    def test_p0_p1_without_issue_is_no_go(self) -> None:
        data = deepcopy(self.manifest)
        data["risks"] = [
            {"severity": "P0", "issue": None},
            {"severity": "P1", "issue": True},
            {"severity": "P1", "issue": 123},
            {"severity": "P2", "issue": None},
        ]
        report = evaluate(data)
        self.assertEqual(report["readiness"], "NO_GO")
        self.assertEqual(report["untracked_p0_p1"], [
            {"index": 0, "severity": "P0"},
            {"index": 1, "severity": "P1"},
        ])
        self.assertFalse(report["publication_allowed"])
        data["risks"] = None
        self.assertTrue(evaluate(data)["risks_unknown"])
        self.assertEqual(evaluate(data)["readiness"], "NO_GO")

    def test_publish_and_do_not_publish_options_are_diagnostic_only(self) -> None:
        report = evaluate(deepcopy(self.manifest))
        self.assertEqual(report["readiness"], "READY_FOR_OWNER_REVIEW")
        self.assertIs(report["publication_allowed"], False)
        self.assertEqual(report["execution"], "none")
        self.assertEqual([x["label"] for x in report["options"]],
                         ["Publicar", "No publicar"])
        self.assertFalse(any(x["executable"] for x in report["options"]))
        self.assertTrue(report["options"][1]["safe_default"])
        md = render_markdown(report)
        self.assertIn("Publicación automática: **NO**", md)
        self.assertIn("No publicar", md)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "readiness.json"
            path.write_text(json.dumps(self.manifest), encoding="utf-8")
            buffer = StringIO()
            with redirect_stdout(buffer):
                self.assertEqual(main(["--input", str(path), "--format", "markdown"]), 0)
            self.assertEqual(md, buffer.getvalue())

    def test_version_sha_deploy_production_are_separate_signals(self) -> None:
        data = deepcopy(self.manifest)
        del data["signals"]["sha"]
        data["signals"]["production"]["status"] = "UNKNOWN"
        report = evaluate(data)
        self.assertEqual(tuple(report["signals"]), SIGNALS)
        self.assertEqual(report["signals"]["version"]["status"], "PASS")
        self.assertEqual(report["signals"]["deploy"]["status"], "PASS")
        self.assertEqual(report["signals"]["sha"]["status"], "UNKNOWN")
        self.assertEqual(report["signals"]["production"]["status"], "UNKNOWN")
        self.assertEqual(report["readiness"], "NO_GO")
        data["signals"]["sha"] = data["signals"]["version"]
        data["signals"]["production"]["status"] = "PASS"
        self.assertEqual(evaluate(data)["readiness"], "READY_FOR_OWNER_REVIEW")


if __name__ == "__main__":
    unittest.main()
