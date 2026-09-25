#!/usr/bin/env python3
"""Reporte sanitizado e idempotente de Issues/PRs abiertos sin clasificación completa."""
from __future__ import annotations

import json
import sys
from typing import Any

MARKER = "<!-- grindflow:label-sweep:v1 -->"
AUTO_TITLE = "[AUTO] Ítems sin etiquetas"
BOT_LOGIN = "github-actions[bot]"
TYPES = frozenset({
    "tipo: error", "tipo: mejora", "tipo: producto", "tipo: infraestructura",
    "tipo: documentación", "tipo: pruebas", "tipo: incidente",
    "tipo: seguridad", "tipo: calidad", "tipo: deuda técnica",
    "tipo: accesibilidad",
})
PRIORITIES = frozenset({
    "prioridad: crítica", "prioridad: alta", "prioridad: media", "prioridad: baja",
})
STATES = frozenset({
    "estado: disponible", "estado: reservado", "estado: en revisión",
    "estado: bloqueado", "estado: requiere recuperación",
    "estado: completado", "estado: cancelado",
})
MAX_ITEMS = 5000


class SweepError(ValueError):
    pass


def _names(item: dict[str, Any]) -> set[str]:
    labels = item.get("labels")
    if not isinstance(labels, list) or len(labels) > 200:
        raise SweepError("labels inválidas")
    names: set[str] = set()
    for raw in labels:
        name = raw.get("name") if isinstance(raw, dict) else None
        if not isinstance(name, str) or not 1 <= len(name) <= 80:
            raise SweepError("label inválida")
        names.add(name)
    return names


def missing_dimensions(names: set[str]) -> tuple[str, ...]:
    missing = []
    type_count = len(names & TYPES)
    priority_count = len(names & PRIORITIES)
    state_count = len(names & STATES)
    if not 1 <= type_count <= 2:
        missing.append("tipo")
    if priority_count != 1:
        missing.append("prioridad")
    if state_count != 1:
        missing.append("estado")
    return tuple(missing)


def _number(item: dict[str, Any]) -> int:
    number = item.get("number")
    if type(number) is not int or number <= 0:
        raise SweepError("number inválido")
    return number


def _is_auto_report(item: dict[str, Any]) -> bool:
    body = item.get("body")
    user = item.get("user")
    login = user.get("login") if isinstance(user, dict) else None
    return (
        isinstance(body, str)
        and body.startswith(MARKER)
        and item.get("title") == AUTO_TITLE
        and login == BOT_LOGIN
        and "pull_request" not in item
    )


def _incomplete_row(item: dict[str, Any], number: int) -> dict[str, Any] | None:
    problems = missing_dimensions(_names(item))
    if not problems:
        return None
    return {
        "number": number,
        "kind": "PR" if "pull_request" in item else "Issue",
        "missing": list(problems),
    }


def _body(rows: list[dict[str, Any]]) -> str:
    lines = [
        MARKER,
        "# Ítems abiertos sin clasificación completa",
        "",
        "Reporte automático sanitizado: solo números, tipo de entidad y dimensiones controladas.",
        "",
    ]
    if rows:
        lines += [
            "| Ítem | Clase | Falta |",
            "| --- | --- | --- |",
            *[
                f"| #{row['number']} | {row['kind']} | {', '.join(row['missing'])} |"
                for row in rows
            ],
        ]
    else:
        lines.append("No hay ítems abiertos con clasificación incompleta.")
    lines.append("")
    return "\n".join(lines)


def build_report(items: object) -> dict[str, Any]:
    if not isinstance(items, list) or len(items) > MAX_ITEMS:
        raise SweepError("items inválidos o excesivos")

    rows: list[dict[str, Any]] = []
    auto_numbers: list[int] = []
    for item in items:
        if not isinstance(item, dict):
            raise SweepError("item inválido")
        number = _number(item)
        if _is_auto_report(item):
            auto_numbers.append(number)
            continue
        row = _incomplete_row(item, number)
        if row is not None:
            rows.append(row)

    rows.sort(key=lambda row: row["number"])
    auto_numbers.sort()
    return {
        "count": len(rows),
        "existing_number": auto_numbers[0] if auto_numbers else None,
        "duplicate_numbers": auto_numbers[1:],
        "body": _body(rows),
    }


def main() -> int:
    raw = sys.stdin.buffer.read(4_000_001)
    if len(raw) > 4_000_000:
        print("ERROR: entrada demasiado grande", file=sys.stderr)
        return 2
    try:
        data = json.loads(raw)
        result = build_report(data)
    except (json.JSONDecodeError, RecursionError, SweepError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
