#!/usr/bin/env python3
"""Contrato offline de etiquetas de GrindFlow (sin llamadas ni cambios a GitHub).

Factory @v1 exige un único tipo; la decisión del propietario permite dos
cuando ambos aplican. Este módulo valida la selección sin modificar metadatos.
"""
from __future__ import annotations

import json
import re
import sys

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
MAX_INPUT_BYTES = 65536
DIMENSION_PATTERN = re.compile(r"^(?:tipo|prioridad|estado)\s*[:：]", re.IGNORECASE)


class LabelSelectionError(ValueError):
    pass


def label_name(item: object) -> str:
    """Extrae una etiqueta desde la API de GitHub o el formato simplificado."""
    if isinstance(item, str):
        name = item
    elif isinstance(item, dict):
        name = item.get("name")
    else:
        name = None
    if not isinstance(name, str) or not 1 <= len(name) <= 80 or name.strip() != name:
        raise LabelSelectionError("Nombre de etiqueta inválido")
    return name


def validate_selection(labels: object) -> dict[str, list[str]]:
    """Valida nombres canónicos; dos tipos necesitan justificación humana."""
    if not isinstance(labels, list) or len(labels) > 200:
        raise LabelSelectionError("La selección debe ser una lista acotada")
    names = [label_name(item) for item in labels]
    if len(names) != len(set(names)):
        raise LabelSelectionError("Etiquetas duplicadas")
    unknown = [
        n for n in names if DIMENSION_PATTERN.match(n)
        and n not in TYPES | PRIORITIES | STATES
    ]
    if unknown:
        raise LabelSelectionError("Dimensión con etiqueta no canónica")
    selected = set(names)
    kinds, priorities, states = selected & TYPES, selected & PRIORITIES, selected & STATES
    if not 1 <= len(kinds) <= 2:
        raise LabelSelectionError("Se necesita uno o dos tipos justificados")
    if len(priorities) != 1:
        raise LabelSelectionError("Se necesita exactamente una prioridad")
    if len(states) != 1:
        raise LabelSelectionError("Se necesita exactamente un estado")
    return {
        "types": sorted(kinds),
        "priorities": sorted(priorities),
        "states": sorted(states),
    }


def main() -> int:
    raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
    if len(raw) > MAX_INPUT_BYTES:
        print("ERROR: entrada demasiado grande", file=sys.stderr)
        return 2
    try:
        result = validate_selection(json.loads(raw))
    except (ValueError, RecursionError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
