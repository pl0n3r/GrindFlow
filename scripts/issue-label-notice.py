#!/usr/bin/env python3
"""Idempotent Issue classification notice. Reads metadata only; never reads PR code."""
from __future__ import annotations

import json
import os
import re
import subprocess

MARKER = "<!-- grindflow:issue-label-notice:v1 -->"
TYPES = frozenset({
    "tipo: error", "tipo: mejora", "tipo: producto", "tipo: infraestructura",
    "tipo: documentación", "tipo: pruebas", "tipo: incidente",
    "tipo: seguridad", "tipo: calidad", "tipo: deuda técnica", "tipo: accesibilidad",
})
PRIORITIES = frozenset({
    "prioridad: crítica", "prioridad: alta", "prioridad: media", "prioridad: baja",
})
STATES = frozenset({
    "estado: disponible", "estado: reservado", "estado: en revisión",
    "estado: bloqueado", "estado: requiere recuperación",
    "estado: completado", "estado: cancelado",
})
DIMENSION = re.compile(r"^(?:tipo|prioridad|estado)\s*[:：]", re.IGNORECASE)
DEFAULT_STATE = "estado: disponible"


def names_of(issue: dict) -> set[str]:
    """Return only well-formed label names from an Issue API snapshot."""
    if not isinstance(issue.get("labels"), list):
        raise ValueError("Invalid Issue labels")
    return {
        value["name"] for value in issue["labels"]
        if isinstance(value, dict) and isinstance(value.get("name"), str)
    }


def missing(names: set[str]) -> tuple[str, ...]:
    """Describe absent or invalid dimensions without logging user-supplied values."""
    problems = []
    for kind, canonical, low, high in (
        ("tipo (1–2)", TYPES, 1, 2),
        ("prioridad (exactamente 1)", PRIORITIES, 1, 1),
        ("estado (exactamente 1)", STATES, 1, 1),
    ):
        if not low <= len(names & canonical) <= high:
            problems.append(kind)
    if any(DIMENSION.match(n) and n not in TYPES | PRIORITIES | STATES for n in names):
        problems.append("etiquetas de dimensión no canónicas")
    return tuple(problems)


def notice(problems: tuple[str, ...]) -> str:
    """Create a stable, editable notice for one Issue, never an append-only feed."""
    if not problems:
        return MARKER + "\n✅ Clasificación completa. Este aviso se conserva como historial."
    return (
        MARKER
        + "\n⚠️ Clasificación pendiente: "
        + "; ".join(problems)
        + ". Añade etiquetas canónicas de tipo y prioridad; el estado solo se "
        + "establece por defecto si no hay ninguno. No se sustituyen estados existentes."
    )


def gh(*args: str) -> object:
    """Invoke gh with argument vectors, never interpolate Issue text into a shell."""
    result = subprocess.run(
        ["gh", "api", *args], check=True, capture_output=True, text=True,
        timeout=30,
    )
    return json.loads(result.stdout)


def main() -> int:
    repository = os.environ["GITHUB_REPOSITORY"]
    number = os.environ["ISSUE_NUMBER"]
    if re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository) is None:
        raise ValueError("Invalid repository")
    if re.fullmatch(r"[1-9][0-9]{0,9}", number) is None:
        raise ValueError("Invalid Issue number")
    endpoint = f"repos/{repository}/issues/{number}"
    issue = gh(endpoint)
    if not isinstance(issue, dict) or "pull_request" in issue:
        raise ValueError("Expected an Issue, not a pull request")
    names = names_of(issue)
    if not names & STATES and not any(re.match(r"^estado\s*[:：]", n, re.IGNORECASE) and n not in STATES for n in names):
        gh("--method", "POST", endpoint + "/labels", "-f", "labels[]=" + DEFAULT_STATE)
        issue = gh(endpoint)
        names = names_of(issue)

    problems = missing(names)
    comments = gh("--paginate", "--slurp", endpoint + "/comments?per_page=100")
    if not isinstance(comments, list):
        raise ValueError("Invalid comments response")
    matches = [
        comment for page in comments if isinstance(page, list)
        for comment in page if isinstance(comment, dict)
        and MARKER in str(comment.get("body") or "")
        and isinstance(comment.get("user"), dict)
        and comment["user"].get("login") == "github-actions[bot]"
    ]
    if not problems and not matches:
        return 0  # Never create noise for correctly labeled Issues.
    body = notice(problems)
    if matches:
        current = matches[0]
        if current.get("body") != body:
            gh("--method", "PATCH", endpoint + "/comments/" + str(current["id"]), "-f", "body=" + body)
    else:
        gh("--method", "POST", endpoint + "/comments", "-f", "body=" + body)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
