#!/usr/bin/env python3
"""Mirror SonarQube Cloud PR analysis details into a stable GitHub PR comment."""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

COMMENT_MARKER = "<!-- grindflow-sonar-details -->"
GITHUB_API = "https://api.github.com"
DEFAULT_SONAR_HOST = "https://sonarcloud.io"
MAX_COMMENT_LENGTH = 60000


class ApiError(RuntimeError):
    def __init__(self, source: str, status: int, message: str) -> None:
        super().__init__(f"{source} API HTTP {status}: {message}")
        self.source = source
        self.status = status


def request_json(
    url: str,
    *,
    token: str = "",
    method: str = "GET",
    payload: dict[str, Any] | None = None,
    source: str,
) -> Any:
    headers = {
        "Accept": "application/json",
        "User-Agent": "GrindFlow-Sonar-Reporter/1.0",
    }

    if source == "GitHub":
        headers["Accept"] = "application/vnd.github+json"
        headers["X-GitHub-Api-Version"] = "2026-03-10"

    if token:
        headers["Authorization"] = f"Bearer {token}"

    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"

    request = urllib.request.Request(
        url,
        data=data,
        headers=headers,
        method=method,
    )

    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            raw = response.read().decode("utf-8")
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as error:
        detail = error.read().decode("utf-8", errors="replace")[:1200]
        raise ApiError(source, error.code, detail) from error
    except urllib.error.URLError as error:
        raise RuntimeError(f"{source} API network error: {error}") from error


def github_json(
    path: str,
    *,
    token: str,
    method: str = "GET",
    payload: dict[str, Any] | None = None,
) -> Any:
    return request_json(
        f"{GITHUB_API}{path}",
        token=token,
        method=method,
        payload=payload,
        source="GitHub",
    )


def sonar_json(
    host: str,
    path: str,
    params: dict[str, Any],
    *,
    token: str,
) -> Any:
    query = urllib.parse.urlencode(
        {key: value for key, value in params.items() if value not in (None, "")}
    )
    return request_json(
        f"{host.rstrip('/')}{path}?{query}",
        token=token,
        source="Sonar",
    )


