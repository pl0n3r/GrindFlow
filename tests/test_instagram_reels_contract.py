"""#264: contrato offline del segundo provider Symfony (no red real)."""

import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class InstagramReelsContractTests(unittest.TestCase):
    def read(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_symfony_adapter_and_official_resumable_flow(self):
        provider = self.read("symfony/src/Distribution/InstagramReelProvider.php")
        transport = self.read("symfony/src/Distribution/StreamInstagramReelTransport.php")
        port = self.read("symfony/src/Distribution/DistributionProvider.php")
        self.assertIn("implements DistributionProvider", provider)
        self.assertIn("interface DistributionProvider", port)
        for phase in ("createContainer(", "uploadVideo(", "containerStatus(", "publishContainer("):
            self.assertIn(phase, provider)
        self.assertIn("upload_type' => 'resumable'", transport)
        self.assertIn("media_type' => 'REELS'", transport)
        self.assertIn("media_publish", transport)
        self.assertIn("https://graph.facebook.com/", transport)
        self.assertIn("rupload.facebook.com", transport)
        self.assertIn("'FINISHED'", provider)
        self.assertNotIn("video_url", provider + transport)

    def test_config_tenant_and_media_are_fail_closed(self):
        config = self.read("symfony/src/Distribution/InstagramReelConfiguration.php")
        provider = self.read("symfony/src/Distribution/InstagramReelProvider.php")
        command = self.read("symfony/src/Distribution/DistributionCommand.php")
        facebook = self.read("symfony/src/Distribution/FacebookPageProvider.php")
        self.assertIn("!$this->enabled", config)
        self.assertIn("hash_equals", config)
        self.assertIn("'instagram_content_publish'", config)
        self.assertIn("'instagram_business_content_publish'", config)
        self.assertIn("hash_file('sha256'", provider)
        self.assertIn("is_link($path)", provider)
        self.assertIn("new \\finfo(FILEINFO_MIME_TYPE)", provider)
        self.assertIn("'video/mp4'", command)
        self.assertIn("!in_array($command->mediaMime, ['image/jpeg', 'image/png']", facebook)
        self.assertIn("DistributionProviderException::rejected()", provider)

    def test_shared_ledger_and_no_blind_replay(self):
        ledger = self.read("symfony/src/Distribution/ExternalPublicationLedger.php")
        service = self.read("symfony/src/Distribution/InstagramReelPublicationService.php")
        migration = self.read("symfony/migrations/Version20261003173000.php")
        self.assertIn("gf_external_publication_attempts", ledger)
        self.assertIn("UNIQUE KEY uq_gf_external_attempt_key", migration)
        self.assertIn("hash_equals", ledger)
        self.assertIn("'published' => $this->storedOutcome", ledger)
        self.assertIn("'ambiguous', 'in_flight' => throw DistributionProviderException::ambiguous()", ledger)
        self.assertIn("new ExternalPublicationLedger", service)
        self.assertIn("'instagram_reels'", service)
        self.assertNotIn("CREATE TABLE", service)
        self.assertIn("retry_not_before", ledger)
        self.assertIn("$this->resumeRateLimited($db, $command, $row, $now, $provider)", ledger)
        self.assertIn("organization_id", ledger)

    def test_fake_only_ci_and_live_separation(self):
        tests = self.read("symfony/tests/php/InstagramReelProviderTest.php")
        config = self.read("symfony/config/services.yaml")
        guide = self.read("docs/AGENT-DISTRIBUTION.md")
        self.assertIn("FakeInstagramReelTransport", tests)
        self.assertIn("GRINDFLOW_INSTAGRAM_REELS_ENABLED", config)
        self.assertIn("grindflow_instagram_enabled_default: 'false'", config)
        self.assertNotIn("new StreamInstagramReelTransport", tests)
        self.assertIn("autorización del dueño", guide)
        self.assertIn("CI", guide)

    def test_no_credentials_or_raw_data_in_logs_or_public_metadata(self):
        provider = self.read("symfony/src/Distribution/InstagramReelProvider.php")
        ledger = self.read("symfony/src/Distribution/ExternalPublicationLedger.php")
        pdata = json.loads(self.read("datos.yml"))
        entry = next(x for x in pdata["treatments"] if x["id"] == "instagram_reels_publication")
        self.assertEqual(entry["basis"], "review_required")
        self.assertIn("meta_instagram", entry["providers"])
        self.assertNotIn("access_token", entry["fields"])
        self.assertNotIn("mediaPath", ledger)
        self.assertNotIn("accessToken", ledger)
        self.assertNotIn("errorMessage", ledger)
        self.assertNotIn("errorBody", ledger)
        self.assertNotIn("errorBody", provider)


if __name__ == "__main__":
    unittest.main()
