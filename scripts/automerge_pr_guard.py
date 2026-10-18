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
    # GitHub does not guarantee check-run ordering. Same-name jobs from distinct
    # workflow runs are legitimate retries, not automatically ambiguous.
    # Their immutable numeric IDs identify the creation order; never choose
    # the first *successful* result over a newer failed or pending attempt.
    seen = {}
    previous_id = None
    for check in sorted(
            checks["check_runs"],
            key=lambda row: row["id"] if type(row) is dict and type(row.get("id")) is int else -1,
            reverse=True):
        if (type(check) is not dict or type(check.get("name")) is not str
                or not check["name"] or check.get("head_sha") != expected):
            raise GuardError("ambiguous_checks")
        run_id = check.get("id")
        if (type(run_id) is not int or run_id <= 0
                or (previous_id is not None and run_id >= previous_id)):
            raise GuardError("check_id_or_order_unknown")
        previous_id = run_id
        app = check.get("app")
        if (type(app) is not dict or type(app.get("slug")) is not str
                or not app["slug"] or type(app.get("id")) is not int
                or app["id"] <= 0):
            raise GuardError("check_app_mismatch")
        if check["name"] in REQUIRED_APPS:
            slug, app_id = REQUIRED_APPS[check["name"]]
            if (app["slug"], app["id"]) != (slug, app_id):
                raise GuardError("check_app_mismatch")
        # Check-run start/completion times establish independently readable
        # temporal evidence, but an unrelated skipped job can complete before
        # its recorded start; only gate-winning required runs need chronology.
        for field in ("started_at", "completed_at"):
            value = check.get(field)
            if value is not None:
                if type(value) is not str or not value.endswith("Z"):
                    raise GuardError("check_time_invalid")
                try:
                    at = datetime.fromisoformat(value.replace("Z", "+00:00"))
                except ValueError as err:
                    raise GuardError("check_time_invalid") from err
                if at.tzinfo is None or at.utcoffset() != timezone.utc.utcoffset(at):
                    raise GuardError("check_time_invalid")
        name = check["name"]
        if name in seen:
            earlier_app = seen[name]["app"]
            if (app["slug"], app["id"]) != (earlier_app["slug"], earlier_app["id"]):
                raise GuardError("ambiguous_check_emitter")
        else:
            seen[name] = check
    for name in REQUIRED:
        row = seen.get(name)
        if not row or row.get("status") != "completed" or row.get("conclusion") != "success":
            raise GuardError("missing_or_failed_policy")
        if row.get("started_at") is None or row.get("completed_at") is None:
            raise GuardError("check_time_missing")
        start = datetime.fromisoformat(row["started_at"].replace("Z", "+00:00"))
        end = datetime.fromisoformat(row["completed_at"].replace("Z", "+00:00"))
        if end < start:
            raise GuardError("check_time_reversed")
    verify_coderabbit_status(snapshot.get("status_history"))
    reviews = snapshot.get("reviews")
    if type(reviews) is not list or len(reviews) > 100:
        raise GuardError("incomplete_reviews")
    threads = snapshot.get("review_threads")
    if type(threads) is not list or len(threads) > 100:
        raise GuardError("incomplete_review_threads")
    for thread in threads:
        if (type(thread) is not dict or type(thread.get("isResolved")) is not bool
                or type(thread.get("author_login")) is not str or not thread["author_login"]):
            raise GuardError("invalid_review_thread")
        if not thread["isResolved"] and thread["author_login"] in ("coderabbitai", "coderabbitai[bot]"):
            raise GuardError("coderabbit_unresolved_thread")

    latest_decisive = {}
    latest_bot = None
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
        if login == "coderabbitai[bot]" and user.get("type") == "Bot" and review.get("commit_id") == expected:
            latest_bot = review
    if "CHANGES_REQUESTED" in latest_decisive.values():
        raise GuardError("changes_requested")
    if latest_bot is None:
        raise GuardError("coderabbit_review_missing")
    body = latest_bot.get("body")
    blocked_text = (
        "review skipped", "skipped review", "review was skipped",
        "skip review", "manual review required",
        "review not performed", "review was not completed",
        "could not review", "unable to review",
        "review pending", "review in progress", "analysis queued",
        "review cancelled", "review not complete",
        "rate limit", "rate-limit", "review disabled",
        "incremental reviews are disabled",
    )
    if type(body) is str:
        if any(s in body.lower() for s in blocked_text):
            raise GuardError("coderabbit_review_incomplete")
        # CodeRabbit publishes e.g. "**Actionable comments posted: 2**".
        # Evaluate every occurrence, including Markdown/blockquote variants;
        # an ambiguous count is NOT evidence that no findings remain.
        labels = re.finditer(r"(?i)\bactionable\s+comments\s+posted\s*:", body)
        for label in labels:
            remainder = body[label.end():].splitlines()[0]
            count = re.match(r"[ \t*_`]*([0-9]+)(?![0-9A-Za-z_.])", remainder)
            if count is None:
                raise GuardError("coderabbit_findings_unknown")
            if int(count.group(1)) > 0:
                raise GuardError("coderabbit_actionable_findings")
    if not (latest_bot["state"] == "APPROVED" or (
            latest_bot["state"] == "COMMENTED" and type(body) is str and body.strip())):
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


