from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class S4ComposerExternalDeliveryContractTests(unittest.TestCase):
    def text(self, relative: str) -> str:
        return (ROOT / relative).read_text(encoding="utf-8")

    def migrations(self) -> str:
        return "\n".join(
            path.read_text(encoding="utf-8")
            for path in sorted((ROOT / "symfony/migrations").glob("*.php"))
        )

    def test_composer_contract_persists_asset_caption_destination_and_schedule(self):
        controller = self.text("symfony/src/Http/Controller/ScheduleDraftController.php")
        panel = self.text("symfony/frontend/admin/ScheduleDraftPanel.tsx")
        migrations = self.migrations()

        for field in (
            "caption",
            "delivery_provider",
            "delivery_destination_id",
            "delivery_locked_at",
        ):
            self.assertIn(field, migrations)
            self.assertIn(field, controller)

        self.assertIn("asset_id", controller)
        self.assertIn("scheduled_at_utc", controller)
        self.assertIn("caption", panel)
        self.assertIn("delivery_destination", panel)
        self.assertIn("/delivery-intent", controller + panel)
        self.assertIn("/publish-facebook", controller + panel)
        self.assertIn("external_destination_id", controller + panel)

    def test_authorization_and_immutability_fail_closed(self):
        controller = self.text("symfony/src/Http/Controller/ScheduleDraftController.php")
        manual_handoff = self.text("symfony/src/Http/Controller/ManualHandoffController.php")
        manual_lock_test = self.text("symfony/tests/php/ManualHandoffExternalDeliveryLockTest.php")
        migrations = self.migrations()

        self.assertIn("content_prepare", controller)
        self.assertIn("grindflow_schedule_draft", controller)
        self.assertIn("organization_id", controller)
        self.assertIn("delivery_locked_at", controller)
        self.assertIn("assetEligibility", controller)
        self.assertIn("delivery_intent_locked", controller)
        self.assertIn("delivery_destination_id", controller)
        self.assertIn("delivery_locked_at", manual_handoff)
        self.assertIn("external_delivery_locked", manual_handoff)
        self.assertIn("external_delivery_locked", manual_lock_test)
        self.assertIn("gf_manual_handoff_events", manual_lock_test)
        self.assertIn("delivery provider and destination must be a complete pair", migrations)
        self.assertIn("delivery_provider IS NOT NULL", migrations)
        self.assertIn("delivery_destination_id IS NOT NULL", migrations)
        self.assertIn("gf_schedule_drafts_lifecycle_update", migrations)
        self.assertIn("schedule draft", migrations.lower())
        self.assertIn("cannot", migrations.lower())

    def test_delivery_contract_is_idempotent_and_fingerprint_bound(self):
        controller = self.text("symfony/src/Http/Controller/ScheduleDraftController.php")
        command = self.text("symfony/src/Distribution/DistributionCommand.php")
        service = self.text("symfony/src/Distribution/FacebookPagePublicationService.php")

        self.assertIn("schedule-draft:", controller)
        self.assertIn("mediaSha256", command)
        self.assertIn("mediaMime", command)
        self.assertIn("$payload['media']", service)
        self.assertIn("'sha256'", service)
        self.assertIn("hash_equals", service)
        self.assertIn("'published' => $this->storedOutcome", service)

    def test_external_states_are_safe_and_ambiguous_is_not_retried(self):
        controller = self.text("symfony/src/Http/Controller/ScheduleDraftController.php")
        service = self.text("symfony/src/Distribution/FacebookPagePublicationService.php")
        exception = self.text("symfony/src/Distribution/DistributionProviderException.php")

        for state in (
            "published",
            "ambiguous",
            "rate_limited",
            "authentication_failed",
            "rejected",
        ):
            self.assertIn(state, service + controller)
        self.assertIn("'ambiguous', 'in_flight' => throw", service)
        self.assertIn("external_delivery_status === 'ambiguous'", self.text("symfony/frontend/admin/ScheduleDraftPanel.tsx"))
        self.assertIn("KIND_AMBIGUOUS, false", " ".join(exception.split()))
        self.assertNotIn("access_token", controller.lower())

    def test_verified_private_photo_route_is_explicit_and_fail_closed(self):
        controller = self.text("symfony/src/Http/Controller/ScheduleDraftController.php")
        transport = self.text("symfony/src/Distribution/StreamFacebookPageTransport.php")
        transport_port = self.text("symfony/src/Distribution/FacebookPageTransport.php")
        provider = self.text("symfony/src/Distribution/FacebookPageProvider.php")

        self.assertIn("VaultBlobVerifier", controller)
        self.assertIn("PrivateVaultDirectory", controller)
        self.assertIn("image/jpeg", controller + transport)
        self.assertIn("image/png", controller + transport)
        self.assertIn("/%s/photos", transport)
        self.assertIn("multipart/form-data", transport)
        self.assertIn("finfo", transport)
        self.assertIn("MAX_PHOTO_BYTES", transport)
        self.assertIn("postPhoto", provider)
        self.assertIn("postPhoto", transport_port)
        self.assertIn("catch (InvalidArgumentException)", provider)
        self.assertIn("throw new InvalidArgumentException('facebook_photo_source_invalid'", transport)
        self.assertNotIn("public_url", controller.lower())

    def test_draft_lock_and_external_ledger_have_single_responsibility(self):
        controller = self.text("symfony/src/Http/Controller/ScheduleDraftController.php")
        service = self.text("symfony/src/Distribution/FacebookPagePublicationService.php")
        migrations = self.migrations()

        self.assertIn("delivery_locked_at", migrations)
        self.assertIn("delivery_locked_at", controller)
        self.assertIn("gf_external_publication_attempts", service)
        self.assertNotIn("external_publication_id", migrations.split("gf_schedule_drafts")[-1][:5000])
        self.assertIn("getTransactionNestingLevel() > 0", service)
        self.assertIn("FacebookPagePublicationService", controller)
        self.assertLess(
            controller.index("$db->transactional"),
            controller.index("$publication->publish"),
        )

    def test_ci_never_performs_live_publication_and_live_evidence_is_separate(self):
        provider_test = self.text("symfony/tests/php/FacebookPageProviderTest.php")
        smoke = self.text(".github/workflows/production-smoke.yml")
        controller = self.text("symfony/src/Http/Controller/ScheduleDraftController.php")

        self.assertIn("FakeFacebookPageTransport", provider_test)
        self.assertNotIn("new StreamFacebookPageTransport", provider_test)
        self.assertNotIn("FacebookPagePublicationService", smoke)
        self.assertIn("external_publication_id", controller)
        self.assertIn("published_at", controller)


if __name__ == "__main__":
    unittest.main()
