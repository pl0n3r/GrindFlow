"""#502: pre-live compliance *diagnostic*, with synthetic facts only."""
import copy
import importlib.util
import io
import unittest
from contextlib import redirect_stdout, redirect_stderr
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/prelive_compliance_gate.py"
spec = importlib.util.spec_from_file_location("prelive_compliance_gate", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def fixture():
    return {
        "schema_version": 1,
        "distribution_mode": "EXTERNAL_PLATFORM",
        "consent": {"status": "VERIFIED", "evidence_ref": "ref:fixture/consent", "revoked": False},
        "ownership": {"status": "VERIFIED", "evidence_ref": "ref:fixture/rights"},
        "age_eligibility": {"status": "ELIGIBLE", "evidence_ref": "ref:fixture/age-result"},
        "platform_eligibility": {"status": "ELIGIBLE", "evidence_ref": "ref:fixture/platform-result"},
        "platform_scopes": {"status": "VERIFIED", "evidence_ref": "ref:fixture/scopes",
                            "required": ["media.publish"], "granted": ["media.publish"]},
        "privacy": {"status": "DOCUMENTED", "evidence_ref": "ref:fixture/privacy-inventory"},
        "legal_review": {"status": "PENDING_HUMAN_REVIEW"},
    }


class PreliveComplianceGateTests(unittest.TestCase):
    def blocked(self, payload, reason):
        result = module.evaluate(payload)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertIn(reason, result["reasons"])
        self.assertFalse(result["publish_allowed"])
        self.assertFalse(result["legal_approved"])
        return result

    def test_unknown_revoked_and_missing_evidence_fail_closed(self):
        self.assertEqual(module.evaluate(fixture())["status"], "READY_FOR_HUMAN_REVIEW")
        for field, reason in (("ownership", "RIGHTS_NOT_VERIFIED"),
                              ("age_eligibility", "AGE_NOT_ELIGIBLE_OR_UNKNOWN"),
                              ("privacy", "PRIVACY_NOT_DOCUMENTED")):
            value = fixture()
            value[field]["status"] = "UNKNOWN"
            self.blocked(value, reason)
            value = fixture()
            value[field].pop("evidence_ref")
            self.blocked(value, reason)
        value = fixture()
        value["consent"]["revoked"] = True
        self.blocked(value, "CONSENT_REVOKED")
        value["consent"]["status"] = "UNKNOWN"
        self.blocked(value, "CONSENT_NOT_VERIFIED")
        for invalid in ({}, [], None, {**fixture(), "schema_version": True},
                        {**fixture(), "birthdate": "not-allowed"}):
            self.blocked(invalid, "INVALID_CONTRACT")

    def test_age_rights_and_scopes_are_explicit(self):
        good = fixture()
        self.assertEqual(module.evaluate(good)["status"], "READY_FOR_HUMAN_REVIEW")
        self.assertNotIn("birthdate", str(good))
        self.assertNotIn("document_number", str(good))
        for mutation, reason in (
            (lambda x: x["age_eligibility"].update(status="NOT_APPLICABLE"), "AGE_NOT_ELIGIBLE_OR_UNKNOWN"),
            (lambda x: x["ownership"].update(status="NOT_APPLICABLE"), "RIGHTS_NOT_VERIFIED"),
            (lambda x: x["platform_scopes"].update(granted=[]), "SCOPES_MISSING_OR_EXCESSIVE"),
            (lambda x: x["platform_scopes"].update(granted=["media.publish", "account.admin"]), "SCOPES_MISSING_OR_EXCESSIVE"),
            (lambda x: x["platform_scopes"].update(required=["*"]), "SCOPES_MISSING_OR_EXCESSIVE"),
            (lambda x: x["platform_scopes"].update(evidence_ref="unknown"), "SCOPES_NOT_VERIFIED"),
        ):
            value = copy.deepcopy(good)
            mutation(value)
            self.blocked(value, reason)
        value = fixture()
        value["age_eligibility"]["date_of_birth"] = "synthetic"
        self.blocked(value, "AGE_NOT_ELIGIBLE_OR_UNKNOWN")

    def test_legal_review_and_not_applicable_are_separate(self):
        value = fixture()
        value["distribution_mode"] = "NO_EXTERNAL_PLATFORM"
        for field in ("platform_eligibility", "platform_scopes"):
            value[field] = {"status": "NOT_APPLICABLE", "reason_code": "NO_EXTERNAL_DESTINATION"}
        self.assertEqual(module.evaluate(value)["status"], "READY_FOR_HUMAN_REVIEW")
        for field, reason in (("platform_scopes", "SCOPES_NA_REASON_REQUIRED"),
                              ("platform_eligibility", "PLATFORM_NA_REASON_REQUIRED")):
            invalid = copy.deepcopy(value)
            invalid[field].pop("reason_code")
            self.blocked(invalid, reason)
        invalid = copy.deepcopy(value)
        invalid["consent"]["status"] = "NOT_APPLICABLE"
        self.blocked(invalid, "CONSENT_NOT_VERIFIED")
        invalid = copy.deepcopy(value)
        invalid["legal_review"]["status"] = "LEGAL_APPROVED"
        self.blocked(invalid, "HUMAN_LEGAL_REVIEW_REQUIRED")
        invalid = fixture()
        invalid["platform_eligibility"] = value["platform_eligibility"]
        self.blocked(invalid, "PLATFORM_NOT_ELIGIBLE_OR_UNKNOWN")

    def test_no_publication_or_external_side_effects(self):
        input_data = fixture()
        before = copy.deepcopy(input_data)
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            results = [module.evaluate(input_data), module.evaluate(input_data)]
        self.assertEqual(results[0], results[1])
        self.assertEqual(input_data, before)
        self.assertEqual(out.getvalue(), "")
        self.assertEqual(err.getvalue(), "")
        self.assertFalse(results[0]["publish_allowed"])
        self.assertFalse(results[0]["legal_approved"])
        self.assertFalse(results[0]["go_live_allowed"])
        self.assertTrue(results[0]["requires_human_review"])
        self.assertNotIn("fixture/consent", str(results[0]))
        script = SCRIPT.read_text(encoding="utf-8")
        for needle in ("import requests", "import socket", "import subprocess", "urllib.request", "http.client"):
            self.assertNotIn(needle, script)

    def test_documentation_keeps_human_legal_gate(self):
        doc = (ROOT / "docs/PRELIVE-COMPLIANCE-CONTRACT.md").read_text(encoding="utf-8")
        for needle in ("#354", "#149", "READY_FOR_HUMAN_REVIEW", "UNKNOWN",
                       "NOT_APPLICABLE", "NO_EXTERNAL_DESTINATION", "documented_not_legally_approved",
                       "revisión jurídica humana", "no autoriza", "datos.yml", "D-059", "#494"):
            self.assertIn(needle, doc)


if __name__ == "__main__":
    unittest.main()
