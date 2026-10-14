#!/usr/bin/env python3
"""Matriz diagnóstica de readiness GrindFlow; nunca autoriza publicación.

Entrada JSON sintética y explícita: as_of UTC, milestones, risks y signals.
Ausencias, fuentes ambiguas y observaciones vencidas quedan UNKNOWN.
Ninguna función contacta GitHub, hosting, proveedores o producción.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone, timedelta
import json
import sys
import re
from typing import Any

MILESTONES = ("250", "271", "311", "351", "security", "quality", "observability")
SIGNALS = ("version", "sha", "deploy", "production")
MAX_AGE = timedelta(hours=24)
SOURCE_RE = re.compile(r"[A-Za-z0-9_.:/#-]{1,96}\Z")


def _utc(value: Any) -> datetime | None:
    if type(value) is not str or not value or len(value) > 64:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if parsed.tzinfo is None or parsed.utcoffset() is None:
            return None
        return parsed.astimezone(timezone.utc)
    except (ValueError, TypeError, OverflowError):
        return None


def _signal(raw: Any, now: datetime) -> dict[str, str | None]:
    unknown = {"status": "UNKNOWN", "freshness": "UNKNOWN",
               "reason": "missing_or_invalid_evidence", "source": None}
    if type(raw) is not dict:
        return unknown
    source = raw.get("source")
    observed = _utc(raw.get("observed_at"))
    status = raw.get("status")
    if (type(source) is not str or SOURCE_RE.fullmatch(source) is None
            or status not in ("PASS", "FAIL", "UNKNOWN")
            or type(status) is not str or observed is None):
        return unknown
    age = now - observed
    if age < timedelta(0):
        return {"status": "UNKNOWN", "freshness": "UNKNOWN",
                "reason": "future_observation", "source": source}
    if age > MAX_AGE:
        return {"status": "UNKNOWN", "freshness": "STALE",
                "reason": "stale_observation", "source": source}
    return {"status": status, "freshness": "CURRENT",
            "reason": "observed" if status != "UNKNOWN" else "explicit_unknown",
            "source": source}


def evaluate(manifest: Any) -> dict[str, Any]:
    """Evalúa exclusivamente evidencia suministrada; salida no ejecutable."""
    if type(manifest) is not dict:
        raise ValueError("manifest debe ser objeto JSON")
    now = _utc(manifest.get("as_of"))
    if now is None:
        raise ValueError("as_of debe ser una fecha UTC con zona verificable")
    raw_milestones = manifest.get("milestones")
    raw_signals = manifest.get("signals")
    milestones = raw_milestones if type(raw_milestones) is dict else {}
    signals = raw_signals if type(raw_signals) is dict else {}
    findings = {name: _signal(milestones.get(name), now) for name in MILESTONES}
    identities = {name: _signal(signals.get(name), now) for name in SIGNALS}

    risks = manifest.get("risks")
    risks_unknown = type(risks) is not list or len(risks) > 500
    untracked: list[dict[str, Any]] = []
    if not risks_unknown:
        for index, risk in enumerate(risks):
            if (type(risk) is not dict or type(risk.get("severity")) is not str
                    or risk["severity"] not in ("P0", "P1", "P2", "P3")):
                risks_unknown = True
                continue
            if risk["severity"] in ("P0", "P1"):
                number = risk.get("issue")
                if type(number) is not int or number <= 0:
                    untracked.append({"index": index, "severity": risk["severity"]})

    ready = (all(v["status"] == "PASS" for v in findings.values())
             and all(v["status"] == "PASS" for v in identities.values())
             and not risks_unknown and not untracked)
    return {
        "as_of": now.isoformat(),
        "readiness": "READY_FOR_OWNER_REVIEW" if ready else "NO_GO",
        "publication_allowed": False,
        "execution": "none",
        "milestones": findings,
        "signals": identities,
        "risks_unknown": risks_unknown,
        "untracked_p0_p1": untracked,
        "options": [
            {"id": "A", "label": "Publicar", "executable": False,
             "requires_owner_decision": True},
            {"id": "B", "label": "No publicar", "executable": False,
             "safe_default": True},
        ],
    }


def render_markdown(report: dict[str, Any]) -> str:
    """Markdown sin valores libres ni comandos de despliegue."""
    rows = ["# GrindFlow — preparación 1.0.0 (solo diagnóstico)", "",
            "Estado: **" + report["readiness"] + "**",
            "Publicación automática: **NO**", "",
            "## Hitos y evidencia", "",
            "| Hito | Estado | Frescura | Motivo |",
            "| --- | --- | --- | --- |"]
    for name, signal in report["milestones"].items():
        rows.append("| " + name + " | " + signal["status"] + " | "
                    + signal["freshness"] + " | " + signal["reason"] + " |")
    rows += ["", "## Identidades independientes", "",
             "| Señal | Estado | Frescura | Motivo |",
             "| --- | --- | --- | --- |"]
    for name, signal in report["signals"].items():
        rows.append("| " + name + " | " + signal["status"] + " | "
                    + signal["freshness"] + " | " + signal["reason"] + " |")
    rows += ["", "P0/P1 sin Issue: **" + str(len(report["untracked_p0_p1"]))
             + "**", "Inventario de riesgos: **"
             + ("UNKNOWN" if report["risks_unknown"] else "PROVISTO") + "**",
             "", "## Opciones para decisión humana",
             "- **Publicar**: no ejecutable desde esta herramienta.",
             "- **No publicar**: opción segura predeterminada.",
             "", "Esta matriz no verifica producción ni concede go-live."]
    return "\n".join(rows) + "\n"


def _reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    """No aceptar ambigüedad de un JSON con claves repetidas en ningún nivel."""
    unique: dict[str, Any] = {}
    for key, value in pairs:
        if key in unique:
            raise ValueError("Evidencia JSON ambigua: clave duplicada")
        unique[key] = value
    return unique


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--format", choices=("json", "markdown"), default="json")
    args = parser.parse_args(argv)
    try:
        # Entrada textual explícita y acotada: ningún path arbitrario llega a open().
        payload = sys.stdin.read(131073)
        if len(payload) > 131072:
            raise ValueError("Evidencia excede 128 KiB")
        manifest = json.loads(payload, object_pairs_hook=_reject_duplicate_keys)
        report = evaluate(manifest)
    except (OSError, UnicodeError, ValueError, json.JSONDecodeError) as exc:
        parser.exit(2, "Evidencia inválida, readiness UNKNOWN: " + str(exc) + "\n")
    if args.format == "json":
        print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))
    else:
        print(render_markdown(report), end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
