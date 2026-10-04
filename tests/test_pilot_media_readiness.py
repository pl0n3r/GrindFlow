from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
DOC_PATH = ROOT / "docs" / "PILOT-MEDIA-READINESS.md"


class PilotMediaReadinessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = DOC_PATH.read_text(encoding="utf-8")
        cls.lower = cls.doc.lower()

    def test_initial_pilot_path_is_quick_upload_images_and_s3_remains_conditional(self) -> None:
        self.assertIn("Quick Upload", self.doc)
        self.assertIn("JPEG y PNG", self.doc)
        self.assertIn("8 MiB", self.doc)
        self.assertIn("#146", self.doc)
        self.assertIn("dependencia **condicional**", self.doc)
        self.assertIn("No son blocker", self.doc)

    def test_untrusted_media_strategy_is_fail_closed_without_false_antimalware_claim(self) -> None:
        self.assertIn("decode real", self.lower)
        self.assertIn("re-encode", self.lower)
        self.assertIn("fail closed", self.lower)
        self.assertIn("no es un antivirus", self.lower)
        self.assertIn("original permanece privado", self.lower)

    def test_failed_processing_never_becomes_publishable_and_video_requires_real_evidence(self) -> None:
        self.assertIn("procesamiento fallido", self.lower)
        self.assertIn("no es elegible para scheduling", self.lower)
        self.assertIn("video está fuera del piloto inicial", self.lower)
        self.assertIn("ffmpeg/ffprobe", self.lower)

    def test_build_ahead_ready_never_claims_target_environment_readiness(self) -> None:
        self.assertIn("BUILD_AHEAD_READY", self.doc)
        self.assertIn("BLOCKED_TARGET_ENV", self.doc)
        self.assertIn("CI no sustituye la observación del entorno objetivo", self.doc)
        self.assertIn("temporary_storage", self.doc)
        self.assertIn("private_vault", self.doc)
        self.assertIn("ci_equivalent", self.doc)


if __name__ == "__main__":
    unittest.main()
