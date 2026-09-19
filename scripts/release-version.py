#!/usr/bin/env python3
"""Validate one explicit human product-version bump per deploy-bound PR/push.

Product version and actual deployed source SHA are independent facts.
The initial adoption bootstraps 0.1.0; after that only patch +1 or an
explicit pre-1.0 minor milestone (next minor, patch 0) is accepted.
CI validates committed metadata but never edits the repository.
"""
from __future__ import annotations

import argparse
import datetime as dt
import re
import subprocess
import sys

VERSION = re.compile(r"'number'\s*=>\s*'(\d+)\.(\d+)\.(\d+)'")
DATE = re.compile(r"'released_at'\s*=>\s*'(\d{4}-\d{2}-\d{2})'")
SHA = re.compile(r"[0-9a-f]{40}\Z")
BOOTSTRAP = (0, 1, 0)


def parse_version(content: str) -> tuple[int, int, int]:
    versions = VERSION.findall(content)
    dates = DATE.findall(content)
    if len(versions) != 1 or len(dates) != 1:
        raise ValueError("exactly one version and release date are required")
    try:
        dt.date.fromisoformat(dates[0])
    except ValueError as error:
        raise ValueError("release date must be a real YYYY-MM-DD date") from error
    version = tuple(int(part) for part in versions[0])
    if version[0] >= 1:
        raise ValueError("GrindFlow 1.0 requires an explicit product milestone")
    return version


def transition(previous: tuple[int, int, int] | None, current: tuple[int, int, int]) -> None:
    if previous is None:
        if current != BOOTSTRAP:
            raise ValueError("initial GrindFlow release must be 0.1.0")
        return
    old_major, old_minor, old_patch = previous
    major, minor, patch = current
    if (major, minor, patch) in (
        (old_major, old_minor, old_patch + 1),
        (0, old_minor + 1, 0),
    ) and major == 0:
        return
    raise ValueError(f"invalid product-version transition: {previous} -> {current}")


def show(sha: str) -> str | None:
    result = subprocess.run(
        ["git", "show", f"{sha}:config/version.php"],
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode == 0:
        return result.stdout
    # A missing release config is accepted only for the bootstrap transition.
    exists = subprocess.run(
        ["git", "cat-file", "-e", f"{sha}:config/version.php"],
        capture_output=True,
        check=False,
    )
    if exists.returncode != 0:
        return None
    raise ValueError("cannot read release metadata from Git")


def self_test() -> None:
    sample = "<?php return ['number' => '0.1.0', 'released_at' => '2026-09-19'];"
    assert parse_version(sample) == BOOTSTRAP
    for before, after in (
        (None, BOOTSTRAP),
        ((0, 1, 0), (0, 1, 1)),
        ((0, 1, 9), (0, 2, 0)),
    ):
        transition(before, after)
    for before, after in (
        (None, (0, 1, 1)),
        ((0, 1, 0), (0, 1, 0)),
        ((0, 1, 0), (0, 1, 2)),
        ((0, 1, 5), (0, 1, 4)),
        ((0, 1, 5), (0, 3, 0)),
        ((0, 1, 9), (1, 0, 0)),
    ):
        try:
            transition(before, after)
        except ValueError:
            continue
        raise AssertionError(f"unexpectedly accepted {before} -> {after}")
    for bad in (
        sample.replace("2026-09-19", "2026-02-30"),
        sample.replace("0.1.0", "1.0.0"),
        sample + sample,
        sample.replace("'released_at'", "'other'"),
    ):
        try:
            parse_version(bad)
        except ValueError:
            continue
        raise AssertionError("invalid metadata was accepted")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--base")
    parser.add_argument("--head")
    args = parser.parse_args()
    try:
        if args.self_test:
            self_test()
            print("GrindFlow release-version contract passed.")
            return 0
        if not args.base or not args.head or not SHA.fullmatch(args.base) or not SHA.fullmatch(args.head):
            raise ValueError("--base and --head must be exact 40-character Git SHAs")
        before = show(args.base)
        after = show(args.head)
        if after is None:
            raise ValueError("release metadata missing at head")
        transition(parse_version(before) if before is not None else None, parse_version(after))
        print("GrindFlow release-version transition verified.")
        return 0
    except ValueError as error:
        print(f"RELEASE VERSION CHECK FAILED: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
