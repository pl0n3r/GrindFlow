#!/usr/bin/env python3
"""Evaluación offline de los seis hitos de GrindFlow 1.0.0; nunca publica."""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import json
import re
import sys
from typing import Any

CONTRACT = "gf356-release-100-evidence-v1"
MILESTONES = ("mvp", "pilot", "recovery", "readiness", "security", "observability")
MAX_AGE = timedelta(days=30)
MAX_BYTES = 65536
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
REF_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9_./:@#?&=%+-]{2,179}\Z")
EVIDENCE_KEYS = frozenset({"status", "ref", "head_sha", "observed_at", "expires_at"})


def parse_utc(value: Any) -> datetime | None:
    """Solo tiempos ISO-8601 UTC explícitos; nunca asumir una zona."""
    if type(value) is not str or not (1 <= len(value) <= 40):
        return None
    try:
        stamp = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if stamp.tzinfo is None or stamp.utcoffset() != timedelta(0):
            return None
        return stamp.astimezone(timezone.utc)
    except (ValueError, OverflowError, TypeError):
        return None


def _check(raw: Any, *, candidate: str, at: datetime) -> dict[str, Any]:
    result = {"status": "UNKNOWN", "freshness": "UNKNOWN", "reason": "invalid_or_missing_evidence"}
    if type(raw) is not dict or set(raw) != EVIDENCE_KEYS:
        return result
    status = raw["status"]
    reference = raw["ref"]
    observed = parse_utc(raw["observed_at"])
    expires = parse_utc(raw["expires_at"])
    if (type(status) is not str or status not in ("PASS", "FAIL", "UNKNOWN")
            or type(reference) is not str or REF_RE.fullmatch(reference) is None
            or type(raw["head_sha"]) is not str
            or SHA_RE.fullmatch(raw["head_sha"]) is None
            or observed is None or expires is None or expires <= observed):
        return result
    if raw["head_sha"] != candidate:
        result["reason"] = "different_candidate_sha"
        return result
    if observed > at:
        result["reason"] = "future_observation"
        return result
    if at >= expires or at - observed > MAX_AGE:
        result.update({"freshness": "STALE", "reason": "expired_or_old_evidence"})
        return result
    result.update({"status": status, "freshness": "CURRENT",
                   "reason": "observed" if status != "UNKNOWN" else "explicit_unknown"})
    return result


def evaluate(manifest: Any) -> dict[str, Any]:
    """Solo calcula diagnóstico a partir de recibos; NO autentica fuentes."""
    if type(manifest) is not dict or set(manifest) != {
        "contract", "as_of", "candidate_sha", "milestones"
    }:
        raise ValueError("invalid manifest fields")
    if manifest["contract"] != CONTRACT:
        raise ValueError("unsupported contract")
    now = parse_utc(manifest["as_of"])
    candidate = manifest["candidate_sha"]
    if now is None or type(candidate) is not str or SHA_RE.fullmatch(candidate) is None:
        raise ValueError("invalid UTC timestamp or candidate SHA")
    raw = manifest["milestones"]
    if type(raw) is not dict or set(raw) - set(MILESTONES):
        raise ValueError("unrecognized milestone")

    findings = {name: _check(raw.get(name), candidate=candidate, at=now)
                for name in MILESTONES}
    # Un mismo recibo no acredita dos hitos diferentes.
    refs: dict[str, list[str]] = {}
    for name in MILESTONES:
        value = raw.get(name)
        if type(value) is dict and set(value) == EVIDENCE_KEYS:
            ref = value.get("ref")
            if type(ref) is str and REF_RE.fullmatch(ref) is not None:
                refs.setdefault(ref, []).append(name)
    for names in refs.values():
        if len(names) > 1:
            for name in names:
                findings[name] = {"status": "UNKNOWN", "freshness": "UNKNOWN",
                                  "reason": "shared_evidence_reference"}

    ready = all(item["status"] == "PASS" and item["freshness"] == "CURRENT"
                for item in findings.values())
    return {
        "contract": CONTRACT,
        "candidate_sha": candidate,
        "as_of": now.isoformat(),
        "go_no_go": "GO" if ready else "NO_GO",
        "interpretation": "READY_FOR_OWNER_REVIEW" if ready else "INCOMPLETE_OR_UNVERIFIED",
        "publication_allowed": False,
        "production_verified": False,
        "owner_decision_required": True,
        "milestones": findings,
    }


def _unique_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    obj = {}
    for key, value in pairs:
        if key in obj:
            raise ValueError("duplicate JSON key")
        obj[key] = value
    return obj


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args(argv)
    try:
        raw = sys.stdin.buffer.read(MAX_BYTES + 1)
        if len(raw) > MAX_BYTES:
            raise ValueError("too much evidence")
        envelope = json.loads(raw.decode("utf-8"), object_pairs_hook=_unique_keys,
                              parse_constant=lambda _: (_ for _ in ()).throw(ValueError("non-finite value")))
        result = evaluate(envelope)
    except (ValueError, UnicodeError, OSError, RecursionError, TypeError):
        print("NO_GO: invalid offline evidence; no action authorized", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