def parse_review_threads(payload):
    """Require complete GraphQL review-thread inventory and authors."""
    if type(payload) is not dict or "errors" in payload:
        raise GuardError("review_threads_graphql_error")
    try:
        listing = payload["data"]["repository"]["pullRequest"]["reviewThreads"]
        nodes = listing["nodes"]
        more = listing["pageInfo"]["hasNextPage"]
    except (KeyError, TypeError, AttributeError) as err:
        raise GuardError("review_threads_shape_unknown") from err
    if type(nodes) is not list or len(nodes) > 100 or more is not False:
        raise GuardError("incomplete_review_threads")
    result = []
    for node in nodes:
        try:
            resolved = node["isResolved"]
            comments = node["comments"]["nodes"]
            author = comments[0]["author"]["login"]
        except (KeyError, TypeError, AttributeError, IndexError) as err:
            raise GuardError("review_thread_author_unknown") from err
        if (type(resolved) is not bool or type(comments) is not list
                or len(comments) != 1 or type(author) is not str or not author.strip()):
            raise GuardError("invalid_review_thread")
        result.append({"isResolved": resolved, "author_login": author})
    return result


def get_threads(token, number):
    """One authenticated GraphQL page; truncation/errors fail closed."""
    query = """query($number: Int!) {
      repository(owner: "pl0n3r", name: "GrindFlow") {
        pullRequest(number: $number) {
          reviewThreads(first: 100) {
            nodes { isResolved comments(first: 1) { nodes { author { login } } } }
            pageInfo { hasNextPage }
          }
        }
      }
    }"""
    request = Request("https://api.github.com/graphql",
                      data=json.dumps({"query": query, "variables": {"number": number}}).encode(),
                      headers={"Accept": "application/vnd.github+json",
                               "Content-Type": "application/json",
                               "Authorization": "Bearer " + token,
                               "X-GitHub-Api-Version": "2022-11-28",
                               "User-Agent": "grindflow-automerge-review-threads"})
    try:
        with urlopen(request, timeout=12) as response:
            body = response.read(262145)
            if len(body) > 262144:
                raise GuardError("review_threads_too_large")
    except (HTTPError, URLError, TimeoutError, OSError) as err:
        raise GuardError("review_threads_transport_unknown") from err
    try:
        payload = json.loads(body.decode("utf-8"), object_pairs_hook=unique)
    except (UnicodeError, ValueError, TypeError) as err:
        raise GuardError("review_threads_invalid_json") from err
    return parse_review_threads(payload)


