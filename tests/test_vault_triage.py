from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class VaultTriageTests(unittest.TestCase):
    def test_ambiguous_asset_enters_triage_without_guessing_owner(self):
        media = (ROOT / "app/Models/MediaAsset.php").read_text(encoding="utf-8")
        service = (ROOT / "app/Services/Media/VaultOwnershipTriage.php").read_text(encoding="utf-8")
        migration = (
            ROOT / "database/migrations/2026_10_03_000200_create_vault_triage_items_table.php"
        ).read_text(encoding="utf-8")
        feature = (ROOT / "tests/Feature/VaultTriageTest.php").read_text(encoding="utf-8")

        self.assertIn("function profile(): BelongsTo", media)
        self.assertNotIn("'profile_id',", media.split("protected $fillable =", 1)[1].split("];", 1)[0])
        self.assertIn("queueAmbiguous", service)
        self.assertIn("Asset already has an explicit operational profile.", service)
        self.assertIn("media_assets_profile_org_foreign", migration)
        self.assertIn("assertNull($asset->refresh()->profile_id)", feature)
        self.assertIn("assertNull($item->assigned_profile_id)", feature)

    def test_authorized_operator_assigns_profile_with_auditable_transition(self):
        service = (ROOT / "app/Services/Media/VaultOwnershipTriage.php").read_text(encoding="utf-8")
        migration = (
            ROOT / "database/migrations/2026_10_03_000200_create_vault_triage_items_table.php"
        ).read_text(encoding="utf-8")
        feature = (ROOT / "tests/Feature/VaultTriageTest.php").read_text(encoding="utf-8")

        self.assertIn("canManageOrganization", service)
        self.assertIn("actorId()", service)
        self.assertIn("DB::transaction", service)
        self.assertGreaterEqual(service.count("lockForUpdate()"), 2)
        self.assertIn("'profile_id' => $profile->getKey()", service)
        self.assertIn("'assigned_profile_id' => $profile->getKey()", service)
        self.assertIn("'assigned_by_user_id' => $actor->getKey()", service)
        self.assertIn("'assigned_at' => now()", service)
        self.assertIn("vault_triage_assigned_profile_org_foreign", migration)
        self.assertIn("test_cross_tenant_unauthorized_or_reassignment_is_rejected", feature)

    def test_privacy_inventory_declares_assignment_audit_fields(self):
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        media_vault = next(row for row in data["treatments"] if row["id"] == "media_vault")

        self.assertIn("profile_id", media_vault["fields"])
        self.assertIn("assigned_by_user_id", media_vault["fields"])
        self.assertIn("assigned_at", media_vault["fields"])
        self.assertEqual(media_vault["purpose"], "media_management")
        self.assertEqual(media_vault["basis"], "review_required")
        self.assertEqual(media_vault["retention"], "review_required")
        self.assertEqual(media_vault["consent"], "review_required")

        for name in (
            "politica-tratamiento.md",
            "aviso-privacidad.md",
            "registro-tratamientos.md",
        ):
            content = (ROOT / "docs/privacidad" / name).read_text(encoding="utf-8")
            self.assertIn("assigned_by_user_id", content, name)
            self.assertIn("assigned_at", content, name)

        retention = (ROOT / "docs/privacidad/retencion.md").read_text(encoding="utf-8")
        self.assertIn("| media_vault | usage | review_required | review_required |", retention)

    def test_release_identity_matches_across_manifests(self):
        php = (ROOT / "config/version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        lock = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))

        match = re.search(r"'number'\s*=>\s*'([^']+)'", php)
        self.assertIsNotNone(match)
        self.assertEqual(match.group(1), "0.1.163")
        self.assertEqual(package["version"], "0.1.163")
        self.assertEqual(lock["version"], "0.1.163")
        self.assertEqual(lock["packages"][""]["version"], "0.1.163")


if __name__ == "__main__":
    unittest.main()
