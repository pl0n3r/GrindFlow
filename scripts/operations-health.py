#!/usr/bin/env python3
"""Contrato puro de salud operativa para GrindFlow.

Consume exclusivamente metadata saneada de GitHub Actions ya normalizada por el
workflow. No realiza red, no lee producción y no ejecuta acciones externas.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from typing import Any, Mapping

VERSION = 1
SOURCES = ("ci_health", "deploy_observer", "production_smoke")
SEVERITY = {
    "ci_health": "S3",
    "deploy_observer": "S2",
    "production_smoke": "S2",
}
RUN_STATUSES = {
    "completed", "in_progress", "queued", "requested", "pending", "waiting", "missing"
}
CONCLUSIONS = {
    None, "success", "failure", "cancelled", "timed_out",
    "action_required", "neutral", "skipped", "stale",
}
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
URL_RE = re.compile(
    r"^https://github\.com/pl0n3r/GrindFlow/actions/runs/[1-9][0-9]*$"
)


class OperationsHealthError(ValueError):
    """Entrada de salud operativa inválida."""


def evaluate(payload: Mapping[str, Any], *, main_sha: str) -> dict[str, Any]:
    if not isinstance(main_sha, str) or SHA_RE.fullmatch(main_sha) is None:
        raise OperationsHealthError("main_sha inválido.")
    if not isinstance(payload, Mapping) or set(payload) != {"version", "signals"}:
        raise OperationsHealthError("payload contiene campos faltantes o no permitidos.")
    if payload["version"] != VERSION:
        raise OperationsHealthError("version inválida.")

    raw_signals = payload["signals"]
    if not isinstance(raw_signals, list) or len(raw_signals) != len(SOURCES):
        raise OperationsHealthError("signals debe contener exactamente las fuentes requeridas.")

    normalized = [_signal(item, main_sha=main_sha) for item in raw_signals]
    normalized.sort(key=lambda row: row["source"])
    if tuple(row["source"] for row in normalized) != tuple(sorted(SOURCES)):
        raise OperationsHealthError("sources incompletas o duplicadas.")

    current_degraded = any(
        row["state"] == "DEGRADED" and row["freshness"] == "CURRENT"
        for row in normalized
    )
    all_healthy = all(
        row["state"] == "HEALTHY" and row["freshness"] == "CURRENT"
        for row in normalized
    )
    overall = "HEALTHY" if all_healthy else ("DEGRADED" if current_degraded else "UNKNOWN")

    fingerprint_payload = [
        {
            "source": row["source"],
            "state": row["state"],
            "freshness": row["freshness"],
            "head_sha": row["head_sha"],
            "conclusion": row["conclusion"],
        }
        for row in normalized
    ]
    fingerprint = hashlib.sha256(
        json.dumps(fingerprint_payload, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()

    return {
        "version": VERSION,
        "state": overall,
        "main_sha": main_sha,
        "fingerprint": fingerprint,
        "signals": normalized,
        "alert": {
            "title": "[AUTO] GrindFlow Operations Status",
            "body": _render_alert(overall, main_sha, fingerprint, normalized),
        },
    }


def _signal(raw: Any, *, main_sha: str) -> dict[str, Any]:
    expected = {
        "source", "status", "conclusion", "head_sha", "run_id", "url", "updated_at"
    }
    if not isinstance(raw, Mapping) or set(raw) != expected:
        raise OperationsHealthError("signal contiene campos faltantes o no permitidos.")

    source = raw["source"]
    if source not in SOURCES:
        raise OperationsHealthError("source fuera del catálogo.")
    status = raw["status"]
    if status not in RUN_STATUSES:
        raise OperationsHealthError("status fuera del catálogo.")
    conclusion = raw["conclusion"]
    if conclusion not in CONCLUSIONS:
        raise OperationsHealthError("conclusion fuera del catálogo.")

    if status == "missing":
        if any(raw[key] is not None for key in ("conclusion", "head_sha", "run_id", "url", "updated_at")):
            raise OperationsHealthError("signal missing no puede traer evidencia.")
        return {
            "source": source,
            "severity": SEVERITY[source],
            "state": "UNKNOWN",
            "freshness": "UNKNOWN",
            "conclusion": None,
            "head_sha": None,
            "run_id": None,
            "url": None,
            "updated_at": None,
            "reason": "evidence_missing",
        }

    head_sha = raw["head_sha"]
    run_id = raw["run_id"]
    url = raw["url"]
    updated_at = raw["updated_at"]
    if not isinstance(head_sha, str) or SHA_RE.fullmatch(head_sha) is None:
        raise OperationsHealthError("head_sha de signal inválido.")
    if type(run_id) is not int or run_id <= 0:
        raise OperationsHealthError("run_id inválido.")
    if not isinstance(url, str) or URL_RE.fullmatch(url) is None:
        raise OperationsHealthError("url de run inválida.")
    if not isinstance(updated_at, str) or not _is_utc_timestamp(updated_at):
        raise OperationsHealthError("updated_at inválido.")

    if head_sha != main_sha:
        state, freshness, reason = "UNKNOWN", "STALE", "head_sha_mismatch"
    elif status != "completed":
        state, freshness, reason = "UNKNOWN", "CURRENT", "run_not_terminal"
    elif conclusion == "success":
        state, freshness, reason = "HEALTHY", "CURRENT", "success"
    else:
        state, freshness, reason = "DEGRADED", "CURRENT", "terminal_non_success"

    return {
        "source": source,
        "severity": SEVERITY[source],
        "state": state,
        "freshness": freshness,
        "conclusion": conclusion,
        "head_sha": head_sha,
        "run_id": run_id,
        "url": url,
        "updated_at": updated_at,
        "reason": reason,
    }


def _render_alert(
    state: str,
    main_sha: str,
    fingerprint: str,
    signals: list[dict[str, Any]],
) -> str:
    lines = [
        "<!-- grindflow-operations-status-v1 -->",
        "## GrindFlow Operations Status",
        "",
        f"State: **{state}**",
        f"Main SHA: `{main_sha}`",
        f"Fingerprint: `{fingerprint}`",
        "",
        "| Source | Severity | State | Freshness | Evidence |",
        "| --- | --- | --- | --- | --- |",
    ]
    for row in signals:
        if row["run_id"] is None:
            evidence = "missing"
        else:
            evidence = f"[run {row['run_id']}]({row['url']}) · {row['conclusion'] or row['status']}"
        lines.append(
            f"| {row['source']} | {row['severity']} | {row['state']} | "
            f"{row['freshness']} | {evidence} |"
        )
    lines.extend(
        [
            "",
            "Evidence is allowlisted metadata only. No response bodies, logs, credentials,",
            "cookies, private paths, backup payloads or PII are copied to this Issue.",
            "",
            "Runbook: `docs/PRODUCTION-OPERATIONS.md`.",
        ]
    )
    return "\n".join(lines) + "\n"


def _is_utc_timestamp(value: str) -> bool:
    return bool(
        re.fullmatch(
            r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z",
            value,
        )
    )


def _read_json_from_stdin() -> Any:
    try:
        return json.loads(sys.stdin.read())
    except json.JSONDecodeError as exc:
        raise OperationsHealthError("input JSON inválido.") from exc


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=("evaluate",))
    parser.add_argument("--main-sha", required=True)
    args = parser.parse_args()

    try:
        result = evaluate(_read_json_from_stdin(), main_sha=args.main_sha)
    except OperationsHealthError as exc:
        print(f"operations status: {exc}", file=sys.stderr)
        return 2

    rendered = json.dumps(result, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    print(rendered)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