def read_snapshot(token, root, number, expected):
    """Fetch independent live policy, reviewer, base and switch evidence."""
    snapshot = {
        "pr": get(token, root + f"/pulls/{number}"),
        "switch": get(token, "/repos/pl0n3r/Factory/issues/767"),
        "checks": get(token, root + f"/commits/{expected}/check-runs?per_page=100"),
        "reviews": get(token, root + f"/pulls/{number}/reviews?per_page=100"),
        "status_history": get(token, root + f"/commits/{expected}/statuses?per_page=100"),
        "review_threads": get_threads(token, number),
    }
    base = get(token, root + "/branches/main")
    main_sha = None
    if isinstance(base, dict):
        commit = base.get("commit")
        if isinstance(commit, dict):
            candidate = commit.get("sha")
            if isinstance(candidate, str) and SHA.fullmatch(candidate):
                main_sha = candidate
    snapshot["main_sha"] = main_sha
    return snapshot



def verify_exact_main_ci_runs(payload, expected):
    """A suppressed push is NOT a successful validation of the merged main SHA."""
    if type(expected) is not str or SHA.fullmatch(expected) is None:
        raise GuardError("exact_main_sha_invalid")
    if (type(payload) is not dict or type(payload.get("total_count")) is not int
            or type(payload.get("workflow_runs")) is not list
            or not 0 <= payload["total_count"] <= 100
            or payload["total_count"] != len(payload["workflow_runs"])):
        raise GuardError("exact_main_runs_incomplete")
    seen, matches = set(), []
    for run in payload["workflow_runs"]:
        if (type(run) is not dict or type(run.get("id")) is not int
                or run["id"] <= 0 or run["id"] in seen
                or type(run.get("head_sha")) is not str
                or SHA.fullmatch(run["head_sha"]) is None):
            raise GuardError("exact_main_run_invalid")
        seen.add(run["id"])
        if run["head_sha"] == expected and run.get("head_branch") == "main" and (
                run.get("name") == "GrindFlow CI"
                and run.get("event") in ("push", "workflow_dispatch")):
            matches.append(run)
    if not matches:
        return "missing"
    latest = max(matches, key=lambda run: run["id"])
    if latest.get("status") == "completed":
        if latest.get("conclusion") == "success":
            return "validated"
        raise GuardError("exact_main_ci_failed")
    if latest.get("status") in ("queued", "in_progress", "waiting", "requested", "pending"):
        return "pending"
    raise GuardError("exact_main_ci_status_unknown")


def audit_main():
    """Independent scheduled recovery if an Actions GITHUB_TOKEN merge suppressed push."""
    try:
        token = os.environ.get("GH_TOKEN", "")
        expected = os.environ.get("GITHUB_SHA", "")
        if (os.environ.get("GITHUB_EVENT_NAME") != "schedule"
                or os.environ.get("GITHUB_REPOSITORY") != REPO
                or not token or SHA.fullmatch(expected) is None):
            raise GuardError("exact_main_audit_environment_invalid")
        root = "/repos/" + REPO
        def current_main_sha():
            branch = get(token, root + "/branches/main")
            if (type(branch) is not dict or type(branch.get("commit")) is not dict
                    or type(branch["commit"].get("sha")) is not str
                    or SHA.fullmatch(branch["commit"]["sha"]) is None):
                raise GuardError("exact_main_branch_unknown")
            return branch["commit"]["sha"]

        if current_main_sha() != expected:
            raise GuardError("exact_main_changed_before_audit")
        runs = get(token, root + "/actions/workflows/grindflow-ci.yml/runs"
                   + "?branch=main&head_sha=" + expected + "&per_page=100")
        verdict = verify_exact_main_ci_runs(runs, expected)
        if current_main_sha() != expected:
            raise GuardError("exact_main_changed_during_audit")
        if verdict == "validated":
            print("EXACT_MAIN_CI_VALIDATED " + expected)
            return 0
        if verdict == "missing":
            print("EXACT_MAIN_DISPATCH_REQUIRED " + expected)
            return 2
        raise GuardError("exact_main_ci_pending")
    except (GuardError, ValueError, TypeError, KeyError) as err:
        reason = str(err) if type(err) is GuardError else "exact_main_audit_input_invalid"
        print("EXACT_MAIN_CI_NOT_VALIDATED: " + reason, file=sys.stderr)
        return 1


def main():
    if sys.argv[1:] == ["--audit-main"]:
        return audit_main()
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
