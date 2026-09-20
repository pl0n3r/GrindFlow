#!/usr/bin/env python3
"""Small read-only GitHub Actions performance report. Never changes CI gates."""
from __future__ import annotations

import argparse
import datetime as dt
import json
import math
import os
import statistics
import sys
import urllib.error
import urllib.request

EVENTS = ("pull_request", "push")
WORKFLOW = "grindflow-ci.yml"


def seconds(run: dict) -> float | None:
    start = run.get("run_started_at")
    end = run.get("updated_at")
    if not start or not end:
        return None
    try:
        a = dt.datetime.fromisoformat(start.replace("Z", "+00:00"))
        b = dt.datetime.fromisoformat(end.replace("Z", "+00:00"))
        duration = (b - a).total_seconds()
    except (TypeError, ValueError):
        return None
    return duration if duration >= 0 else None


def percentile(values: list[float], percent: float) -> float:
    ordered = sorted(values)
    return ordered[max(0, math.ceil(len(ordered) * percent) - 1)]


def report(runs: list[dict]) -> str:
    lines = [
        "# GrindFlow CI · salud de ejecución",
        "",
        "Muestra: hasta 50 ejecuciones recientes; las canceladas y las manuales de matriz completa",
        "no se comparan con PR/push. Duraciones aproximadas según marcas de GitHub Actions.",
        "",
        "| Evento | Muestra | Éxitos | Fallos | Mediana | p90 |",
        "|---|---:|---:|---:|---:|---:|",
    ]
    alerts: list[str] = []
    for event in EVENTS:
        sample = [
            run for run in runs
            if run.get("event") == event
            and run.get("status") == "completed"
            and run.get("conclusion") in ("success", "failure")
            and seconds(run) is not None
        ][:20]
        values = [seconds(run) for run in sample]
        values = [float(value) for value in values if value is not None]
        if not values:
            lines.append(f"| {event} | 0 | 0 | 0 | sin datos | sin datos |")
            continue
        failures = sum(run["conclusion"] == "failure" for run in sample)
        lines.append(
            f"| {event} | {len(sample)} | {len(sample) - failures} | {failures} "
            f"| {statistics.median(values):.0f}s | {percentile(values, .9):.0f}s |"
        )
        if len(sample) >= 10:
            recent, baseline = sample[:5], sample[5:10]
            new_times = [seconds(run) for run in recent]
            old_times = [seconds(run) for run in baseline]
            new_median = statistics.median(new_times)
            old_median = statistics.median(old_times)
            if new_median > old_median * 1.35 and new_median - old_median > 30:
                alerts.append(
                    f"{event}: mediana reciente {new_median:.0f}s vs "
                    f"anterior {old_median:.0f}s (>35% y >30s)."
                )
            new_failures = sum(run["conclusion"] == "failure" for run in recent)
            old_failures = sum(run["conclusion"] == "failure" for run in baseline)
            if new_failures >= 3 and new_failures > old_failures:
                alerts.append(
                    f"{event}: {new_failures}/5 fallos recientes vs {old_failures}/5 previos."
                )
    lines.extend([
        "",
        "## Detección",
        *([f"- ⚠️ {alert}" for alert in alerts] if alerts else
          ["Sin regresión estadística suficiente para alertar; no equivale a garantizar cero fallos."]),
        "",
        "Los umbrales generan diagnóstico, **nunca** omiten pruebas, reintentan fallos",
        "de código ni modifican ramas o secretos automáticamente.",
    ])
    return "\n".join(lines) + "\n"


def self_test() -> None:
    assert percentile([7, 2, 3, 6, 1], .9) == 7
    assert seconds({
        "run_started_at": "2026-09-20T10:00:00Z",
        "updated_at": "2026-09-20T10:00:25Z",
    }) == 25
    assert seconds({"run_started_at": None, "updated_at": None}) is None
    synthetic = [
        {
            "event": "pull_request", "status": "completed", "conclusion": "success",
            "run_started_at": "2026-09-20T10:00:00Z",
            "updated_at": "2026-09-20T10:01:00Z",
        },
        {
            "event": "pull_request", "status": "completed", "conclusion": "cancelled",
            "run_started_at": "2026-09-20T10:00:00Z",
            "updated_at": "2026-09-20T10:10:00Z",
        },
    ]
    output = report(synthetic)
    assert "| pull_request | 1 | 1 | 0 | 60s | 60s |" in output
    assert "cancelled" not in output
    print("CI performance report contract passed.")


def fetch_runs(repository: str, token: str) -> list[dict]:
    if not token:
        raise ValueError("GH_TOKEN is required for read-only Actions telemetry")
    request = urllib.request.Request(
        f"https://api.github.com/repos/{repository}/actions/workflows/"
        f"{WORKFLOW}/runs?per_page=50&status=completed",
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "grindflow-ci-performance/1",
        },
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        payload = json.load(response)
    runs = payload.get("workflow_runs")
    if not isinstance(runs, list):
        raise ValueError("Unexpected GitHub Actions response")
    return runs


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--repository", default=os.getenv("GITHUB_REPOSITORY", ""))
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return 0
    if not args.repository or "/" not in args.repository:
        print("CI telemetry: repository must be owner/name", file=sys.stderr)
        return 2
    try:
        print(report(fetch_runs(args.repository, os.getenv("GH_TOKEN", ""))))
    except (ValueError, urllib.error.URLError, TimeoutError) as error:
        print(f"CI telemetry unavailable: {type(error).__name__}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
