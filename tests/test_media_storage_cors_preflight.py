"""Synthetic, side-effect-free tests for a declared S3 bucket CORS policy."""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import socket
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/media-storage-cors-preflight.py"
ORIGIN = "https://www.grindflow.com.co"


def policy() -> dict[str, object]:
    return {"CORSRules": [{
        "AllowedOrigins": [ORIGIN],
        "AllowedMethods": ["PUT"],
        "AllowedHeaders": ["Content-Type", "x-amz-*"],
    }]}


def load_module():
    spec = importlib.util.spec_from_file_location("gf528_cors", SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class MediaStorageCorsPreflightTests(unittest.TestCase):
    def test_exact_origin_put_and_headers_in_same_rule(self) -> None:
        m = load_module()
        self.assertEqual("compatible", m.assess(policy(), ORIGIN))
        split = {"CORSRules": [
            {"AllowedOrigins": [ORIGIN], "AllowedMethods": ["GET"],
             "AllowedHeaders": ["Content-Type", "x-amz-*"]},
            {"AllowedOrigins": ["https://other.example"], "AllowedMethods": ["PUT"],
             "AllowedHeaders": ["Content-Type", "x-amz-*"]},
        ]}
        self.assertEqual("incompatible", m.assess(split, ORIGIN))

    def test_invalid_or_untrusted_policy_fails_closed_without_leak(self) -> None:
        m = load_module()
        for candidate, origin, expected in (
            ({"CORSRules": [{**policy()["CORSRules"][0], "AllowedOrigins": ["*"]}]}, ORIGIN, "incompatible"),
            ({"CORSRules": [{**policy()["CORSRules"][0], "AllowedOrigins": ["https://*.grindflow.com.co"]}]}, ORIGIN, "incompatible"),
            ({"CORSRules": [{**policy()["CORSRules"][0], "AllowedMethods": ["GET"]}]}, ORIGIN, "incompatible"),
            ({"CORSRules": [{**policy()["CORSRules"][0], "AllowedHeaders": ["Content-Type"]}]}, ORIGIN, "incompatible"),
            ({"CORSRules": [{}]}, ORIGIN, "unknown"),
            ({"CORSRules": []}, ORIGIN, "unknown"),
            (policy(), "http://www.grindflow.com.co", "unknown"),
            (policy(), "https://www.grindflow.com.co@evil.test", "unknown"),
            ({"CORSRules": [{**policy()["CORSRules"][0], "AllowedOrigins": [ORIGIN + ":443"]}]},
             ORIGIN + ":443", "unknown"),
        ):
            with self.subTest(candidate=candidate, origin=origin):
                self.assertEqual(expected, m.assess(candidate, origin))
        for raw in (
            "", "{", '{"CORSRules":[],"CORSRules":[]}',
            "x" * (128 * 1024 + 1),
            '{"secret":"s3-secret-leak"}',
            "[" * 4000,
        ):
            with self.subTest(raw=raw[:20]):
                proc = subprocess.run([sys.executable, str(SCRIPT), "--origin", ORIGIN],
                                      input=raw, text=True, capture_output=True, check=False)
                self.assertEqual(2, proc.returncode)
                output = json.loads(proc.stdout)
                self.assertEqual("unknown", output["declared_status"])
                self.assertIs(output["target_verified"], False)
                self.assertEqual("offline_configuration_only", output["evidence_scope"])
                self.assertNotIn("s3-secret-leak", proc.stdout + proc.stderr)

    def test_cli_is_offline_and_never_claims_bucket_ready(self) -> None:
        with patch.object(socket.socket, "connect", side_effect=AssertionError("network")), \
             patch.object(socket, "create_connection", side_effect=AssertionError("network")):
            module = load_module()
            self.assertEqual("compatible", module.assess(policy(), ORIGIN))
        proc = subprocess.run([sys.executable, str(SCRIPT), "--origin", ORIGIN],
                              input=json.dumps(policy()), text=True, capture_output=True, check=False)
        self.assertEqual(0, proc.returncode, proc.stderr)
        output = json.loads(proc.stdout)
        self.assertEqual("compatible", output["declared_status"])
        self.assertEqual("offline_configuration_only", output["evidence_scope"])
        self.assertIs(output["target_verified"], False)
        self.assertNotIn(ORIGIN, proc.stdout)
        self.assertNotIn("MEDIA_STORAGE_READY", proc.stdout)
        self.assertEqual("", proc.stderr)


if __name__ == "__main__":
    unittest.main()
