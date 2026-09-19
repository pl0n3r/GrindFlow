#!/usr/bin/env python3
"""Contract tests for the read-only Admin System readiness allowlist."""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

PARSER = Path(__file__).with_name("production-runtime-readiness.py")
MODULES = {
    "vault": "ready",
    "scheduling": "migration-required",
    "distribution": "ready",
    "traffic": "ready",
    "finance": "unknown",
}
TOOLS = {"ffmpeg": "disabled", "ffprobe": "binary-missing"}
SECRET = "secret-html-cookie-or-csrf-must-not-leak"


def page() -> str:
    module_tags = "".join(
        f'<article data-module-readiness="{name}:{state}"></article>'
        for name, state in MODULES.items()
    )
    tool_tags = "".join(
        f'<article data-media-tool="{name}:{state}"></article>'
        for name, state in TOOLS.items()
    )
    return f'<html><body>{module_tags}{tool_tags}</body></html>'


def check(label: str, html: str, *, expected: int) -> None:
    result = subprocess.run(
        [sys.executable, str(PARSER)],
        input=html,
        capture_output=True,
        text=True,
        timeout=10,
        check=False,
    )
    if result.returncode != expected:
        raise AssertionError(
            f"{label}: exit {result.returncode}, expected {expected}: {result.stderr}"
        )
    output = result.stdout
    if SECRET in output or SECRET in result.stderr:
        raise AssertionError(f"{label}: untrusted HTML leaked")
    if expected == 0:
        wanted = {
            *(f"MODULE_SCHEMA_{name.upper()}={state}" for name, state in MODULES.items()),
            *(f"MEDIA_TOOL_{name.upper()}={state}" for name, state in TOOLS.items()),
        }
        if set(output.splitlines()) != wanted:
            raise AssertionError(f"{label}: partial or incorrect allowlisted output")
    elif output:
        raise AssertionError(f"{label}: invalid inventory emitted partial output")
    print(f"PASS runtime readiness contract: {label}")


def main() -> None:
    valid = page()
    check("valid seven statuses", valid, expected=0)
    check("invalid module state", valid.replace("vault:ready", "vault:other"), expected=2)
    check("invalid tool state", valid.replace("ffmpeg:disabled", "ffmpeg:other"), expected=2)
    check("missing module", valid.replace('data-module-readiness="vault:ready"', "data-ignored"), expected=2)
    check("duplicate module", valid.replace("</body>", '<article data-module-readiness="vault:ready"></article></body>'), expected=2)
    check("duplicate attributes on one element", valid.replace('data-module-readiness="vault:ready"', 'data-module-readiness="vault:ready" data-module-readiness="vault:ready"'), expected=2)
    check("duplicate tool", valid.replace("</body>", '<article data-media-tool="ffmpeg:disabled"></article></body>'), expected=2)
    check("forged status", valid.replace("traffic:ready", "traffic:ready;"+SECRET), expected=2)
    check("extra status", valid.replace("</body>", '<article data-media-tool="unknown:disabled"></article></body>'), expected=2)
    check("oversized HTML", valid + ("x" * 1_000_001) + SECRET, expected=2)
    check("no raw HTML", valid.replace("</body>", f"<input value='{SECRET}'></body>"), expected=0)


if __name__ == "__main__":
    main()
