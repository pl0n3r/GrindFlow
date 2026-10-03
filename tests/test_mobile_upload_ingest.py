from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class MobileUploadIngestTests(unittest.TestCase):
    def test_guest_upload_enters_vault_with_explicit_profile_or_triage(self):
        service = (
            ROOT / "app/Services/Media/GuestMobileUploadIngestor.php"
        ).read_text(encoding="utf-8")
        model = (
            ROOT / "app/Models/MobileUploadGrantUse.php"
        ).read_text(encoding="utf-8")
        migration = (
            ROOT
            / "database/migrations/2026_10_03_000300_create_mobile_upload_grant_uses_table.php"
        ).read_text(encoding="utf-8")
        tenant = (
            ROOT / "app/Support/Tenancy/TenantContext.php"
        ).read_text(encoding="utf-8")
        feature = (
            ROOT / "tests/Feature/GuestMobileUploadIngestorTest.php"
        ).read_text(encoding="utf-8")

        self.assertIn("runWithinGuestOrganization", tenant)
        self.assertIn("$this->actorId = null", tenant)
        self.assertIn("Organization::query()->whereKey", tenant)
        self.assertIn("MobileUploadGrantUse::query()->create", service)
        self.assertIn("validateForGuest($token, $now)", service)
        self.assertNotIn("expectedOrganizationId", service)
        self.assertIn("'ingested_by_user_id' => null", service)
        self.assertIn("OperationalProfile::query()->find($profileId)", service)
        self.assertIn("'profile_id' => $profile->getKey()", service)
        self.assertIn("$this->triage->queueAmbiguous($asset)", service)
        self.assertIn("mobile_upload_grant_uses_org_nonce_unique", migration)
        self.assertIn("'nonce'", model)
        self.assertIn(
            "test_guest_upload_enters_vault_with_explicit_profile_or_triage",
            feature,
        )

    def test_invalid_media_and_ambiguous_owner_never_bypass_validation_or_triage(self):
        service = (
            ROOT / "app/Services/Media/GuestMobileUploadIngestor.php"
        ).read_text(encoding="utf-8")
        feature = (
            ROOT / "tests/Feature/GuestMobileUploadIngestorTest.php"
        ).read_text(encoding="utf-8")

        self.assertIn("$grant['max_files']", service)
        self.assertIn("$grant['max_bytes']", service)
        self.assertIn("$file->getSize()", service)
        self.assertIn("$file->getMimeType()", service)
        self.assertIn("grindflow.media.allowed_mimetypes", service)
        self.assertIn("hash_file('sha256'", service)
        self.assertIn("already been consumed", service)
        self.assertIn("Guest upload profile is invalid for this tenant.", service)
        self.assertNotIn("withoutGlobalScopes", service)
        self.assertIn(
            "test_invalid_media_and_ambiguous_owner_never_bypass_validation_or_triage",
            feature,
        )

    def test_release_identity_matches_across_manifests(self):
        php = (ROOT / "config/version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        lock = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))

        match = re.search(r"'number'\s*=>\s*'([^']+)'", php)
        self.assertIsNotNone(match)
        self.assertEqual(match.group(1), "0.1.165")
        self.assertEqual(package["version"], "0.1.165")
        self.assertEqual(lock["version"], "0.1.165")
        self.assertEqual(lock["packages"][""]["version"], "0.1.165")


if __name__ == "__main__":
    unittest.main()
