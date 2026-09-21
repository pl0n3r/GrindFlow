#!/usr/bin/env python3
"""Retry CI dependency operations only when their failure is transient."""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
import time
from collections.abc import Sequence

MAX_ATTEMPTS = 5
MAX_DELAY_SECONDS = 30.0
TRANSIENT_PATTERNS = (
    re.compile(r"\b(?:timed?\s*out|econnreset|etimedout|econnrefused)\b", re.I),
    re.compile(r"\b(?:HTTP|status|response)(?: code)?[: /]+(?:429|502|503|504)\b", re.I),
    re.compile(r"connection reset|socket hang up", re.I),
)


def transient(returncode: int, output: str) -> bool:
    return returncode == 75 or "timeout" in output.lower() or any(
        pattern.search(output) for pattern in TRANSIENT_PATTERNS
    )


def validate(command: Sequence[str], attempts: int, delay: float) -> None:
    if not command:
        raise ValueError("command cannot be empty")
    if not 1 <= attempts <= MAX_ATTEMPTS:
        raise ValueError(f"attempts must be 1..{MAX_ATTEMPTS}")
    if not 0 <= delay <= MAX_DELAY_SECONDS:
        raise ValueError(f"base-delay must be 0..{MAX_DELAY_SECONDS}")


def run(command: Sequence[str], *, attempts: int = 3, delay: float = 2.0, label: str = "external operation") -> int:
    validate(command, attempts, delay)
    for attempt in range(1, attempts + 1):
        result = subprocess.run(list(command), check=False, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, errors="replace")
        output = result.stdout or ""
        if output:
            print(output, end="" if output.endswith("\n") else "\n")
        if result.returncode == 0:
            return 0
        if not transient(result.returncode, output):
            print(f"::notice title=CI without retry::{label} failed without a verified transient signal.")
            return result.returncode
        if attempt == attempts:
            return result.returncode
        pause = min(MAX_DELAY_SECONDS, delay * 2 ** (attempt - 1))
        print(f"::warning title=CI retry::{label} transient failure ({attempt}/{attempts}); retrying in {pause:g}s.")
        time.sleep(pause)
    return 1


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--attempts", type=int, default=3)
    parser.add_argument("--base-delay", type=float, default=2)
    parser.add_argument("--label", default="external operation")
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ["--"] else args.command
    try:
        return run(command, attempts=args.attempts, delay=args.base_delay, label=args.label)
    except ValueError as error:
        print(f"ci_retry: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
