#!/usr/bin/env python3
"""Protege decisiones activas de GrindFlow con aprobación OWNER ligada al HEAD."""

from __future__ import annotations

import argparse
import json
import re
import stat
import sys
from pathlib import Path
from typing import Any

POLICY_PATH = Path("decisiones.yml")
BASE_POLICY_PATH = Path(".decision-base/decisiones.yml")
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
ID_RE = re.compile(r"^D-\d{3,}$", re.ASCII)
APPROVAL_RE = re.compile(
    r"<!--\s*grindflow-decision-approval\s+(\{[^<>]{1,500}\})\s*-->"
)
MAX_POLICY_BYTES = 256 * 1024
MAX_COMMENTS_BYTES = 2_000_000


class DecisionGateError(ValueError):
    pass


def validate_sha(value: str, label: str) -> str:
    normalized = value.strip().lower()
    if SHA_RE.fullmatch(normalized) is None:
        raise DecisionGateError(f"{label} inválido.")
    return normalized


def validate_decision(item: Any, seen: set[str]) -> None:
    if not isinstance(item, dict) or set(item) != {"id", "status", "text"}:
        raise DecisionGateError("Decisión inválida.")

    decision_id = item["id"]
    if (
        not isinstance(decision_id, str)
        or ID_RE.fullmatch(decision_id) is None
        or decision_id in seen
    ):
        raise DecisionGateError("ID inválido o duplicado.")
    seen.add(decision_id)

    if item["status"] not in {"active", "superseded"}:
        raise DecisionGateError("Estado de decisión inválido.")

    text = item["text"]
    if not isinstance(text, str) or not 1 <= len(text.strip()) <= 1000:
        raise DecisionGateError("Texto de decisión inválido.")


def validate_policy(raw: Any) -> dict[str, Any]:
    if not isinstance(raw, dict):
        raise DecisionGateError("Esquema de decisiones inválido.")
    if set(raw) != {"version", "review_round_limit", "decisions"}:
        raise DecisionGateError("Esquema de decisiones inválido.")
    if raw["version"] != 1 or raw["review_round_limit"] != 3:
        raise DecisionGateError("version=1 y review_round_limit=3 son obligatorios.")

    decisions = raw["decisions"]
    if not isinstance(decisions, list) or not 1 <= len(decisions) <= 200:
        raise DecisionGateError("decisions debe ser una lista acotada y no vacía.")

    seen: set[str] = set()
    for item in decisions:
        validate_decision(item, seen)
    return raw


def load_policy_file(path: Path, *, required: bool) -> dict[str, Any] | None:
    try:
        metadata = path.lstat()
    except FileNotFoundError:
        if required:
            raise DecisionGateError(f"Falta {path}.")
        return None
    except OSError as exc:
        raise DecisionGateError(f"No se pudo inspeccionar {path}.") from exc

    if not stat.S_ISREG(metadata.st_mode):
        raise DecisionGateError(f"{path} debe ser un archivo regular.")
    if metadata.st_size > MAX_POLICY_BYTES:
        raise DecisionGateError(f"{path} excede el tamaño permitido.")

    try:
        data = path.read_bytes()
        return validate_policy(json.loads(data.decode("utf-8")))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise DecisionGateError(f"{path} inválido.") from exc


def load_current_policy() -> dict[str, Any]:
    policy = load_policy_file(POLICY_PATH, required=True)
    if policy is None:  # pragma: no cover - required=True garantiza este caso.
        raise DecisionGateError("Falta decisiones.yml.")
    return policy


def base_policy() -> dict[str, Any] | None:
    return load_policy_file(BASE_POLICY_PATH, required=False)


def policy_changed(base: dict[str, Any] | None, current: dict[str, Any]) -> bool:
    return base is not None and base != current


def approval_sha_from_comment(comment: Any, owner: str) -> str | None:
    if not isinstance(comment, dict):
        return None

    user = comment.get("user")
    if not isinstance(user, dict):
        return None
    if user.get("login") != owner or comment.get("author_association") != "OWNER":
        return None

    body = comment.get("body")
    if not isinstance(body, str):
        return None
    matches = list(APPROVAL_RE.finditer(body))
    if not matches:
        return None

    try:
        payload = json.loads(matches[-1].group(1))
    except json.JSONDecodeError:
        return None
    if not isinstance(payload, dict) or set(payload) != {"sha"}:
        return None

    sha = payload.get("sha")
    if not isinstance(sha, str) or SHA_RE.fullmatch(sha.lower()) is None:
        return None
    return sha.lower()


def latest_owner_approval(lines: list[str], owner: str) -> str | None:
    latest: str | None = None
    for line in lines:
        if not line.strip():
            continue
        if len(line) > 200_000:
            raise DecisionGateError("Comentario excede el tamaño permitido.")
        try:
            comment = json.loads(line)
        except json.JSONDecodeError as exc:
            raise DecisionGateError("Comentarios contienen NDJSON inválido.") from exc
        candidate = approval_sha_from_comment(comment, owner)
        if candidate is not None:
            latest = candidate
    return latest


def evaluate(
    *,
    base: dict[str, Any] | None,
    current: dict[str, Any],
    comments: list[str],
    owner: str,
    head_sha: str,
) -> None:
    if not policy_changed(base, current):
        return
    if latest_owner_approval(comments, owner) != head_sha:
        raise DecisionGateError(
            "Cambio semántico en decisiones.yml requiere aprobación OWNER del HEAD exacto."
        )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--head-sha", required=True)
    parser.add_argument("--owner", required=True)
    args = parser.parse_args()

    try:
        head_sha = validate_sha(args.head_sha, "head-sha")
        current = load_current_policy()
        base = base_policy()
        payload = sys.stdin.read(MAX_COMMENTS_BYTES + 1)
        if len(payload) > MAX_COMMENTS_BYTES:
            raise DecisionGateError("Comentarios exceden el tamaño permitido.")
        evaluate(
            base=base,
            current=current,
            comments=payload.splitlines(),
            owner=args.owner,
            head_sha=head_sha,
        )
    except DecisionGateError as exc:
        print(f"DECISION_GATE=blocked: {exc}", file=sys.stderr)
        return 1

    print("DECISION_GATE=ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
