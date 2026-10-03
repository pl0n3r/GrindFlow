from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class MobileUploadUxTests(unittest.TestCase):
    def test_guest_mobile_flow_previews_valid_files_and_surfaces_rejections(self):
        view = (
            ROOT / "resources/views/vault/guest-upload.blade.php"
        ).read_text(encoding="utf-8")
        css = (ROOT / "public/css/grindflow.css").read_text(encoding="utf-8")
        feature = (
            ROOT / "tests/Feature/GuestMobileUploadFlowTest.php"
        ).read_text(encoding="utf-8")

        self.assertIn("multiple", view)
        self.assertIn("accept=", view)
        self.assertIn("data-max-files", view)
        self.assertIn("data-max-bytes", view)
        self.assertIn("URL.createObjectURL", view)
        self.assertIn("URL.revokeObjectURL", view)
        self.assertIn("addRejection", view)
        self.assertIn("textContent", view)
        self.assertIn("Retirar", view)
        self.assertIn("aria-live=\"polite\"", view)
        self.assertNotIn(".innerHTML", view)
        self.assertIn(".gf-upload-previews", css)
        self.assertIn("@media(max-width:520px)", css)
        self.assertIn(
            "test_guest_mobile_flow_previews_valid_files_and_surfaces_rejections",
            feature,
        )

    def test_guest_mobile_flow_works_without_authenticated_session_and_preserves_tenant_scope(self):
        routes = (ROOT / "routes/web.php").read_text(encoding="utf-8")
        controller = (
            ROOT / "app/Http/Controllers/Vault/GuestMobileUploadController.php"
        ).read_text(encoding="utf-8")
        ingestor = (
            ROOT / "app/Services/Media/GuestMobileUploadIngestor.php"
        ).read_text(encoding="utf-8")
        provider = (
            ROOT / "app/Providers/AppServiceProvider.php"
        ).read_text(encoding="utf-8")
        config = (ROOT / "config/grindflow.php").read_text(encoding="utf-8")
        view = (
            ROOT / "resources/views/vault/guest-upload.blade.php"
        ).read_text(encoding="utf-8")
        feature = (
            ROOT / "tests/Feature/GuestMobileUploadFlowTest.php"
        ).read_text(encoding="utf-8")

        self.assertIn("guest.upload.show", routes)
        self.assertIn("guest.upload.store", routes)
        public_routes = routes.split("Route::middleware('guest')->group", 1)[0]
        self.assertIn("GuestMobileUploadController", public_routes)
        self.assertNotIn("middleware('auth')", public_routes)
        self.assertIn("$this->ingestor->ingest(", controller)
        self.assertIn("validateForGuest", controller)
        view_payload = controller.split("return response()->view", 1)[1].split("], $status)", 1)[0]
        self.assertNotIn("organization_id", view_payload)
        self.assertIn("$grant['organization_id']", ingestor)
        self.assertIn("'ingested_by_user_id' => null", ingestor)
        self.assertIn("MobileUploadGrant::class", provider)
        self.assertIn(
            "'guest_upload_signing_key' => env('MOBILE_UPLOAD_SIGNING_KEY'),",
            config,
        )
        self.assertIn("Referrer-Policy", controller)
        self.assertIn("no-referrer", controller)
        self.assertIn("AuthorizationException", controller)
        self.assertLess(
            view.index('class="gf-upload-picker__input"'),
            view.index('class="gf-upload-picker__label"'),
        )
        self.assertIn(
            "test_guest_mobile_flow_works_without_authenticated_session_and_preserves_tenant_scope",
            feature,
        )
        self.assertIn("$this->assertDatabaseCount(\'media_assets\', 1)", feature)
        self.assertIn("$this->assertDatabaseCount(\'mobile_upload_grant_uses\', 1)", feature)
        self.assertIn("$this->assertGuest()", feature)


if __name__ == "__main__":
    unittest.main()
