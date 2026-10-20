#!/usr/bin/env python3
"""Validate a declared S3 CORS policy offline, never a deployed bucket."""
from __future__ import annotations

import argparse
import json
import sys
from urllib.parse import urlsplit

CONTRACT = "gf345-cors-offline-v1"
MAX_INPUT_BYTES = 128 * 1024
REQUIRED_HEADERS = {"content-type", "x-amz-*"}


def unique_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("ambiguous JSON")
        result[key] = value
    return result


def valid_origin(origin: str) -> bool:
    try:
        url = urlsplit(origin)
        return (url.scheme == "https" and bool(url.hostname)
                and url.username is None and url.password is None
                and url.port in (None, 443) and url.path in ("", "/")
                and not url.query and not url.fragment and "*" not in origin
                and origin == f"https://{url.hostname}" + (":443" if url.port == 443 else ""))
    except (ValueError, AttributeError):
        return False


def assess(policy: object, origin: str) -> str:
    """Return compatible, incompatible or unknown, without side effects."""
    if not valid_origin(origin):
        return "unknown"
    if not isinstance(policy, dict) or set(policy) != {"CORSRules"}:
        return "unknown"
    rules = policy["CORSRules"]
    if not isinstance(rules, list) or not rules or len(rules) > 100:
        return "unknown"
    for rule in rules:
        if not isinstance(rule, dict) or not all(
            isinstance(rule.get(k), list)
            and bool(rule[k])
            and all(isinstance(v, str) for v in rule[k])
            for k in ("AllowedOrigins", "AllowedMethods", "AllowedHeaders")
        ):
            return "unknown"
        if any("*" in v for v in rule["AllowedOrigins"]):
            return "incompatible"
    for rule in rules:
        origins = rule["AllowedOrigins"]
        methods = {v.upper() for v in rule["AllowedMethods"]}
        headers = {v.lower() for v in rule["AllowedHeaders"]}
        # All properties must be satisfied by the SAME policy rule.
        if origin in origins and "PUT" in methods and REQUIRED_HEADERS <= headers:
            return "compatible"
    return "incompatible"


def main() -> int:
    parser = argparse.ArgumentParser(description="Offline S3 CORS declaration check")
    parser.add_argument("--origin", required=True, help="exact HTTPS browser origin")
    args = parser.parse_args()
    status = "unknown"
    try:
        raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
        if raw and len(raw) <= MAX_INPUT_BYTES:
            policy = json.loads(raw.decode("utf-8"), object_pairs_hook=unique_object)
            status = assess(policy, args.origin)
    except (ValueError, UnicodeError, TypeError):
        pass
    # No input, endpoint, origin, header value, signed URL or secret is printed.
    print(json.dumps({
        "contract": CONTRACT,
        "declared_status": status,
        "evidence_scope": "offline_configuration_only",
        "target_verified": False,
    }, sort_keys=True))
    return 0 if status == "compatible" else 2


if __name__ == "__main__":
    raise SystemExit(main())
