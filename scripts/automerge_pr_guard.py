#!/usr/bin/env python3
"""Fail-closed exact-SHA auto-merge preflight. This script never writes to GitHub."""
from datetime import datetime, timezone
import json
import os
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPO = "pl0n3r/GrindFlow"
SHA = re.compile(r"[0-9a-f]{40}\Z")
MARKER = re.compile(r"<!--\s*factory-unattended-kill-switch\s+(\{[^\n]*\})\s*-->")
MARKER_START = re.compile(r"<!--\s*factory-unattended-kill-switch\b")
REQUIRED = ("Etiquetas", "validate", "SonarCloud Code Analysis", "CodeQL",
            "Factory policy / Validar decisiones y límite de revisión")
REQUIRED_APPS = {
    "Etiquetas": ("github-actions", 15368),
    "validate": ("github-actions", 15368),
    "SonarCloud Code Analysis": ("sonarqubecloud", 12526),
    "CodeQL": ("github-advanced-security", 57789),
    "Factory policy / Validar decisiones y límite de revisión": ("github-actions", 15368),
}



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
    if (type(issue) is not dict or issue.get("number") != 767
            or issue.get("url") != "https://api.github.com/repos/pl0n3r/Factory/issues/767"
            or issue.get("repository_url") != "https://api.github.com/repos/pl0n3r/Factory"
            or issue.get("state") != "closed"
            or type(issue.get("user")) is not dict
            or issue["user"].get("login") != "pl0n3r"
            or type(issue.get("body")) is not str):
        return False
    marks = MARKER.findall(issue["body"])
    # A second malformed marker must not be ignored just because one is valid.
    if len(marks) != 1 or len(MARKER_START.findall(issue["body"])) != 1:
        return False
    try:
        value = json.loads(marks[0], object_pairs_hook=unique)
    except (GuardError, ValueError, TypeError):
        return False
    return (type(value) is dict and set(value) == {"version", "state", "owner"}
            and type(value["version"]) is int and value["version"] == 1
            and value["state"] == "RUNNING" and value["owner"] == "pl0n3r")


def verify_coderabbit_status(rows):
    """Require an authenticated, completed CodeRabbit status for this SHA.

    The REST /statuses history exposes author identity, unlike some
    aggregate /status responses. A skipped state with conclusion success
    is never equivalent to a completed security review.
    """
    if type(rows) is not list or not 1 <= len(rows) <= 100:
        raise GuardError("coderabbit_status_history_missing")
    seen = set()
    previous = None
    latest = None
    for row in rows:
        if type(row) is not dict or type(row.get("id")) is not int or row["id"] <= 0:
            raise GuardError("coderabbit_status_history_invalid")
        if row["id"] in seen:
            raise GuardError("coderabbit_status_history_duplicate")
        seen.add(row["id"])
        created = row.get("created_at")
        if type(created) is not str or not created.endswith("Z"):
            raise GuardError("coderabbit_status_time_invalid")
        try:
            at = datetime.fromisoformat(created.replace("Z", "+00:00"))
        except ValueError as err:
            raise GuardError("coderabbit_status_time_invalid") from err
        if at.tzinfo is None or at.utcoffset() != timezone.utc.utcoffset(at):
            raise GuardError("coderabbit_status_time_invalid")
        # GitHub's status-history API presents newest statuses first.
        # A changing/contradictory order is not evidence of completion.
        if previous is not None and at > previous:
            raise GuardError("coderabbit_status_order_changed")
        previous = at
        if row.get("context") != "CodeRabbit":
            continue
        author = row.get("creator")
        if (type(author) is not dict
                or author.get("login") != "coderabbitai[bot]"
                or author.get("type") != "Bot"):
            raise GuardError("coderabbit_status_author_unknown")
        if latest is None:
            latest = row
    if latest is None:
        raise GuardError("coderabbit_status_missing")
    description = latest.get("description")
    if (latest.get("state") != "success" or type(description) is not str
            or description.strip().lower() not in {
                "review completed", "review complete", "full review finished."
            }):
        raise GuardError("coderabbit_review_not_completed")


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
        app = row.get("app")
        slug, app_id = REQUIRED_APPS[name]
        if (type(app) is not dict or app.get("slug") != slug
                or type(app.get("id")) is not int or app["id"] != app_id):
            raise GuardError("check_app_mismatch")
    verify_coderabbit_status(snapshot.get("status_history"))
    reviews = snapshot.get("reviews")
    if type(reviews) is not list or len(reviews) > 100:
        raise GuardError("incomplete_reviews")
    substantive = False
    # The reviews REST API lists submissions from oldest to newest.
    # COMMENTED is not a dismissal of an earlier outstanding veto.
    latest_decisive = {}
    for review in reviews:
        if type(review) is not dict or type(review.get("user")) is not dict:
            raise GuardError("invalid_review")
        state = review.get("state")
        if state not in ("COMMENTED", "APPROVED", "DISMISSED", "CHANGES_REQUESTED"):
            raise GuardError("review_unknown")
        user = review["user"]
        login = user.get("login")
        if type(login) is not str or not login.strip():
            raise GuardError("invalid_reviewer")
        if state in ("CHANGES_REQUESTED", "APPROVED", "DISMISSED"):
            latest_decisive[login] = state
        if (user.get("login") == "coderabbitai[bot]" and user.get("type") == "Bot"
                and review.get("commit_id") == expected):
            body = review.get("body")
            blocked_text = (
                "review skipped", "skipped review", "review was skipped",
                "skip review", "manual review required",
                "review not performed", "review was not completed",
                "could not review", "unable to review",
                "review pending", "review in progress", "analysis queued",
                "review cancelled", "review not complete",
                "rate limit", "rate-limit", "review disabled",
            )
            # A nominal COMMENTED or APPROVED cannot override the bot's
            # explicit statement that analysis was not actually completed.
            if type(body) is str and any(s in body.lower() for s in blocked_text):
                continue
            if state == "APPROVED" or (
                state == "COMMENTED" and type(body) is str and body.strip()
            ):
                substantive = True
    if "CHANGES_REQUESTED" in latest_decisive.values():
        raise GuardError("changes_requested")
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
        "status_history": get(token, root + f"/commits/{expected}/statuses?per_page=100"),
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
