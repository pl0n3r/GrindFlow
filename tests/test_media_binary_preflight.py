"""Fail-closed, offline contract tests for ffmpeg/ffprobe discovery."""
from __future__ import annotations

import importlib.util
import io
import json
from contextlib import redirect_stdout
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/media-binary-preflight.py"


def load_module():
    spec = importlib.util.spec_from_file_location("gf345_media_binary", SCRIPT)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class MediaBinaryPreflightTests(unittest.TestCase):
    def test_available_binary_requires_successful_bounded_invocation(self) -> None:
        mod = load_module()
        with patch.object(mod.shutil, "which", return_value="/usr/bin/ffmpeg") as which, \
             patch.object(mod.subprocess, "run", return_value=subprocess.CompletedProcess(
                 ["/usr/bin/ffmpeg", "-version"], 0, b"ffmpeg version 7", b""
             )) as run:
            self.assertEqual("available", mod.probe("ffmpeg"))
            which.assert_called_once_with("ffmpeg")
            args, kwargs = run.call_args
            self.assertEqual(["/usr/bin/ffmpeg", "-version"], args[0])
            self.assertEqual(mod.PROBE_TIMEOUT_SECONDS, kwargs["timeout"])
            self.assertEqual(mod.subprocess.DEVNULL, kwargs["stdin"])
            self.assertEqual(mod.subprocess.PIPE, kwargs["stdout"])
            self.assertEqual(mod.subprocess.PIPE, kwargs["stderr"])
            self.assertIs(kwargs["shell"], False)
            self.assertIs(kwargs["check"], False)
        with patch.object(mod.shutil, "which", return_value="/usr/bin/ffprobe"), \
             patch.object(mod.subprocess, "run", return_value=subprocess.CompletedProcess(
                 ["/usr/bin/ffprobe", "-version"], 0, b"ffprobe version 7", b"")):
            self.assertEqual("available", mod.probe("ffprobe"))

    def test_missing_or_failed_binary_fails_closed_without_leaking_details(self) -> None:
        mod = load_module()
        with patch.object(mod.shutil, "which", return_value=None), \
             patch.object(mod.subprocess, "run") as run:
            self.assertEqual("unavailable", mod.probe("ffmpeg"))
            run.assert_not_called()
        with patch.object(mod.shutil, "which", return_value="/tmp/attacker"), \
             patch.object(mod.subprocess, "run") as run:
            self.assertEqual("unknown", mod.probe("ffmpeg"))
            run.assert_not_called()
        with patch.object(mod.shutil, "which", return_value="/usr/bin/ffmpeg"), \
             patch.object(mod.subprocess, "run") as run:
            for exception in (
                subprocess.TimeoutExpired(["ffmpeg", "-version"], 3),
                PermissionError("private/path"),
                OSError("TOKEN=secret"),
            ):
                with self.subTest(exception=type(exception).__name__):
                    run.side_effect = exception
                    self.assertEqual("unknown", mod.probe("ffmpeg"))
            run.side_effect = None
            for result, expected in (
                (subprocess.CompletedProcess([], 1, b"bad TOKEN=x", b"private"), "unavailable"),
                (subprocess.CompletedProcess([], 0, b"x" * 4097, b""), "unknown"),
                (subprocess.CompletedProcess([], 0, b"", b"secret" * 900), "unknown"),
                (subprocess.CompletedProcess([], 0, "text", b""), "unknown"),
                (subprocess.CompletedProcess([], None, b"", b""), "unknown"),
            ):
                with self.subTest(code=result.returncode, size=len(result.stdout)):
                    run.return_value = result
                    self.assertEqual(expected, mod.probe("ffmpeg"))
            self.assertEqual("unknown", mod.probe("foreign-program"))

    def test_cli_is_local_only_and_does_not_claim_hosting(self) -> None:
        mod = load_module()
        out = io.StringIO()
        with patch.object(mod.shutil, "which", side_effect=lambda name: "/usr/bin/" + name), \
             patch.object(mod.subprocess, "run", return_value=subprocess.CompletedProcess(
                 [], 0, b"SUPER-PRIVATE-OUTPUT", b"PASSWORD=secret")) as run, \
             redirect_stdout(out):
            self.assertEqual(0, mod.main())
        data = json.loads(out.getvalue())
        self.assertEqual(["ffmpeg", "ffprobe"], list(data["binaries"]))
        self.assertEqual({"ffmpeg": "available", "ffprobe": "available"}, data["binaries"])
        self.assertEqual("local", data["environment"])
        self.assertIs(data["target_verified"], False)
        self.assertNotIn("PRIVATE", out.getvalue())
        self.assertNotIn("PASSWORD", out.getvalue())
        self.assertNotIn("/usr/bin", out.getvalue())
        self.assertEqual(2, run.call_count)
        out = io.StringIO()
        with patch.object(mod.shutil, "which", return_value=None), redirect_stdout(out):
            self.assertEqual(2, mod.main())
        self.assertEqual({"ffmpeg": "unavailable", "ffprobe": "unavailable"},
                         json.loads(out.getvalue())["binaries"])


if __name__ == "__main__":
    unittest.main()
