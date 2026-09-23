#!/usr/bin/env python3
"""Reject official GitHub Actions majors that still run on pre-Node-24 runtimes."""

from __future__ import annotations

import argparse
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"

ACTION_MIN_MAJOR = {
    "actions/checkout": 5,
    "actions/setup-node": 5,
    "actions/cache": 5,
    "actions/upload-artifact": 6,
}

APPROVED_PINNED_SHA = {
    "actions/checkout": {
        "fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09",
    },
    "actions/upload-artifact": {
        "b7c566a772e6b6bfb58ed0dc250532a479d7789f",
    },
}

USES_PATTERN = re.compile(
    r"^\s*(?:-\s*)?uses:\s*(actions/(?:checkout|setup-node|cache|upload-artifact))@([^\s#]+)",
    flags=re.MULTILINE,
)


def reference_error(action: str, reference: str) -> str | None:
    major = re.fullmatch(r"v(\d+)", reference)
    if major:
        minimum = ACTION_MIN_MAJOR[action]
        if int(major.group(1)) < minimum:
            return f"{action}@{reference} must use v{minimum}+ for Node 24 runtime"
        return None

    if re.fullmatch(r"[0-9a-f]{40}", reference):
        if reference not in APPROVED_PINNED_SHA.get(action, set()):
            return f"{action}@{reference} is not an approved Node 24 pinned SHA"
        return None

    return f"{action}@{reference} must use an approved major tag or pinned SHA"


def workflow_errors(text: str, label: str) -> list[str]:
    errors: list[str] = []
    for action, reference in USES_PATTERN.findall(text):
        error = reference_error(action, reference)
        if error:
            errors.append(f"{label}: {error}")
    return errors


def repository_errors() -> list[str]:
    errors: list[str] = []
    for path in sorted(WORKFLOWS.glob("*.y*ml")):
        errors.extend(workflow_errors(path.read_text(encoding="utf-8"), str(path.relative_to(ROOT))))
    return errors


def self_test() -> None:
    valid = """
steps:
  - uses: actions/checkout@v5
  - uses: actions/setup-node@v5
  - uses: actions/cache@v5
  - uses: actions/upload-artifact@v6
  - uses: actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09 # v5
  - uses: actions/upload-artifact@b7c566a772e6b6bfb58ed0dc250532a479d7789f # v6
"""
    assert workflow_errors(valid, "valid.yml") == []

    invalid = """
steps:
  - uses: actions/checkout@v4
  - uses: actions/setup-node@v4
  - uses: actions/cache@v4
  - uses: actions/upload-artifact@v5
  - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262
"""
    errors = workflow_errors(invalid, "invalid.yml")
    assert len(errors) == 5
    assert all("Node 24" in error for error in errors[:4])
    assert "approved Node 24 pinned SHA" in errors[4]

    unknown = "steps:\n  - uses: actions/cache@main\n"
    assert "approved major tag or pinned SHA" in workflow_errors(unknown, "unknown.yml")[0]

    print("GitHub Actions Node 24 runtime contract: OK")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    if args.self_test:
        self_test()
        return

    errors = repository_errors()
    if errors:
        raise SystemExit("\n".join(errors))

    print("GitHub Actions official runtime contract: Node 24 compatible")


if __name__ == "__main__":
    main()
