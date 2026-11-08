"""Pillow wheel provenance guard for the worker Docker image."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = (ROOT / "workers/requirements.txt").read_text(encoding="utf-8")
LOCK = (ROOT / "workers/requirements.lock").read_text(encoding="utf-8")
DOCKER = (ROOT / "workers/Dockerfile").read_text(encoding="utf-8")
# Official PyPI wheel digests for Pillow 13.0.0 CPython 3.11 Linux.
LINUX_X86_SHA256 = "a625df9a2a5597fa5e5266e01aefc82604bb85d291e3547981e863560d75da6c"
LINUX_ARM_SHA256 = "7c88c10dd432f3b39ccdafc9ed81d0c21b8fd634502574da76d1bfa4fe2c76d0"


class WorkersPillowDependencyLockTests(unittest.TestCase):
    def test_pillow_manifest_and_lock_are_aligned(self):
        self.assertRegex(MANIFEST, r"(?im)^Pillow==13\\.0\\.0$")
        self.assertRegex(LOCK, r"(?im)^pillow==13\\.0\\.0[ \\\\]*$")
        self.assertEqual(len(re.findall(r"(?im)^pillow==", LOCK)), 1)

    def test_pillow_linux_wheel_hashes_verified(self):
        match = re.search(r"(?ms)^pillow==13\\.0\\.0.*?(?=^[a-zA-Z][^\\n]*==|\\Z)", LOCK)
        self.assertIsNotNone(match)
        hashes = set(re.findall(r"--hash=sha256:([0-9a-f]{64})", match.group()))
        self.assertEqual(hashes, {LINUX_X86_SHA256, LINUX_ARM_SHA256})

    def test_worker_image_uses_locked_hash_install(self):
        self.assertIn("COPY workers/requirements.lock ./requirements.lock", DOCKER)
        self.assertIn("pip install --no-cache-dir --require-hashes --only-binary=:all:", DOCKER)
        self.assertIn("python:3.11-slim", DOCKER)


if __name__ == "__main__":
    unittest.main()