def event_payload() -> dict[str, Any]:
    event_path = os.environ.get("GITHUB_EVENT_PATH")
    if not event_path:
        raise RuntimeError("GITHUB_EVENT_PATH is not available.")

    with open(event_path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def find_pull_request(
    event: dict[str, Any],
    *,
    repository: str,
    github_token: str,
) -> int | None:
    pull_requests = event.get("check_run", {}).get("pull_requests") or []
    if pull_requests:
        return int(pull_requests[0]["number"])

    head_sha = event.get("check_run", {}).get("head_sha")
    if not head_sha:
        return None

    pulls = github_json(
        f"/repos/{repository}/commits/{head_sha}/pulls",
        token=github_token,
    )
    open_pulls = [pull for pull in pulls if pull.get("state") == "open"]
    if not open_pulls:
        return None

    return int(open_pulls[0]["number"])


def sonar_context(
    event: dict[str, Any],
    pull_request: int,
) -> tuple[str, str, str]:
    details_url = event.get("check_run", {}).get("details_url") or ""
    parsed = urllib.parse.urlparse(details_url)
    query = urllib.parse.parse_qs(parsed.query)

    host = (
        f"{parsed.scheme}://{parsed.netloc}"
        if parsed.scheme and parsed.netloc
        else DEFAULT_SONAR_HOST
    )
    project_key = (
        query.get("id", [None])[0]
        or os.environ.get("SONAR_PROJECT_KEY")
        or ""
    )
    sonar_pull_request = query.get("pullRequest", [str(pull_request)])[0]

    if not project_key:
        raise RuntimeError(
            "Unable to determine the Sonar project key from the check details URL."
        )

    return host, project_key, sonar_pull_request


def fetch_paged(
    host: str,
    path: str,
    params: dict[str, Any],
    result_key: str,
    *,
    token: str,
) -> list[dict[str, Any]]:
    page = 1
    page_size = 500
    results: list[dict[str, Any]] = []

    while True:
        response = sonar_json(
            host,
            path,
            {**params, "p": page, "ps": page_size},
            token=token,
        )
        items = response.get(result_key) or []
        results.extend(items)

        paging = response.get("paging") or {}
        total = int(paging.get("total", len(results)))
        if len(results) >= total or not items:
            return results

        page += 1


def escape_markdown(value: Any) -> str:
    return str(value if value is not None else "").replace("|", "\\|").replace("\n", " ")


def component_path(component: str, project_key: str) -> str:
    prefix = f"{project_key}:"
    return component[len(prefix) :] if component.startswith(prefix) else component


def issue_impacts(issue: dict[str, Any]) -> str:
    impacts = issue.get("impacts") or []
    if not impacts:
        return ""

    rendered = []
    for impact in impacts:
        software_quality = impact.get("softwareQuality") or "UNKNOWN"
        severity = impact.get("severity") or "UNKNOWN"
        rendered.append(f"{software_quality}:{severity}")

    return ", ".join(rendered)


def issue_url(host: str, project_key: str, pull_request: str, issue_key: str) -> str:
    query = urllib.parse.urlencode(
        {
            "id": project_key,
            "pullRequest": pull_request,
            "issues": issue_key,
            "open": issue_key,
        }
    )
    return f"{host}/project/issues?{query}"


def hotspot_url(host: str, project_key: str, pull_request: str, hotspot_key: str) -> str:
    query = urllib.parse.urlencode(
        {
            "id": project_key,
            "pullRequest": pull_request,
            "hotspots": hotspot_key,
        }
    )
    return f"{host}/project/security_hotspots?{query}"


def dashboard_url(host: str, project_key: str, pull_request: str) -> str:
    return (
        f"{host}/dashboard?"
        + urllib.parse.urlencode({"id": project_key, "pullRequest": pull_request})
    )


def render_quality_gate(status: dict[str, Any]) -> list[str]:
    project_status = status.get("projectStatus") or {}
    gate = project_status.get("status") or "UNKNOWN"
    icon = "✅" if gate == "OK" else "❌" if gate == "ERROR" else "⚪"

    lines = [f"**Quality Gate:** {icon} `{gate}`"]

    conditions = project_status.get("conditions") or []
    if not conditions:
        return lines

    lines.extend(
        [
            "",
            "<details>",
            "<summary><strong>Quality Gate conditions</strong></summary>",
            "",
            "| Metric | Status | Actual | Threshold |",
            "| --- | --- | ---: | ---: |",
        ]
    )

    for condition in conditions:
        metric = escape_markdown(condition.get("metricKey", "unknown"))
        condition_status = escape_markdown(condition.get("status", "UNKNOWN"))
        actual = escape_markdown(condition.get("actualValue", ""))
        threshold = escape_markdown(condition.get("errorThreshold", ""))
        lines.append(f"| `{metric}` | {condition_status} | {actual} | {threshold} |")

    lines.extend(["", "</details>"])
    return lines


def render_issues(
    issues: list[dict[str, Any]],
    *,
    host: str,
    project_key: str,
    pull_request: str,
) -> list[str]:
    lines = [f"### Issues ({len(issues)})"]

    if not issues:
        lines.append("✅ No Sonar issues reported for this pull request.")
        return lines

    lines.extend(["", "<details open>", "<summary><strong>Show all issues</strong></summary>", ""])

    for index, issue in enumerate(issues, start=1):
        path = component_path(str(issue.get("component", "")), project_key)
        line = issue.get("line") or (issue.get("textRange") or {}).get("startLine")
        location = f"`{escape_markdown(path)}"
        if line:
            location += f":{line}"
        location += "`"

        severity = issue.get("severity") or "UNKNOWN"
        issue_type = issue.get("type") or "ISSUE"
        status = issue.get("status") or issue.get("issueStatus") or "UNKNOWN"
        rule = issue.get("rule") or "unknown"
        message = escape_markdown(issue.get("message", ""))
        clean_code = issue.get("cleanCodeAttribute") or ""
        impacts = issue_impacts(issue)
        key = issue.get("key") or ""

        metadata = [f"rule `{escape_markdown(rule)}`", f"status `{escape_markdown(status)}`"]
        if clean_code:
            metadata.append(f"attribute `{escape_markdown(clean_code)}`")
        if impacts:
            metadata.append(f"impacts `{escape_markdown(impacts)}`")

        link = issue_url(host, project_key, pull_request, str(key))

        lines.extend(
            [
                f"{index}. **{escape_markdown(severity)} · {escape_markdown(issue_type)}** {location}",
                f"   - {message}",
                f"   - {' · '.join(metadata)}",
                f"   - [Open this issue in SonarQube Cloud]({link})",
            ]
        )

    lines.extend(["", "</details>"])
    return lines


def render_hotspots(
    hotspots: list[dict[str, Any]],
    *,
    host: str,
    project_key: str,
    pull_request: str,
) -> list[str]:
    lines = [f"### Security Hotspots ({len(hotspots)})"]

    if not hotspots:
        lines.append("✅ No Security Hotspots reported for this pull request.")
        return lines

    lines.extend(["", "<details open>", "<summary><strong>Show all hotspots</strong></summary>", ""])

    for index, hotspot in enumerate(hotspots, start=1):
        path = component_path(str(hotspot.get("component", "")), project_key)
        line = hotspot.get("line")
        location = f"`{escape_markdown(path)}"
        if line:
            location += f":{line}"
        location += "`"

        key = hotspot.get("key") or ""
        message = escape_markdown(hotspot.get("message", ""))
        category = hotspot.get("securityCategory") or "UNKNOWN"
        probability = hotspot.get("vulnerabilityProbability") or "UNKNOWN"
        status = hotspot.get("status") or "UNKNOWN"
        link = hotspot_url(host, project_key, pull_request, str(key))

        lines.extend(
            [
                f"{index}. **{escape_markdown(category)} · {escape_markdown(probability)}** {location}",
                f"   - {message}",
                f"   - status `{escape_markdown(status)}`",
                f"   - [Open this hotspot in SonarQube Cloud]({link})",
            ]
        )

    lines.extend(["", "</details>"])
    return lines


def fallback_check_summary(event: dict[str, Any]) -> str:
    summary = event.get("check_run", {}).get("output", {}).get("summary") or ""
    return summary.strip()


def render_comment(
    *,
    event: dict[str, Any],
    pull_request: int,
    project_key: str,
    sonar_pull_request: str,
    host: str,
    quality_gate: dict[str, Any] | None,
    issues: list[dict[str, Any]] | None,
    hotspots: list[dict[str, Any]] | None,
    api_error: str | None,
) -> str:
    check_run = event.get("check_run", {})
    conclusion = check_run.get("conclusion") or "unknown"
    check_icon = "✅" if conclusion == "success" else "❌" if conclusion == "failure" else "⚪"

    lines = [
        COMMENT_MARKER,
        "## 🔎 SonarQube Cloud · Full PR details",
        "",
        f"**PR:** #{pull_request} · **Sonar check:** {check_icon} `{conclusion}`",
        f"**Project:** `{project_key}`",
        f"**Dashboard:** [Open full Sonar analysis]({dashboard_url(host, project_key, sonar_pull_request)})",
        "",
    ]

    if quality_gate is not None:
        lines.extend(render_quality_gate(quality_gate))
        lines.append("")

    if api_error:
        lines.extend(
            [
                "### ⚠️ Sonar API details unavailable",
                "",
                api_error,
                "",
                "The native Sonar check summary is mirrored below so the PR still keeps the available context.",
                "",
            ]
        )
        native_summary = fallback_check_summary(event)
        if native_summary:
            lines.extend(["<details open>", "<summary><strong>Native Sonar check summary</strong></summary>", "", native_summary, "", "</details>"])
    else:
        lines.extend(
            render_issues(
                issues or [],
                host=host,
                project_key=project_key,
                pull_request=sonar_pull_request,
            )
        )
        lines.append("")
        lines.extend(
            render_hotspots(
                hotspots or [],
                host=host,
                project_key=project_key,
                pull_request=sonar_pull_request,
            )
        )

    lines.extend(
        [
            "",
            "---",
            "_This comment is maintained automatically by GrindFlow CI after the SonarQube Cloud check completes. It mirrors Sonar details into GitHub so agents and reviewers do not depend on GitHub check annotations alone._",
        ]
    )

    body = "\n".join(lines)
    if len(body) > MAX_COMMENT_LENGTH:
        dashboard = dashboard_url(host, project_key, sonar_pull_request)
        body = (
            body[: MAX_COMMENT_LENGTH - 700]
            + "\n\n> ⚠️ GitHub's comment-size limit truncated this report. "
            + f"[Open the complete analysis in SonarQube Cloud]({dashboard})."
            + "\n"
        )

    return body


def existing_comment(
    repository: str,
    pull_request: int,
    *,
    github_token: str,
) -> int | None:
    page = 1
    while page <= 10:
        comments = github_json(
            f"/repos/{repository}/issues/{pull_request}/comments?per_page=100&page={page}",
            token=github_token,
        )
        if not comments:
            return None

        for comment in comments:
            if COMMENT_MARKER in (comment.get("body") or ""):
                return int(comment["id"])

        if len(comments) < 100:
            return None

        page += 1

    return None


def publish_comment(
    repository: str,
    pull_request: int,
    body: str,
    *,
    github_token: str,
) -> None:
    comment_id = existing_comment(
        repository,
        pull_request,
        github_token=github_token,
    )

    if comment_id is None:
        github_json(
            f"/repos/{repository}/issues/{pull_request}/comments",
            token=github_token,
            method="POST",
            payload={"body": body},
        )
        print(f"Created Sonar details comment on PR #{pull_request}.")
        return

    github_json(
        f"/repos/{repository}/issues/comments/{comment_id}",
        token=github_token,
        method="PATCH",
        payload={"body": body},
    )
    print(f"Updated Sonar details comment on PR #{pull_request}.")


def main() -> int:
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    github_token = os.environ.get("GITHUB_TOKEN", "")
    sonar_token = os.environ.get("SONAR_TOKEN", "")

    if not repository or not github_token:
        raise RuntimeError("GITHUB_REPOSITORY and GITHUB_TOKEN are required.")

    event = event_payload()
    pull_request = find_pull_request(
        event,
        repository=repository,
        github_token=github_token,
    )

    if pull_request is None:
        print("Sonar check is not associated with an open pull request; nothing to comment.")
        return 0

    host, project_key, sonar_pull_request = sonar_context(event, pull_request)

    quality_gate: dict[str, Any] | None = None
    issues: list[dict[str, Any]] | None = None
    hotspots: list[dict[str, Any]] | None = None
    api_error: str | None = None

    try:
        quality_gate = sonar_json(
            host,
            "/api/qualitygates/project_status",
            {
                "projectKey": project_key,
                "pullRequest": sonar_pull_request,
            },
            token=sonar_token,
        )

        issues = fetch_paged(
            host,
            "/api/issues/search",
            {
                "componentKeys": project_key,
                "pullRequest": sonar_pull_request,
                "issueStatuses": "OPEN,CONFIRMED,ACCEPTED",
            },
            "issues",
            token=sonar_token,
        )

        hotspots = fetch_paged(
            host,
            "/api/hotspots/search",
            {
                "projectKey": project_key,
                "pullRequest": sonar_pull_request,
            },
            "hotspots",
            token=sonar_token,
        )
    except ApiError as error:
        if error.status in (401, 403):
            api_error = (
                f"SonarQube Cloud returned HTTP {error.status}. "
                "If this project requires API authentication, add a repository secret named "
                "`SONAR_TOKEN` containing a SonarQube Cloud token with read access to this project."
            )
        else:
            api_error = f"SonarQube Cloud API request failed: `{escape_markdown(error)}`."
    except RuntimeError as error:
        api_error = f"SonarQube Cloud API request failed: `{escape_markdown(error)}`."

    comment = render_comment(
        event=event,
        pull_request=pull_request,
        project_key=project_key,
        sonar_pull_request=sonar_pull_request,
        host=host,
        quality_gate=quality_gate,
        issues=issues,
        hotspots=hotspots,
        api_error=api_error,
    )

    publish_comment(
        repository,
        pull_request,
        comment,
        github_token=github_token,
    )

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"sonar-pr-comment failed: {error}", file=sys.stderr)
        raise
