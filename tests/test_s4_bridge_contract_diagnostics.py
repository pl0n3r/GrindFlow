from __future__ import annotations

import json
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]
SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
README = (ROOT / "README.md").read_text(encoding="utf-8")


class S4BridgeContractDiagnosticsTests(unittest.TestCase):
    def test_probe_emits_only_allowlisted_status_header_and_body_contract(self) -> None:
        self.assertIn("safe_s4_bridge_header_state()", SMOKE)
        self.assertIn("S4_BRIDGE_HTTP_STATUS=%s", SMOKE)
        self.assertIn("S4_BRIDGE_HEADER_STATE=%s", SMOKE)
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=valid", SMOKE)
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=invalid", SMOKE)
        self.assertNotIn('cat "$s4_bridge_body"', SMOKE)
        self.assertNotIn('cat "$s4_bridge_headers"', SMOKE)
        self.assertNotIn('echo "$s4_bridge_body"', SMOKE)
        self.assertNotIn('echo "$s4_bridge_headers"', SMOKE)

    def test_invalid_body_never_promotes_readiness_from_header(self) -> None:
        probe_start = SMOKE.index("check_s4_media_web_runtime_readiness()")
        probe_end = SMOKE.index("check_failed_login_session()", probe_start)
        probe = SMOKE[probe_start:probe_end]
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=invalid", probe)
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=valid", probe)
        self.assertIn('if ! bridge_state="$(extract_s4_bridge_state)"; then', probe)
        self.assertIn("MEDIA_WEB_RUNTIME_DIAGNOSTIC=contract_invalid", probe)
        self.assertIn('if [[ "$bridge_status" != "200" || "$bridge_state" != "ready_for_web_probe" ]]; then', probe)
        self.assertNotRegex(
            probe,
            re.compile(r'header_state[^\n]*ready_for_web_probe[^\n]*(?:return|READY=1)', re.IGNORECASE),
        )

    def test_contract_invalid_comment_is_safe_and_actionable(self) -> None:
        self.assertIn('if [[ "$diagnostic_state" == "contract_invalid" ]]; then', WORKFLOW)
        self.assertIn("S4 bridge HTTP status:", WORKFLOW)
        self.assertIn("S4 bridge header state:", WORKFLOW)
        self.assertIn("S4 bridge body contract:", WORKFLOW)
        self.assertIn("No remote response body", WORKFLOW)
        self.assertNotIn("Remote response body:", WORKFLOW)
        self.assertNotIn("S4 bridge headers:", WORKFLOW)

    def test_missing_or_invalid_header_remains_unknown_and_fail_closed(self) -> None:
        helper_start = SMOKE.index("safe_s4_bridge_header_state()")
        helper_end = SMOKE.index("extract_s4_bridge_state()", helper_start)
        helper = SMOKE[helper_start:helper_end]
        for state in (
            "runtime_unavailable",
            "config_missing",
            "schema_missing",
            "identity_unavailable",
            "ready_for_web_probe",
        ):
            self.assertIn(state, helper)
        self.assertIn("unknown", helper)
        self.assertIn("S4_BRIDGE_HEADER_STATE=%s", SMOKE)
        self.assertIn("S4_BRIDGE_BODY_CONTRACT=invalid", SMOKE)

    def test_release_v0207_is_synchronized_and_suite_is_canonical(self) -> None:
        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        self.assertEqual("0.1.207", match.group(1))
        self.assertEqual("0.1.207", PACKAGE["version"])
        self.assertEqual("0.1.207", LOCK["version"])
        self.assertEqual("0.1.207", LOCK["packages"][""]["version"])
        self.assertIn("V0.1.207", README)
        self.assertIn("tests/test_s4_bridge_contract_diagnostics.py", PACKAGE["scripts"]["test"])

    def test_operational_closeout_requires_exact_main_diagnostic_evidence(self) -> None:
        self.assertIn("Exact deployed SHA:", WORKFLOW)
        self.assertIn("S4 bridge HTTP status:", WORKFLOW)
        self.assertIn("S4 bridge header state:", WORKFLOW)
        self.assertIn("S4 bridge body contract:", WORKFLOW)
        self.assertIn('diagnostic_state" == "contract_invalid"', WORKFLOW)


if __name__ == "__main__":
    unittest.main()
