"""Deterministic, synthetic MVP tenant matrix over existing Symfony HTTP tests.

This contract does not execute the PHP tests or claim browser E2E success.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs" / "MVP-E2E-TENANT-MATRIX.md"
EPOCH = datetime(2026, 10, 19, 12, 0, tzinfo=timezone.utc)

MODULES = {
    "Vault": ("symfony/tests/php/VaultTest.php",
              "testPrivatePhotoUploadListDownloadAndCrossTenantGuards", (401, 403, 404)),
    "Composer": ("symfony/tests/php/ScheduleDraftTest.php",
                 "testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish", (401, 403, 404)),
    "Scheduling": ("symfony/tests/php/ScheduleDraftTest.php",
                   "testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish", (401, 403, 404)),
    "Distribution": ("symfony/tests/php/DistributionAuthorizationTest.php",
                     "testAuthorizationIsExplicitTenantSafeRevocableAndNeverPublishes", (401, 404)),
    "Dashboard": ("symfony/tests/php/PilotWeeklySummaryTest.php",
                  "testWeeklyPilotSummaryScopesEventsToMembershipWithoutInventingTraffic", (401, 403)),
}
ROLES = {
    "Vault": "editor", "Composer": "admin", "Scheduling": "admin",
    "Distribution": "admin", "Dashboard": "model",
}
STATES = {
    "Vault": "candidate", "Composer": "draft", "Scheduling": "scheduled",
    "Distribution": "pending", "Dashboard": "unknown",
}


def _method(source: str, name: str) -> str:
    match = re.search(r"\bfunction\s+" + re.escape(name) + r"\s*\(", source)
    if match is None:
        raise AssertionError("Missing Symfony integration test: " + name)
    following = re.search(
        r"\bpublic\s+function\s+test[A-Za-z0-9_]+\s*\(", source[match.end():]
    )
    end = match.end() + following.start() if following else len(source)
    return source[match.start():end]


def _scenario(module: str, offset: int) -> dict[str, str | int]:
    return {
        "module": module,
        "actor_tenant": "tenant-sintetico-a",
        "resource_tenant": "tenant-sintetico-b",
        "role": ROLES[module],
        "at_utc": (EPOCH + timedelta(minutes=offset * 5)).isoformat(),
        "initial_state": STATES[module],
        "expected_action": "deny",
        "expected_http": 404,
    }


class MvpE2eTenantMatrixTests(unittest.TestCase):
    def test_each_mvp_module_has_tenant_and_permission_negative_cases(self):
        self.assertEqual(
            set(MODULES), {"Vault", "Composer", "Scheduling", "Distribution", "Dashboard"}
        )
        markdown = MATRIX.read_text(encoding="utf-8")
        for module, (relative, method, codes) in MODULES.items():
            with self.subTest(module=module):
                source = (ROOT / relative).read_text(encoding="utf-8")
                self.assertIn("extends WebTestCase", source)
                block = _method(source, method)
                self.assertIn("$client->request(", block)
                self.assertIn("organization_id", block)
                self.assertIn("role", block)
                self.assertRegex(block, r"(?i)\b(?:foreign|other)\b")
                for code in codes:
                    self.assertIn(
                        f"assertResponseStatusCodeSame({code})", block,
                        f"Missing actual HTTP denial {code} for {module}",
                    )
                self.assertIn("| " + module + " |", markdown)
                self.assertIn(relative, markdown)
                self.assertIn(method, markdown)

        scheduling = (ROOT / "tests/test_scheduling_automation_contract.py").read_text(
            encoding="utf-8"
        )
        self.assertIn(
            "def test_cross_tenant_and_unauthorized_destination_fail_closed(",
            scheduling,
        )
        composer = (
            ROOT / "tests/test_s4_composer_external_delivery_contract.py"
        ).read_text(encoding="utf-8")
        self.assertIn(
            "def test_authorization_and_immutability_fail_closed(", composer
        )

    def test_fixture_scope_and_time_state_transitions_are_deterministic(self):
        modules = tuple(MODULES)
        scenarios = tuple(_scenario(name, i) for i, name in enumerate(modules))
        self.assertEqual(scenarios, tuple(_scenario(n, i) for i, n in enumerate(modules)))
        self.assertEqual(len({row["module"] for row in scenarios}), 5)
        previous = None
        for row in scenarios:
            with self.subTest(module=row["module"]):
                self.assertEqual(row["actor_tenant"], "tenant-sintetico-a")
                self.assertEqual(row["resource_tenant"], "tenant-sintetico-b")
                self.assertNotEqual(row["actor_tenant"], row["resource_tenant"])
                self.assertEqual(row["role"], ROLES[str(row["module"])])
                self.assertEqual(row["initial_state"], STATES[str(row["module"])])
                self.assertEqual(row["expected_action"], "deny")
                self.assertEqual(row["expected_http"], 404)
                timestamp = datetime.fromisoformat(str(row["at_utc"]))
                self.assertEqual(timestamp.utcoffset(), timedelta(0))
                if previous is not None:
                    self.assertEqual(timestamp - previous, timedelta(minutes=5))
                previous = timestamp

        documentation = MATRIX.read_text(encoding="utf-8")
        for literal in (
            "2026-10-19T12:00:00+00:00", "tenant-sintetico-a",
            "tenant-sintetico-b", "fixtures descartables",
            "UNKNOWN", "sin datos reales", "No sustituye",
        ):
            self.assertIn(literal, documentation)


if __name__ == "__main__":
    unittest.main()
