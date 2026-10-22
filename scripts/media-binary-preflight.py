#!/usr/bin/env python3
"""Local, non-authoritative ffmpeg/ffprobe diagnostic; never probes hosting."""
from __future__ import annotations

import json
import os
import shutil
import subprocess

CONTRACT = "gf345-media-binary-local-preflight-v1"
BINARIES = ("ffmpeg", "ffprobe")
PROBE_TIMEOUT_SECONDS = 3
MAX_VERSION_OUTPUT_BYTES = 4096


def probe(binary: str) -> str:
    """Return available/unavailable/unknown, never disclose paths or output."""
    if binary not in BINARIES:
        return "unknown"
    try:
        resolved = shutil.which(binary)
    except (OSError, ValueError, TypeError):
        return "unknown"
    if resolved is None:
        return "unavailable"
    if (not isinstance(resolved, str) or not os.path.isabs(resolved)
            or os.path.basename(resolved).lower() not in (binary, binary + ".exe")
            or "\x00" in resolved):
        return "unknown"
    try:
        result = subprocess.run(
            [resolved, "-version"],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=PROBE_TIMEOUT_SECONDS,
            check=False,
            shell=False,
        )
    except (OSError, ValueError, subprocess.TimeoutExpired, subprocess.SubprocessError):
        return "unknown"
    if type(result.returncode) is not int:
        return "unknown"
    if not all(isinstance(value, bytes) for value in (result.stdout, result.stderr)):
        return "unknown"
    if (len(result.stdout) > MAX_VERSION_OUTPUT_BYTES
            or len(result.stderr) > MAX_VERSION_OUTPUT_BYTES):
        return "unknown"
    return "available" if result.returncode == 0 else "unavailable"


def main() -> int:
    # Binaries and identifiers are fixed constants. No command-line input,
    # network, file writes, or environment-derived target is trusted.
    statuses = {binary: probe(binary) for binary in BINARIES}
    print(json.dumps({
        "contract": CONTRACT,
        "environment": "local",
        "target_verified": False,
        "binaries": statuses,
    }, sort_keys=True))
    return 0 if all(status == "available" for status in statuses.values()) else 2


if __name__ == "__main__":
    raise SystemExit(main())
