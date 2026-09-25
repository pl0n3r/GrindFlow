#!/usr/bin/env python3
"""Plan seguro de herencia de etiquetas desde un Issue enlazado por Closes #N."""
from __future__ import annotations

import argparse
import json
import re
import sys
from typing import Any

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
DIMENSION = re.compile(r"^(tipo|prioridad|estado)\s*[:：]", re.IGNORECASE)
CLOSES = re.compile(r"(?im)(?:^|\s)closes\s+#([1-9]\d{0,9})(?=\s|$|[.,;:)])")
MAX_BODY_CHARS = 65_536
MAX_LABELS = 200
DEFAULT_PR_STATE = "estado: en revisión"


class InheritanceError(ValueError):
    pass


def _label_name(item: object) -> str:
    if isinstance(item, str):
        name = item
    elif isinstance(item, dict):
        name = item.get("name")
    else:
        name = None
    if not isinstance(name, str) or not 1 <= len(name) <= 80 or name.strip() != name:
        raise InheritanceError("nombre de etiqueta inválido")
    return name


def label_names(value: object) -> set[str]:
    if not isinstance(value, list) or len(value) > MAX_LABELS:
        raise InheritanceError("labels debe ser una lista acotada")
    names = [_label_name(item) for item in value]
    if len(names) != len(set(names)):
        raise InheritanceError("etiquetas duplicadas")
    return set(names)


def linked_issue_number(body: object) -> int | None:
    if body is None:
        return None
    if not isinstance(body, str) or len(body) > MAX_BODY_CHARS:
        raise InheritanceError("body inválido o excesivo")
    matches = CLOSES.findall(body)
    if not matches:
        return None
    if len(matches) != 1:
        raise InheritanceError("Closes debe enlazar un único Issue")
    return int(matches[0])


def _dimension(names: set[str], allowed: frozenset[str], dimension: str) -> set[str]:
    scoped = {
        name
        for name in names
        if (match := DIMENSION.match(name)) is not None
        and match.group(1).lower() == dimension
    }
    unknown = scoped - allowed
    if unknown:
        raise InheritanceError(f"{dimension} contiene etiqueta no canónica")
    return names & allowed


def plan_inheritance(pr_labels: object, issue_labels: object) -> list[str]:
    pr = label_names(pr_labels)
    issue = label_names(issue_labels)

    pr_types = _dimension(pr, TYPES, "tipo")
    pr_priorities = _dimension(pr, PRIORITIES, "prioridad")
    pr_states = _dimension(pr, STATES, "estado")
    issue_types = _dimension(issue, TYPES, "tipo")
    issue_priorities = _dimension(issue, PRIORITIES, "prioridad")

    if len(pr_types) > 2 or len(pr_priorities) > 1 or len(pr_states) > 1:
        raise InheritanceError("PR ya contiene dimensiones conflictivas")

    add: set[str] = set()
    if not pr_types:
        if not 1 <= len(issue_types) <= 2:
            raise InheritanceError("Issue enlazado no tiene tipo heredable")
        add.update(issue_types)
    if not pr_priorities:
        if len(issue_priorities) != 1:
            raise InheritanceError("Issue enlazado no tiene prioridad heredable")
        add.update(issue_priorities)
    if not pr_states:
        add.add(DEFAULT_PR_STATE)
    return sorted(add)


def _read_json() -> object:
    raw = sys.stdin.buffer.read(131_073)
    if len(raw) > 131_072:
        raise InheritanceError("entrada demasiado grande")
    try:
        return json.loads(raw)
    except (json.JSONDecodeError, RecursionError) as exc:
        raise InheritanceError("JSON inválido") from exc


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=("extract", "plan"))
    args = parser.parse_args()
    try:
        data = _read_json()
        if not isinstance(data, dict):
            raise InheritanceError("envelope debe ser objeto")
        if args.operation == "extract":
            result = {"issue_number": linked_issue_number(data.get("body"))}
        else:
            result = {
                "add": plan_inheritance(data.get("pr_labels"), data.get("issue_labels"))
            }
    except InheritanceError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
