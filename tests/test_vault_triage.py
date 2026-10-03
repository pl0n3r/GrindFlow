from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class VaultTriageTests(unittest.TestCase):
    def test_ambiguous_asset_enters_triage_without_guessing_owner(self):
        model = (ROOT / "app/Models/VaultTriageItem.php").read_text(encoding="utf-8")
        service = (ROOT / "app/Support/Vault/VaultTriageQueue.php").read_text(encoding="utf-8")
        migration = (
            ROOT / "database/migrations/2026_10_03_000200_create_vault_triage_items_table.php"
        ).read_text(encoding="utf-8")
        feature = (ROOT / "tests/Feature/VaultTriageTest.php").read_text(encoding="utf-8")

        self.assertIn("class VaultTriageItem extends TenantModel", model)
        self.assertIn("STATUS_PENDING = 'pending'", model)
        self.assertIn("enqueueAmbiguous", service)
        self.assertIn("operational_profile_id')->nullable()", migration)
        self.assertIn("vault_triage_asset_org_foreign", migration)
        self.assertIn("assertNull($item->operational_profile_id)", feature)
        self.assertIn("assertNull($item->assigned_by_user_id)", feature)

    def test_authorized_operator_assigns_profile_with_auditable_transition(self):
        service = (ROOT / "app/Support/Vault/VaultTriageQueue.php").read_text(encoding="utf-8")
        migration = (
            ROOT / "database/migrations/2026_10_03_000200_create_vault_triage_items_table.php"
        ).read_text(encoding="utf-8")
        feature = (ROOT / "tests/Feature/VaultTriageTest.php").read_text(encoding="utf-8")

        self.assertIn("canManageOrganization", service)
        self.assertIn("actorId()", service)
        self.assertIn("lockForUpdate()", service)
        self.assertIn("'assigned_by_user_id' => $operator->getKey()", service)
        self.assertIn("'assigned_at' => now()", service)
        self.assertIn("vault_triage_profile_org_foreign", migration)
        self.assertIn("test_cross_tenant_unauthorized_or_reassignment_is_rejected", feature)
        self.assertIn("catch (AuthorizationException)", feature)
        self.assertIn("catch (LogicException)", feature)


if __name__ == "__main__":
    unittest.main()
