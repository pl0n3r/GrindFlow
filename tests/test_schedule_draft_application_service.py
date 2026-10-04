#!/usr/bin/env python3
from __future__ import annotations

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "symfony/src/Scheduling/ScheduleDraftApplicationService.php"
CONTROLLER = ROOT / "symfony/src/Http/Controller/ScheduleDraftController.php"
PHP_TEST = ROOT / "symfony/tests/php/ScheduleDraftApplicationServiceTest.php"
HTTP_TEST = ROOT / "symfony/tests/php/ScheduleDraftTest.php"


class ScheduleDraftApplicationServiceTests(unittest.TestCase):
    def test_manual_controller_delegates_without_changing_http_contract(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        create = re.search(
            r"public function create\([\s\S]*?\n    }\n\n",
            controller,
        )
        self.assertIsNotNone(create)
        create_source = create.group(0)

        self.assertIn(
            "ScheduleDraftApplicationService $drafts",
            create_source,
        )
        self.assertIn("$drafts->reserve(", create_source)
        self.assertNotIn("Connection $db", create_source)
        self.assertNotIn("WeeklySlotCalculator $calculator", create_source)
        self.assertNotIn("->transactional(", create_source)
        self.assertNotIn("FOR UPDATE", create_source)

        for contract in (
            "organization_access_changed",
            "asset_not_found",
            "asset_not_eligible",
            "slot_changed",
            "slot_full",
            "'publishes' => false",
        ):
            self.assertIn(contract, create_source)

        http_test = HTTP_TEST.read_text(encoding="utf-8")
        for status in ("201", "404", "409"):
            self.assertIn(f"assertResponseStatusCodeSame({status})", http_test)
        self.assertIn("ScheduleDraftApplicationService::class", http_test)
        self.assertIn("use GrindFlow\\Scheduling\\ScheduleDraftApplicationService;", controller)
        self.assertIn("use GrindFlow\\Scheduling\\ScheduleDraftApplicationService;", http_test)

    def test_service_preserves_tenant_eligibility_slot_capacity_and_idempotency_without_provider_io(
        self,
    ) -> None:
        service = SERVICE.read_text(encoding="utf-8")

        required_contract = (
            "transactional(",
            "gf_identity_organizations",
            "FOR UPDATE",
            "gf_identity_memberships",
            "actor.is_active = 1",
            "membership.role IN ('admin', 'studio', 'editor')",
            "gf_vault_assets",
            "organization_id = :organization",
            "deleted_at IS NULL",
            "gf_content_rules",
            "review_only",
            "gf_content_review_events",
            "gf_distribution_authorization_events",
            "$this->calculator->upcoming($rule)",
            "hash_equals($candidate['scheduled_at_utc'], $scheduledAtUtc)",
            "gf_schedule_drafts",
            "status = 'draft'",
            "COUNT(*)",
            "$reserved >= $slot['capacity']",
            "$db->insert('gf_schedule_drafts'",
        )
        for fragment in required_contract:
            self.assertIn(fragment, service)

        for forbidden in (
            "FacebookPageProvider",
            "FacebookPagePublicationService",
            "DistributionCommand",
            "Request",
            "Response",
            "Csrf",
            "curl",
            "http://",
            "https://",
            "fetch(",
        ):
            self.assertNotIn(forbidden, service)

        direct_test = PHP_TEST.read_text(encoding="utf-8")
        self.assertIn("testRevokedOrganizationFailsBeforeDraftQueriesOrMutation", direct_test)
        self.assertIn("expects(self::never())->method('insert')", direct_test)


if __name__ == "__main__":
    unittest.main()
