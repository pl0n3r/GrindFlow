from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class MediaPilotReadinessContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = (ROOT / "docs/PILOT-MEDIA-READINESS.md").read_text(encoding="utf-8")
        cls.controller = (
            ROOT / "symfony/src/Http/Controller/ScheduleDraftController.php"
        ).read_text(encoding="utf-8")
        cls.materializer = (
            ROOT / "symfony/src/Infrastructure/Storage/VaultPhotoSafetyMaterializer.php"
        ).read_text(encoding="utf-8")
        cls.materializer_test = (
            ROOT / "symfony/tests/php/VaultPhotoSafetyMaterializerTest.php"
        ).read_text(encoding="utf-8")
        cls.schedule_test = (
            ROOT / "symfony/tests/php/ScheduleDraftTest.php"
        ).read_text(encoding="utf-8")
        cls.readiness = (ROOT / "scripts/media-pilot-readiness.php").read_text(
            encoding="utf-8"
        )
        cls.scheduler = (
            ROOT / "app/Services/Scheduling/ContentScheduler.php"
        ).read_text(encoding="utf-8")
        cls.workflow = (ROOT / ".github/workflows/grindflow-ci.yml").read_text(
            encoding="utf-8"
        )

    def test_pilot_scope_is_private_image_only_and_storage_external_remains_conditional(self) -> None:
        self.assertIn("JPEG y PNG", self.doc)
        self.assertIn("8 MiB", self.doc)
        self.assertIn("Video está fuera del piloto inicial", self.doc)
        self.assertIn("#146", self.doc)
        self.assertIn("dependencia **condicional**", self.doc)
        self.assertIn("Quick Upload", self.doc)
        self.assertIn("Vault privado local", self.doc)

    def test_private_photo_is_decoder_reencoded_before_provider_io(self) -> None:
        for token in (
            "imagecreatefromstring",
            "imagejpeg",
            "imagepng",
            "tempnam",
            "chmod($temporary, 0600)",
            "MAX_PIXELS = 32_000_000",
        ):
            self.assertIn(token, self.materializer)
        publish = self.controller.split("public function publishFacebook", 1)[1].split(
            "/** Cancel in place", 1
        )[0]
        self.assertIn("VaultPhotoSafetyMaterializer $photoSafety", publish)
        self.assertIn("$safeMediaPath = $photoSafety->materialize(", publish)
        self.assertLess(
            publish.index("$photoSafety->materialize("),
            publish.index("'delivery_locked_at' => $lockedAt"),
        )
        self.assertLess(
            publish.index("'delivery_locked_at' => $lockedAt"),
            publish.index("$publication->publish($command)"),
        )
        self.assertIn("$safeMediaPath,\n            (string) $draft['mime_type']", publish)
        self.assertIn("$photoSafety->cleanup($safeMediaPath);", publish)
        self.assertIn("str_starts_with(basename($filePath), 'gf-photo-')", self.schedule_test)

    def test_untrusted_or_unprocessable_photo_fails_before_delivery_lock(self) -> None:
        for token in (
            "trailing payload",
            "MAX_DIMENSION = 8192",
            "MAX_PIXELS = 32_000_000",
            "Image decoder rejected",
            "Safe image re-encode failed",
            "assertMemoryAvailable",
            "memory_get_usage(true)",
            "ini_get('memory_limit')",
        ):
            self.assertIn(token, self.materializer)
        publish = self.controller.split("public function publishFacebook", 1)[1].split(
            "/** Cancel in place", 1
        )[0]
        self.assertIn("return ['status' => 'media_unsafe'];", publish)
        self.assertIn("'media_not_safe_to_publish'", publish)
        self.assertIn("'trailing-payload'", self.schedule_test)
        self.assertIn("self::assertNull($db->fetchOne(", self.schedule_test)
        self.assertIn("self::assertSame(0, $transport->calls);", self.schedule_test)
        self.assertIn("testTrailingPayloadAndCorruptContentFailWithoutLeakingTemporaryCopy", self.materializer_test)
        self.assertIn("GD decoder is required in CI.", self.materializer_test)

    def test_target_readiness_is_observed_separately_from_ci(self) -> None:
        for token in (
            "'decoder'",
            "'temporary_storage'",
            "'private_vault'",
            "'evidence_scope' => 'cli_diagnostic'",
            "'ci_equivalent' => false",
        ):
            self.assertIn(token, self.readiness)
        self.assertIn("php scripts/media-pilot-readiness.php", self.doc)
        self.assertIn("GET /api/admin/schedules/media-readiness", self.doc)
        self.assertIn("evidence_scope=cli_diagnostic", self.doc)
        self.assertIn("evidence_scope\":\"web_runtime", self.doc)
        self.assertIn("'/api/admin/schedules/media-readiness'", self.controller)
        self.assertIn("'evidence_scope' => 'web_runtime'", self.controller)
        self.assertIn("$photoSafety->runtimeReadiness($vaultRoot)", self.controller)
        self.assertIn("CI no sustituye la observación del entorno objetivo", self.doc)
        self.assertIn("no imprime paths, secretos, hashes", self.doc)
        self.assertNotIn("echo $root", self.readiness)
        self.assertNotIn("echo $path", self.readiness)

    def test_failed_processing_is_not_schedulable_and_tenant_gates_remain_closed(self) -> None:
        self.assertIn("where('metadata->processing->status', 'completed')", self.scheduler)
        self.assertIn("$this->processor->currentVersion()", self.scheduler)
        self.assertIn("($processing['status'] ?? null) === 'completed'", self.scheduler)
        self.assertIn("(string) $asset->organization_id !== $organizationId", self.scheduler)
        self.assertIn("The active tenant does not match this scheduling request", self.scheduler)
        self.assertIn(
            "tests/test_media_pilot_readiness_contract.py",
            self.workflow,
            "The pre-pilot contract must run explicitly from GrindFlow CI / fast.",
        )


if __name__ == "__main__":
    unittest.main()
