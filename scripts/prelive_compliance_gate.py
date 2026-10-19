"""Pure, untrusted-claims pre-live diagnostic; never a publication authority."""
from __future__ import annotations

import re
from collections.abc import Mapping

REQUIRED = frozenset({
    "schema_version", "distribution_mode", "consent", "ownership",
    "age_eligibility", "platform_eligibility", "platform_scopes",
    "privacy", "legal_review",
})
EVIDENCE = re.compile(r"^ref:[A-Za-z0-9][A-Za-z0-9_.:/-]{0,127}$", re.ASCII)
SCOPE = re.compile(r"^[a-z][a-z0-9_.:-]{0,63}$", re.ASCII)
NO_EXTERNAL = "NO_EXTERNAL_PLATFORM"
NA_REASON = "NO_EXTERNAL_DESTINATION"


def _fields(value: object, expected: set[str]) -> bool:
    return isinstance(value, Mapping) and set(value) == expected


def _evidence(value: object) -> bool:
    return isinstance(value, str) and EVIDENCE.fullmatch(value) is not None


def _verified(value: object, state: str, extra: set[str] | None = None) -> bool:
    keys = {"status", "evidence_ref"} | (extra or set())
    return (_fields(value, keys) and value["status"] == state
            and _evidence(value["evidence_ref"]))


def _scopes(value: object) -> list[str] | None:
    if not isinstance(value, list) or not value or len(value) > 24:
        return None
    if not all(isinstance(s, str) and SCOPE.fullmatch(s) for s in value):
        return None
    if len(value) != len(set(value)) or any("*" in s for s in value):
        return None
    return value


def evaluate(facts: object) -> dict[str, object]:
    """Evaluate synthetic/externally supplied metadata without trusting its author.

    A successful result is *only* ready for a separate human/legal review.
    It cannot be used as an authorization token or to trigger any side effect.
    The response never includes raw facts, evidence refs or personal fields.
    """
    issues: set[str] = set()
    if not _fields(facts, set(REQUIRED)) or type(facts.get("schema_version")) is not int or facts["schema_version"] != 1:
        return _result({"INVALID_CONTRACT"})

    mode = facts["distribution_mode"]
    if mode not in ("EXTERNAL_PLATFORM", NO_EXTERNAL) or not isinstance(mode, str):
        issues.add("INVALID_DISTRIBUTION_MODE")

    consent = facts["consent"]
    if not _verified(consent, "VERIFIED", {"revoked"}) or type(consent["revoked"]) is not bool:
        issues.add("CONSENT_NOT_VERIFIED")
    elif consent["revoked"]:
        issues.add("CONSENT_REVOKED")

    if not _verified(facts["ownership"], "VERIFIED"):
        issues.add("RIGHTS_NOT_VERIFIED")
    if not _verified(facts["age_eligibility"], "ELIGIBLE"):
        issues.add("AGE_NOT_ELIGIBLE_OR_UNKNOWN")

    platform = facts["platform_eligibility"]
    scopes = facts["platform_scopes"]
    if mode == "EXTERNAL_PLATFORM":
        if not _verified(platform, "ELIGIBLE"):
            issues.add("PLATFORM_NOT_ELIGIBLE_OR_UNKNOWN")
        if not _verified(scopes, "VERIFIED", {"required", "granted"}):
            issues.add("SCOPES_NOT_VERIFIED")
        else:
            required, granted = _scopes(scopes["required"]), _scopes(scopes["granted"])
            if required is None or granted is None or set(required) != set(granted):
                issues.add("SCOPES_MISSING_OR_EXCESSIVE")
    elif mode == NO_EXTERNAL:
        for name, field in (("PLATFORM", platform), ("SCOPES", scopes)):
            if not (_fields(field, {"status", "reason_code"})
                    and field["status"] == "NOT_APPLICABLE"
                    and field["reason_code"] == NA_REASON):
                issues.add(name + "_NA_REASON_REQUIRED")

    if not _verified(facts["privacy"], "DOCUMENTED"):
        issues.add("PRIVACY_NOT_DOCUMENTED")
    # A caller cannot self-certify LEGAL_APPROVED via this offline evaluator.
    if not _fields(facts["legal_review"], {"status"}) or facts["legal_review"]["status"] != "PENDING_HUMAN_REVIEW":
        issues.add("HUMAN_LEGAL_REVIEW_REQUIRED")
    return _result(issues)


def _result(issues: set[str]) -> dict[str, object]:
    return {
        "status": "BLOCKED" if issues else "READY_FOR_HUMAN_REVIEW",
        "reasons": sorted(issues),
        "publish_allowed": False,
        "legal_approved": False,
        "go_live_allowed": False,
        "requires_human_review": True,
    }
