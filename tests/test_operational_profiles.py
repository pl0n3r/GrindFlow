from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class OperationalProfileTests(unittest.TestCase):
    """Verify source-level contracts for tenant-scoped operational profiles."""

    def test_profile_identity_is_distinct_from_membership_and_tenant_scoped(self):
        """Profile storage must stay independent from membership and user identity."""
        model = (ROOT / "app/Models/OperationalProfile.php").read_text(encoding="utf-8")
        migration = (
            ROOT / "database/migrations/2026_10_03_000100_create_operational_profiles_table.php"
        ).read_text(encoding="utf-8")
        feature = (ROOT / "tests/Feature/OperationalProfileTest.php").read_text(encoding="utf-8")

        self.assertIn("class OperationalProfile extends TenantModel", model)
        self.assertIn("use HasUuids;", model)
        self.assertNotIn("membership_id", model)
        self.assertNotIn("user_id", model)

        self.assertIn("Schema::create('operational_profiles'", migration)
        self.assertIn("$table->foreignUuid('organization_id')", migration)
        self.assertIn("$table->unique(['organization_id', 'slug'])", migration)
        self.assertNotIn("membership_id", migration)
        self.assertNotIn("user_id", migration)

        self.assertIn("assertNotSame($membership->id, $profile->id)", feature)
        self.assertIn("OperationalProfile::query()->pluck('name')->all()", feature)

    def test_profile_access_rejects_cross_tenant_and_missing_membership(self):
        """Tenant protections must reject cross-tenant writes and missing membership."""
        model = (ROOT / "app/Models/OperationalProfile.php").read_text(encoding="utf-8")
        feature = (ROOT / "tests/Feature/OperationalProfileTest.php").read_text(encoding="utf-8")

        self.assertIn("extends TenantModel", model)
        self.assertIn("AuthorizationException::class", feature)
        self.assertIn("'organization_id' => $organizationB->id", feature)
        self.assertIn("assertSame($organizationA->id, $massAssigned->organization_id)", feature)
        self.assertIn("assertDatabaseMissing('operational_profiles'", feature)
        self.assertIn("forceFill(['organization_id' => $organizationB->id])", feature)
        self.assertIn("test_missing_membership_cannot_enter_profile_tenant_context", feature)
        self.assertIn("runWithinOrganization", feature)


if __name__ == "__main__":
    unittest.main()
