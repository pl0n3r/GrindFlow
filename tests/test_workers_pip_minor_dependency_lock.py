"""Exact wheel-hash and transitive dependency guard for Python 3.11 workers."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = (ROOT / "workers/requirements.txt").read_text(encoding="utf-8")
LOCK = (ROOT / "workers/requirements.lock").read_text(encoding="utf-8")
DOCKER = (ROOT / "workers/Dockerfile").read_text(encoding="utf-8")

# Official PyPI wheel SHA-256 values, not hashes of sdists.
EXPECTED = {
    "psycopg[binary]": ("3.3.8", {"ca50af6072ba9ea6866f33021975230c2555d7bfdb9d8f94ca3af5c3d1bf2167"}),
    "psycopg-binary": ("3.3.8", {
        "39bc3b42e6486ae8ace820df93b00dce4858338e8a428d14d8e71130e852bab1",  # cp311 manylinux x86-64
        "4bedd0fbbbebe0c0f66b34c3f6be972ce38357ce3451510e1dcf5025f7011dba",  # cp311 manylinux ARM64
    }),
    "boto3": ("1.43.126", {"dd701a973b02a73930e546a6a15d13edbfbedc3180bb3d55b0a88678c284ccd6"}),
    "botocore": ("1.43.126", {"2f2239a47025a2fa173cd145ab8803ac02b76114864bf5b66475ff1cb88e6b0a"}),
    "s3transfer": ("0.19.2", {"d8168eccca828cbb2cd573675333f3bddd254313a9c42494b84c76b539e8ba25"}),
}
PILLOW_HASHES = {
    "7c88c10dd432f3b39ccdafc9ed81d0c21b8fd634502574da76d1bfa4fe2c76d0",
    "a625df9a2a5597fa5e5266e01aefc82604bb85d291e3547981e863560d75da6c",
}


class WorkersPipMinorDependencyLockTests(unittest.TestCase):
    def _pins(self, text: str) -> dict[str, str]:
        pins: dict[str, str] = {}
        for raw in text.splitlines():
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            if not line[0].isalpha() or "==" not in line:
                continue
            name, version = line.rstrip("\\").strip().split("==", 1)
            name = name.lower()
            self.assertNotIn(name, pins, f"Duplicate pin: {name}")
            pins[name] = version
        return pins

    def _lock(self) -> dict[str, tuple[str, set[str]]]:
        rows: dict[str, tuple[str, set[str]]] = {}
        current: str | None = None
        for raw in LOCK.splitlines():
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            if raw[0].isalpha():
                self.assertTrue(line.endswith("\\"), f"Missing continuation: {line}")
                name, version = line.rstrip("\\").strip().split("==", 1)
                current = name.lower()
                self.assertNotIn(current, rows, f"Duplicate lock pin: {current}")
                rows[current] = (version, set())
            else:
                self.assertIsNotNone(current, "Hash without package")
                self.assertTrue(line.startswith("--hash=sha256:"), f"Unexpected lock option: {line}")
                digest = line.split("--hash=sha256:", 1)[1].rstrip("\\").strip()
                self.assertRegex(digest, r"^[0-9a-f]{64}$")
                rows[current][1].add(digest)
        self.assertTrue(rows)
        for package, (_, digests) in rows.items():
            self.assertTrue(digests, f"Missing wheel hash for {package}")
        return rows

    def test_manifest_and_locked_direct_versions(self):
        manifest = self._pins(MANIFEST)
        locked = self._lock()
        self.assertEqual(manifest["psycopg[binary]"], "3.3.8")
        self.assertEqual(manifest["boto3"], "1.43.126")
        self.assertEqual(manifest["pillow"], "13.0.0")
        for package in ("psycopg[binary]", "boto3", "pillow"):
            self.assertEqual(locked[package][0], manifest[package], package)

    def test_transitive_hash_resolution(self):
        locked = self._lock()
        # boto3@1.43.126 requires botocore>=1.43.126,<1.44.0 and
        # s3transfer>=0.19.0,<0.20.0. s3transfer 0.17.x is incompatible.
        self.assertEqual(locked["botocore"][0], "1.43.126")
        self.assertEqual(locked["s3transfer"][0], "0.19.2")
        self.assertEqual(locked["psycopg-binary"][0], "3.3.8")
        for name, expected in EXPECTED.items():
            self.assertEqual(locked[name][0], expected[0], name)
            self.assertEqual(locked[name][1], expected[1], name)
        # Retain hashes of unmodified packages rather than dropping transitive pins.
        for name in ("jmespath", "python-dateutil", "urllib3", "six", "typing-extensions"):
            self.assertIn(name, locked)
            self.assertTrue(locked[name][1])

    def test_worker_image_and_pillow_untouched(self):
        locked = self._lock()
        self.assertEqual(locked["pillow"], ("13.0.0", PILLOW_HASHES))
        active = "\n".join(
            line for line in DOCKER.splitlines()
            if line.strip() and not line.lstrip().startswith("#")
        )
        self.assertRegex(active, r"(?m)^COPY workers/requirements\.lock \./requirements\.lock$")
        self.assertRegex(
            active,
            r"(?m)^RUN pip install --no-cache-dir --require-hashes --only-binary=:all: -r requirements\.lock$",
        )
        self.assertIn("python:3.11-slim", DOCKER)


if __name__ == "__main__":
    unittest.main()
