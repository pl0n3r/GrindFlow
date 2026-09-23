#!/usr/bin/env python3
"""Verify terminal CodeRabbit status for one exact PR commit from local JSON.

Read-only, offline and fail-closed. Never print raw provider data.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import stat

SHA = re.compile(r"[a-f0-9]{40}\Z")
COMPLETED = re.compile(r"Review completed[.!]?\Z", re.IGNORECASE)
EVIDENCE_NAMES = ("status.json", "reviews.json", "threads.json")
MAX_EVIDENCE_BYTES = 1_048_576


def extract_records(source: object, name: str) -> object:
    if isinstance(source, list):
        return source
    if isinstance(source, dict):
        return source.get(name)
    return None


def status_failures(head: str, status: object) -> list[str]:
    if not isinstance(status, dict) or status.get("sha") != head:
        return ["status_sha_mismatch"]
    records = status.get("statuses")
    if not isinstance(records, list):
        return ["missing_statuses"]
    if any(not isinstance(record, dict) for record in records):
        return ["invalid_statuses"]
    # The combined-status API returns statuses newest first.
    rabbit = next(
        (record for record in records if isinstance(record, dict) and record.get("context") == "CodeRabbit"),
        None,
    )
    if rabbit is None:
        return ["missing_coderabbit_status"]
    description = rabbit.get("description")
    if rabbit.get("state") != "success":
        return ["coderabbit_not_completed"]
    if not isinstance(description, str) or COMPLETED.fullmatch(description) is None:
        return ["coderabbit_not_completed"]
    return []


def review_failures(head: str, reviews: object) -> list[str]:
    records = extract_records(reviews, "reviews")
    if not isinstance(records, list) or any(not isinstance(record, dict) for record in records):
        return ["missing_reviews"]
    if any(record.get("commit_id") == head and record.get("state") == "CHANGES_REQUESTED" for record in records):
        return ["changes_requested_on_head"]
    return []


def thread_failures(threads: object) -> list[str]:
    records = extract_records(threads, "review_threads")
    if not isinstance(records, list):
        return ["missing_review_threads"]
    if any(not isinstance(record, dict) or record.get("is_resolved") is not True for record in records):
        return ["unresolved_review_threads"]
    return []


def verify(head: str, statuses: object, reviews: object, threads: object) -> list[str]:
    """Return bounded diagnostic codes only, not arbitrary GitHub strings."""
    if SHA.fullmatch(head) is None:
        return ["invalid_head_sha"]
    return status_failures(head, statuses) + review_failures(head, reviews) + thread_failures(threads)


def read_evidence() -> tuple[object, object, object]:
    """Load bounded regular files with no symlink/traversal race.

    Resolve no provider-supplied names: each file is opened relative to a
    directory descriptor, with O_NOFOLLOW and O_NONBLOCK. fstat validates the
    opened object, not a path that could change between checking and reading.
    """
    if not all(hasattr(os, flag) for flag in ("O_DIRECTORY", "O_NOFOLLOW", "O_NONBLOCK")):
        raise ValueError("secure file opening unavailable")

    directory = os.open(".", os.O_RDONLY | os.O_DIRECTORY)
    try:
        values = []
        for name in EVIDENCE_NAMES:
            descriptor = os.open(
                name,
                os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK,
                dir_fd=directory,
            )
            with os.fdopen(descriptor, "rb") as source:
                metadata = os.fstat(source.fileno())
                if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > MAX_EVIDENCE_BYTES:
                    raise ValueError("invalid evidence file")
                data = source.read(MAX_EVIDENCE_BYTES + 1)
                if len(data) > MAX_EVIDENCE_BYTES:
                    raise ValueError("oversized evidence")
                values.append(json.loads(data.decode("utf-8")))
        return tuple(values)
    finally:
        os.close(directory)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--head", required=True)
    args = parser.parse_args()
    try:
        status, reviews, threads = read_evidence()
    except (OSError, ValueError, UnicodeError):
        print("CODERABBIT_GATE=invalid_evidence")
        return 1
    failures = verify(args.head, status, reviews, threads)
    if failures:
        print("CODERABBIT_GATE=blocked")
        for failure in failures:
            print("CODERABBIT_GATE_REASON=" + failure)
        return 1
    print("CODERABBIT_GATE=completed_for_exact_head")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
