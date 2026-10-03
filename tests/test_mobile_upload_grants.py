import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/mobile_upload_grant_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class MobileUploadGrantTests(unittest.TestCase):
    def test_temporary_grant_is_tenant_scoped_expiring_and_bounded(self):
        data = scenario("valid")
        token = data["token"]
        grant = data["validated"]

        self.assertTrue(token.startswith("v1."))
        self.assertEqual(grant["scope"], "guest-upload")
        self.assertEqual(grant["organization_id"], "org-123")
        self.assertEqual(grant["issued_at"], 1000)
        self.assertEqual(grant["expires_at"], 2000)
        self.assertEqual(grant["max_files"], 5)
        self.assertEqual(grant["max_bytes"], 10485760)
        self.assertEqual(grant["nonce"], "nonce-1")

        boundary = data["boundary"]
        self.assertEqual(boundary["max_files"], 25)
        self.assertEqual(boundary["max_bytes"], 104857600)
        self.assertEqual(boundary["nonce"], "nonce-max")

    def test_invalid_expired_or_cross_tenant_grant_fails_closed(self):
        data = scenario("invalid")

        for key in (
            "tampered",
            "expired",
            "cross_tenant",
            "future_issued",
            "too_long_issue",
            "too_long_signed",
            "zero_files",
            "zero_bytes",
            "too_many_files",
            "too_many_bytes",
        ):
            self.assertFalse(data[key]["ok"], key)

        self.assertIn("signature", data["tampered"]["message"].lower())
        self.assertIn("expired", data["expired"]["message"].lower())
        self.assertIn("tenant", data["cross_tenant"]["message"].lower())
        self.assertIn("not active", data["future_issued"]["message"].lower())
        self.assertIn("lifetime", data["too_long_issue"]["message"].lower())
        self.assertIn("lifetime", data["too_long_signed"]["message"].lower())
        self.assertIn("limits", data["zero_files"]["message"].lower())
        self.assertIn("limits", data["zero_bytes"]["message"].lower())
        self.assertIn("limits", data["too_many_files"]["message"].lower())
        self.assertIn("limits", data["too_many_bytes"]["message"].lower())


if __name__ == "__main__":
    unittest.main()
