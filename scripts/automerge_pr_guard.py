#!/usr/bin/env python3
"""Fail-closed exact-SHA auto-merge preflight. This script never writes to GitHub."""
import json
import os
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPO = "pl0n3r/GrindFlow"
SHA = re.compile(r"[0-9a-f]{40}\Z")
MARKER = re.compile(r"<!--\s*factory-unattended-kill-switch\s+(\{[^\n]*\})\s*-->")
REQUIRED = ("Etiquetas", "validate", "SonarCloud Code Analysis", "CodeQL",
            "Factory policy / Validar decisiones y límite de revisión")


class GuardError(Exception):
    pass


def unique(pairs):
    data = {}
    for key, value in pairs:
        if key in data:
            raise GuardError("duplicate_json_key")
        data[key] = value
    return data


def switch_running(issue):
    if (type(issue) is not dict or issue.get("state") != "closed"
            or type(issue.get("user")) is not dict
            or issue["user"].get("login") != "pl0n3r"
            or type(issue.get("body")) is not str):
        return False
    marks = MARKER.findall(issue["body"])
    if len(marks) != 1:
        return False
    try:
        value = json.loads(marks[0], object_pairs_hook=unique)
    except (GuardError, ValueError, TypeError):
        return False
    return (type(value) is dict and set(value) == {"version", "state", "owner"}
            and type(value["version"]) is int and value["version"] == 1
            and value["state"] == "RUNNING" and value["owner"] == "pl0n3r")


def verify(snapshot, expected):
    if type(expected) is not str or not SHA.fullmatch(expected):
        raise GuardError("head_unknown")
    if type(snapshot) is not dict or not switch_running(snapshot.get("switch")):
        raise GuardError("switch_unknown")
    pr = snapshot.get("pr")
    if type(pr) is not dict:
        raise GuardError("pr_unknown")
    head, base = pr.get("head"), pr.get("base")
    if (pr.get("state") != "open" or pr.get("draft") is not False
            or pr.get("mergeable") is not True
            or type(head) is not dict or type(base) is not dict
            or type(head.get("repo")) is not dict
            or type(head["repo"].get("owner")) is not dict
            or head["repo"]["owner"].get("login") != "pl0n3r"
            or head["repo"].get("name") != "GrindFlow"
            or head["repo"].get("fork") is not False
            or head.get("sha") != expected or base.get("ref") != "main"
            or type(base.get("sha")) is not str or SHA.fullmatch(base["sha"]) is None
            or base["sha"] != snapshot.get("main_sha")):
        raise GuardError("pull_identity_or_base_unknown")
    checks = snapshot.get("checks")
    if (type(checks) is not dict or type(checks.get("total_count")) is not int
            or type(checks.get("check_runs")) is not list
            or checks["total_count"] != len(checks["check_runs"])
            or checks["total_count"] > 100):
        raise GuardError("incomplete_checks")
    seen = {}
    for check in checks["check_runs"]:
        if (type(check) is not dict or type(check.get("name")) is not str
                or check.get("head_sha") != expected or check["name"] in seen):
            raise GuardError("ambiguous_checks")
        seen[check["name"]] = check
    for name in REQUIRED:
        row = seen.get(name)
        if not row or row.get("status") != "completed" or row.get("conclusion") != "success":
            raise GuardError("missing_or_failed_policy")
    reviews = snapshot.get("reviews")
    if type(reviews) is not list or len(reviews) > 100:
        raise GuardError("incomplete_reviews")
    substantive = False
    for review in reviews:
        if type(review) is not dict or type(review.get("user")) is not dict:
            raise GuardError("invalid_review")
        state = review.get("state")
        if state not in ("COMMENTED", "APPROVED", "DISMISSED", "CHANGES_REQUESTED"):
            raise GuardError("review_unknown")
        if state == "CHANGES_REQUESTED":
            raise GuardError("changes_requested")
        user = review["user"]
        if (user.get("login") == "coderabbitai[bot]" and user.get("type") == "Bot"
                and review.get("commit_id") == expected):
            body = review.get("body")
            skipped = ("review skipped", "skip review", "manual review required",
                       "rate limit", "rate-limit", "review disabled")
            if state == "APPROVED" or (state == "COMMENTED" and type(body) is str
                    and body.strip() and not any(s in body.lower() for s in skipped)):
                substantive = True
    if not substantive:
        raise GuardError("coderabbit_review_missing")


def get(token, path):
    request = Request("https://api.github.com" + path, headers={
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "grindflow-automerge-readonly",
        "Authorization": "Bearer " + token})
    try:
        with urlopen(request, timeout=12) as response:
            body = response.read(262145)
            if len(body) > 262144 or 'rel="next"' in response.headers.get("Link", ""):
                raise GuardError("incomplete_page")
    except (HTTPError, URLError, TimeoutError, OSError) as err:
        raise GuardError("github_transport_unknown") from err
    try:
        return json.loads(body.decode("utf-8"), object_pairs_hook=unique)
    except (UnicodeError, ValueError, TypeError) as err:
        raise GuardError("invalid_response") from err


def read_snapshot(token, root, number, expected):
    """Fetch independent live policy, reviewer, base and switch evidence."""
    snapshot = {
        "pr": get(token, root + f"/pulls/{number}"),
        "switch": get(token, "/repos/pl0n3r/Factory/issues/767"),
        "checks": get(token, root + f"/commits/{expected}/check-runs?per_page=100"),
        "reviews": get(token, root + f"/pulls/{number}/reviews?per_page=100"),
    }
    base = get(token, root + "/branches/main")
    snapshot["main_sha"] = base.get("commit", {}).get("sha") if type(base) is dict else None
    return snapshot


def main():
    try:
        token = os.environ.get("GH_TOKEN")
        expected = os.environ.get("PR_SHA", "")
        number = int(os.environ.get("PR_NUMBER", "0"))
        if (os.environ.get("GITHUB_REPOSITORY") != REPO or not token
                or SHA.fullmatch(expected) is None or not 1 <= number <= 1000000):
            raise GuardError("invalid_environment")
        root = "/repos/" + REPO
        # The first snapshot can become stale while we check it.
        verify(read_snapshot(token, root, number, expected), expected)
        # Re-read ALL security gates immediately before the merge step:
        # policy, review, branch base and the global kill switch, not just HEAD.
        verify(read_snapshot(token, root, number, expected), expected)
    except (GuardError, ValueError, TypeError, KeyError) as err:
        reason = str(err) if type(err) is GuardError else "invalid_input"
        print("AUTOMERGE_DENIED: " + reason, file=sys.stderr)
        return 1
    print("AUTOMERGE_PREFLIGHT_OK " + expected[:12])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
