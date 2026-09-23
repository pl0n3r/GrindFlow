#!/usr/bin/env python3
"""Fail closed when a PR lacks CodeRabbit terminal review evidence for its exact head.

Accepts GitHub API response JSON captured separately; does not make network
requests or print raw review bodies, credentials, or provider diagnostics.
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

SHA = re.compile(r"[a-f0-9]{40}\Z")
COMPLETED = re.compile(r"^review completed(?:[.! ]|\Z)", re.IGNORECASE)


def verify(head: str, statuses: object, reviews: object, threads: object) -> list[str]:
    """Return only fixed diagnostic strings, never untrusted GitHub content."""
    errors: list[str] = []
    if SHA.fullmatch(head) is None:
        return ["invalid_head_sha"]

    if not isinstance(statuses, dict) or statuses.get("sha") != head:
        return ["status_sha_mismatch"]
    records = statuses.get("statuses")
    if not isinstance(records, list):
        return ["missing_statuses"]
    # GitHub /commits/{sha}/status returns statuses newest first. The latest
    # CodeRabbit context takes precedence over any older success.
    rabbit = next((x for x in records if isinstance(x, dict) and x.get("context") == "CodeRabbit"), None)
    if rabbit is None:
        errors.append("missing_coderabbit_status")
    elif rabbit.get("state") != "success" or not isinstance(rabbit.get("description"), str) or COMPLETED.match(rabbit["description"]) is None:
        errors.append("coderabbit_not_completed")

    review_records = reviews if isinstance(reviews, list) else reviews.get("reviews") if isinstance(reviews, dict) else None
    if not isinstance(review_records, list):
        errors.append("missing_reviews")
    elif any(
        isinstance(x, dict)
        and x.get("commit_id") == head
        and x.get("state") == "CHANGES_REQUESTED"
        for x in review_records
    ):
        errors.append("changes_requested_on_head")

    thread_records = threads if isinstance(threads, list) else threads.get("review_threads") if isinstance(threads, dict) else None
    if not isinstance(thread_records, list):
        errors.append("missing_review_threads")
    elif any(not isinstance(x, dict) or x.get("is_resolved") is not True for x in thread_records):
        errors.append("unresolved_review_threads")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--head", required=True)
    parser.add_argument("--statuses", type=Path, required=True)
    parser.add_argument("--reviews", type=Path, required=True)
    parser.add_argument("--threads", type=Path, required=True)
    args = parser.parse_args()
    try:
        status = json.loads(args.statuses.read_text(encoding="utf-8"))
        reviews = json.loads(args.reviews.read_text(encoding="utf-8"))
        threads = json.loads(args.threads.read_text(encoding="utf-8"))
    except (OSError, ValueError):
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
