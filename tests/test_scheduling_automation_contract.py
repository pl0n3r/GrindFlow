"""Contrato ejecutable del MVP S4; no prueba provider real ni habilita piloto."""
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
def source(p):
    return (ROOT / p).read_text(encoding="utf-8")

SERVICE = "symfony/src/Scheduling/PublishingAutomationRuleScheduler.php"
CONTROLLER = "symfony/src/Http/Controller/PublishingAutomationController.php"
RESERVATION = "symfony/src/Scheduling/ScheduleDraftApplicationService.php"
COMMAND = "symfony/src/Command/MaterializeAutomationRulesCommand.php"
CALCULATOR = "symfony/src/Scheduling/WeeklySlotCalculator.php"
MIGRATION = "symfony/migrations/Version20261012170000.php"
PHPTEST = "symfony/tests/php/PublishingAutomationRuleTest.php"
UI = "symfony/frontend/admin/WeeklyPlannerPanel.tsx"


class SchedulingAutomationContractTests(unittest.TestCase):
    def test_rule_tenant_scope_slots_timezone_destination(self):
        self.assertIn("automation_state VARCHAR(12) NOT NULL DEFAULT 'paused'", source(MIGRATION))
        self.assertIn("automation_local_times", source(MIGRATION))
        self.assertIn("automation_destination_id", source(MIGRATION))
        self.assertIn("WHERE organization_id = :organization", source(CONTROLLER))
        self.assertIn("DateTimeZone", source(CALCULATOR))
        self.assertIn("eligible_assets", source(CONTROLLER))

    def test_materializes_future_slots_via_canonical_reservation(self):
        s = source(SERVICE)
        self.assertIn("$this->slots->upcoming($rule)", s)
        self.assertIn("$this->drafts->reserve(", s)
        self.assertNotIn("->publish(", s)
        self.assertNotIn("->postPhoto(", s)
        self.assertIn("if (!$apply)", s)

    def test_same_rule_slot_is_idempotent_and_concurrency_safe(self):
        self.assertIn("bool $uniqueAutomationSlot = false", source(RESERVATION))
        self.assertIn("if ($uniqueAutomationSlot && $reserved > 0)", source(RESERVATION))
        self.assertIn("FOR UPDATE", source(RESERVATION))
        self.assertIn("['status' => 'occupied']", source(RESERVATION))
        self.assertIn("$existing", source(SERVICE))

    def test_foreign_blocked_deleted_assets_are_never_selected(self):
        s = source(SERVICE)
        self.assertIn("organization_id = :organization AND deleted_at IS NULL", s)
        self.assertIn("LIMIT 101", s)
        self.assertIn("$this->drafts->reserve(", s)
        self.assertIn("distribution_authorization_missing", source(RESERVATION))
        self.assertIn("content_review_required", source(RESERVATION))

    def test_avoids_immediate_asset_repetition_when_possible(self):
        s = source(SERVICE)
        self.assertIn("SELECT asset_id FROM gf_schedule_drafts", s)
        self.assertIn("scheduled_at_utc < :scheduled AND status = 'draft' ORDER BY scheduled_at_utc DESC, id DESC", s)
        self.assertIn("'scheduled' => $stamp", s)
        self.assertIn("usort($candidates", s)
        self.assertIn("count($candidates) > 1", s)

    def test_paused_rule_preserves_existing_drafts(self):
        s = source(SERVICE)
        self.assertIn("!== 'active'", s)
        self.assertIn("automation_state = 'paused'", source("symfony/src/Http/Controller/ContentRuleController.php"))
        self.assertIn("'paused'", s)
        self.assertNotIn("DELETE FROM gf_schedule_drafts", s)
        self.assertNotIn("UPDATE gf_external_publication_attempts", s)

    def test_cross_tenant_and_unauthorized_destination_fail_closed(self):
        s = source(CONTROLLER)
        self.assertIn("$memberships->find($user->id(), $selected)", s)
        self.assertIn("isCsrfTokenValid", s)
        self.assertIn("BoundedJsonBody::decode($request)", s)
        self.assertNotIn("json_decode($request->getContent()", s)
        self.assertIn("assertAvailableFor", s)
        self.assertIn("hash_equals", s)
        self.assertIn("FOR UPDATE", s)
        self.assertIn("'destination_unavailable'", s)

    def test_exhausted_pool_has_explicit_state(self):
        s = source(SERVICE)
        self.assertIn("'insufficient_content'", s)
        self.assertIn("'selection_incomplete'", s)
        self.assertIn("$blocked", s)
        self.assertIn("'no_future_slots'", s)
        self.assertNotIn("->publish(", s)

    def test_synthetic_symfony_integration_covers_timezone_and_locks(self):
        self.assertIn("testMultipleLocalTimesUseCanonicalUtcSlotsWithoutPublishing", source(PHPTEST))
        self.assertIn("testLegacyReviewOnlyRuleRetainsOneTimePerWeekday", source(PHPTEST))
        self.assertIn("testInvalidLocalTimesFailClosed", source(PHPTEST))
        self.assertIn("testMaterializerCannotReserveForUnknownOrUnconfiguredTenant", source(PHPTEST))
        for p in (SERVICE, CONTROLLER, COMMAND, CALCULATOR, RESERVATION, MIGRATION, PHPTEST):
            with self.subTest(path=p):
                if shutil.which("php"):
                    proc = subprocess.run(["php", "-l", str(ROOT / p)],
                                          capture_output=True, text=True, timeout=15)
                    self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)

    def test_external_pilot_remains_behind_issue_453_gate(self):
        self.assertIn("InputOption::VALUE_NONE", source(COMMAND))
        self.assertIn("if (!$apply)", source(SERVICE))
        self.assertNotIn("->publish(", source(SERVICE))
        self.assertNotIn("->publish(", source(CONTROLLER))
        self.assertIn("Esta pantalla", source(UI))
        self.assertIn("automation_state VARCHAR(12) NOT NULL DEFAULT 'paused'", source(MIGRATION))


if __name__ == "__main__":
    unittest.main()
