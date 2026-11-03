#!/usr/bin/env python3
"""GF642: offline negative evidence for support, segments and pre-live gates.

The canonical evaluators are reused; fixtures are entirely synthetic and neither
CI success nor a readiness document can authorize commercialization or go-live.
"""
from __future__ import annotations

from contextlib import redirect_stderr
from copy import deepcopy
from importlib.util import module_from_spec, spec_from_file_location
from io import StringIO
from pathlib import Path
import unittest
from unittest.mock import patch

from scripts.gf_readiness_matrix import MILESTONES, SIGNALS, evaluate, main


ROOT = Path(__file__).resolve().parents[1]


def _validator(filename: str):
    """Load real, existing offline validators without relying on tests as a package."""
    path = ROOT / "tests" / filename
    spec = spec_from_file_location("gf642_" + path.stem, path)
    if spec is None or spec.loader is None:
        raise RuntimeError("Readiness validator unavailable")
    module = module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


commercial = _validator("test_commercial_readiness_package.py")
onboarding = _validator("test_launch_onboarding_support.py")
launch = _validator("test_launch_readiness_docs.py")


def synthetic_manifest() -> dict:
    """All-PASS is a *diagnostic fixture*, never authenticated production data."""
    evidence = {"status": "PASS", "source": "fixture:gf642",
                "observed_at": "2026-10-13T19:00:00Z"}
    return {
        "as_of": "2026-10-13T20:00:00Z",
        "milestones": {key: dict(evidence) for key in MILESTONES},
        "signals": {key: dict(evidence) for key in SIGNALS},
        "risks": [],
    }


class PrelaunchBusinessReadinessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.package = (ROOT / "docs/launch/commercial-readiness.md").read_text(encoding="utf-8")
        cls.support = (ROOT / "docs/launch/onboarding-support.md").read_text(encoding="utf-8")
        cls.checklist = (ROOT / "docs/launch/commercial-readiness-checklist.md").read_text(encoding="utf-8")

    def assertNotAuthorized(self, report: dict) -> None:
        self.assertIs(report["publication_allowed"], False)
        self.assertEqual(report["execution"], "none")
        self.assertFalse(report["options"][0]["executable"])
        self.assertTrue(report["options"][0]["requires_owner_decision"])
        self.assertTrue(report["options"][1]["safe_default"])

    def test_missing_market_or_support_evidence_is_not_ready(self) -> None:
        """AC-01: absent, expired or forged support/market proof fails closed."""
        commercial.validate_package(self.package)
        self.assertTrue(onboarding.valid_readiness(self.support))
        scenarios = (
            ("market_missing", "milestones", "351", None),
            ("market_unknown", "milestones", "351", "UNKNOWN"),
            ("pilot_missing", "milestones", "271", None),
            ("ops_unknown", "signals", "production", "UNKNOWN"),
        )
        for name, section, key, status in scenarios:
            with self.subTest(case=name):
                fixture = synthetic_manifest()
                if status is None:
                    del fixture[section][key]
                else:
                    fixture[section][key]["status"] = status
                report = evaluate(fixture)
                self.assertEqual(report["readiness"], "NO_GO")
                self.assertNotAuthorized(report)
                target = report[section]
                self.assertEqual(target[key]["status"], "UNKNOWN")

        # Presence of an onboarding proposal is not proof of working support.
        support_row = next(line for line in self.support.splitlines()
                           if line.startswith("| Canales y personal de soporte operativos |"))
        self.assertFalse(onboarding.valid_readiness(
            self.support.replace(support_row + "\n", "", 1)))
        self.assertFalse(onboarding.valid_readiness(
            self.support.replace(support_row, support_row.replace(
                "| UNKNOWN | UNKNOWN |", "| propuesta | EVIDENCIADO |", 1), 1)))
        self.assertTrue(onboarding.valid_live_authorization(self.support))

    def test_segments_and_modalities_are_not_commercial_authorization(self) -> None:
        # #355 requires validation by modality; documentation alone is discovery.
        for modality in ("Onboarding: independiente", "Onboarding: Studio"):
            self.assertIn("Owner de proceso:", onboarding.section(self.support, modality))
        self.assertIn("persona asignada: UNKNOWN", self.support)
        self.assertIn("canal de recepción = UNKNOWN", self.support)
        # Even a perfectly green *synthetic* matrix cannot grant release/live.
        report = evaluate(synthetic_manifest())
        self.assertEqual(report["readiness"], "READY_FOR_OWNER_REVIEW")
        self.assertNotAuthorized(report)
        self.assertEqual([choice["label"] for choice in report["options"]],
                         ["Publicar", "No publicar"])

    def test_market_costs_and_missing_owner_cannot_be_inferred(self) -> None:
        commercial.validate_package(self.package)
        replacements = (
            ("| GiB·mes | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |",
             "| GiB·mes | 0 | UNKNOWN | UNKNOWN | 0 | NO ADJUNTA |"),
            ("persona asignada UNKNOWN", "persona asignada confirmada"),
            ("**Costos totales:** UNKNOWN.", "**Costos totales:** 0."),
            ("**Decisión go/no-go comercial:** NO-GO (sin evidencias autenticadas).",
             "**Decisión go/no-go comercial:** GO."),
        )
        for original, forged in replacements:
            with self.subTest(forgery=forged):
                self.assertIn(original, self.package)
                with self.assertRaises(ValueError):
                    commercial.validate_package(self.package.replace(original, forged, 1))

    def test_support_proposal_does_not_become_approved_sla(self) -> None:
        self.assertTrue(onboarding.valid_readiness(self.support))
        self.assertTrue(onboarding.valid_go_no_go_decision(self.support))
        self.assertTrue(onboarding.valid_live_authorization(self.support))
        sla_title = "SLAs iniciales — propuesta, NO aprobación contractual"
        self.assertTrue(all(row["Estado"] == "PROPUESTA_NO_APROBADA"
                            for row in onboarding.table(self.support, sla_title)))
        forged_sla = self.support.replace("PROPUESTA_NO_APROBADA |", "APROBADA |", 1)
        self.assertNotEqual(forged_sla, self.support)
        self.assertFalse(all(row["Estado"] == "PROPUESTA_NO_APROBADA"
                             for row in onboarding.table(forged_sla, sla_title)))
        forged_go = self.support.replace(onboarding.GO_NO_GO_CANONICAL,
                                         "**Resultado go/no-go:** GO.", 1)
        self.assertFalse(onboarding.valid_readiness(forged_go))
        live = self.support.replace(onboarding.LIVE_STATUS,
                                    "**Estado actual de live:** AUTORIZADO.", 1)
        self.assertFalse(onboarding.valid_live_authorization(live))

    def test_release_document_cannot_promote_unverified_rows(self) -> None:
        rows = launch._matrix(self.checklist)
        self.assertEqual(len(rows), 12)
        launch._status(self.checklist)
        for criterion in ("GF-C03", "GF-C06", "GF-C07", "GF-C10", "GF-C12"):
            row = next(item for item in rows if item["ID"] == criterion)
            self.assertEqual(row["Estado"], "UNKNOWN", criterion)
            self.assertTrue(row["Evidencia"].startswith("NO ADJUNTA;"), criterion)
            self.assertTrue(row["Frescura"].startswith("SIN VERIFICAR;"), criterion)
        for mutation in (
            self.checklist.replace("| GF-C07 |", "| GF-C99 |", 1),
            self.checklist.replace("| UNKNOWN | SIN VERIFICAR;", "| READY | SIN VERIFICAR;", 1),
        ):
            with self.assertRaises(ValueError):
                launch._matrix(mutation)
        with self.assertRaises(ValueError):
            launch._status(self.checklist + "\n**Autorización go-live:** AUTORIZADO.\n")

    def test_stale_future_and_untrusted_evidence_is_no_go(self) -> None:
        variants = (
            ("observed_at", "2026-10-11T19:00:00Z", "stale_observation"),
            ("observed_at", "2026-10-14T19:00:00Z", "future_observation"),
            ("observed_at", "2026-10-13T19:00:00", "missing_or_invalid_evidence"),
            ("source", "../secret?token=sample", "missing_or_invalid_evidence"),
            ("status", "READY", "missing_or_invalid_evidence"),
        )
        for key, value, reason in variants:
            with self.subTest(field=key, value=value):
                fixture = synthetic_manifest()
                fixture["milestones"]["351"][key] = value
                report = evaluate(fixture)
                self.assertEqual(report["readiness"], "NO_GO")
                self.assertEqual(report["milestones"]["351"]["reason"], reason)
                self.assertNotAuthorized(report)

    def test_p0_p1_or_missing_production_identity_vetoes_release(self) -> None:
        for risk in ({"severity": "P0", "issue": None},
                     {"severity": "P1", "issue": True}):
            fixture = synthetic_manifest()
            fixture["risks"] = [risk]
            report = evaluate(fixture)
            self.assertEqual(report["readiness"], "NO_GO")
            self.assertEqual(report["untracked_p0_p1"][0]["severity"], risk["severity"])
            self.assertNotAuthorized(report)
        fixture = synthetic_manifest()
        del fixture["signals"]["sha"]
        report = evaluate(fixture)
        self.assertEqual(report["readiness"], "NO_GO")
        self.assertEqual(report["signals"]["sha"]["status"], "UNKNOWN")
        self.assertEqual(report["signals"]["version"]["status"], "PASS")
        self.assertNotAuthorized(report)

    def test_duplicate_json_keys_cannot_forge_operational_proof(self) -> None:
        fake = ('{"as_of":"2026-10-13T20:00:00Z",'
                '"as_of":"2026-10-13T20:00:00Z",'
                '"milestones":{},"signals":{},"risks":[]}')
        with patch("sys.stdin", StringIO(fake)):
            with redirect_stderr(StringIO()):
                with self.assertRaises(SystemExit) as caught:
                    main([])
        self.assertEqual(caught.exception.code, 2)


if __name__ == "__main__":
    unittest.main()
