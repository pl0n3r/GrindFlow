#!/usr/bin/env python3
"""Summarize only allowlisted authentication signals from a production smoke log.

This tool never contacts production, retries a login, prints HTTP response bodies,
or asserts that an account/password is correct. Raw logs stay in their short-lived
artifact; the issue summary contains a closed set of fixed, non-secret labels.
"""
from __future__ import annotations

import argparse
import json
import re
import sys

MAX_BYTES = 1_000_000
SIGNALS = {
    "LOGIN_SESSION_PREFLIGHT": frozenset({"consistent", "inconsistent"}),
    "LOGIN_REDIRECT_PATH": frozenset({
        "/login", "/dashboard", "(missing)", "(redacted)", "/organizations",
        "/admin", "/admin/system",
    }),
    "LOGIN_FAILURE_SESSION_CHECK": frozenset({"stable", "changed", "unavailable"}),
}
DASHBOARD_FAILURE = re.compile(
    r"^ERROR: authenticated dashboard returned HTTP "
    r"(301|302|303|307|308), redirect path "
    r"(/login|/dashboard|/organizations|/admin|/admin/system|\(redacted\)|\(missing\)); "
    r"check authentication/session\. No repeated login attempts\.$"
)
LOGIN_HTTP_FAILURE = re.compile(
    r"^ERROR: login returned HTTP (301|302|303|307|308|401|403|419|422|429); "
    r"stop authentication retries(?: on unexpected redirect)?\.$"
)


def parse_signals(log: str) -> dict[str, str]:
    """Extract permitted telemetry and reject conflicting values."""
    signals: dict[str, str] = {}
    for line in log.splitlines():
        for name, allowed in SIGNALS.items():
            prefix = name + "="
            if not line.startswith(prefix):
                continue
            value = line[len(prefix):]
            if value not in allowed:
                raise ValueError("invalid authentication signal")
            if name in signals and signals[name] != value:
                raise ValueError("conflicting authentication signals")
            signals[name] = value
    return signals


def classify(log: str) -> dict[str, str]:
    """Classify one authentication outcome without echoing the source log."""
    signals = parse_signals(log)
    preflight = signals.get("LOGIN_SESSION_PREFLIGHT", "unobserved")
    redirect = signals.get("LOGIN_REDIRECT_PATH", "unobserved")
    recheck = signals.get("LOGIN_FAILURE_SESSION_CHECK", "unobserved")
    dashboard_errors = {
        (match.group(1), match.group(2))
        for line in log.splitlines()
        if (match := DASHBOARD_FAILURE.fullmatch(line))
    }
    login_errors = {
        match.group(1)
        for line in log.splitlines()
        if (match := LOGIN_HTTP_FAILURE.fullmatch(line))
    }
    if (
        len(dashboard_errors) > 1
        or len(login_errors) > 1
        or (dashboard_errors and login_errors)
        or (dashboard_errors and redirect != "/dashboard")
        or (recheck != "unobserved" and redirect != "/login")
        or (login_errors and recheck != "unobserved")
        or (login_errors and redirect != "unobserved"
            and not login_errors.issubset({"301", "307", "308"}))
        or (preflight == "inconsistent" and (
            redirect != "unobserved" or recheck != "unobserved"
            or dashboard_errors or login_errors
        ))
    ):
        raise ValueError("conflicting authentication outcomes")

    diagnosis = "not_classified"
    if preflight == "inconsistent":
        diagnosis = "anonymous_session_inconsistent"
    elif preflight == "consistent" and login_errors:
        diagnosis = "login_http_rejected"
    elif preflight == "consistent" and redirect == "/login":
        if recheck == "stable":
            diagnosis = "login_rejected_anonymous_session_stable"
        elif recheck == "changed":
            diagnosis = "login_rejected_anonymous_session_changed"
        else:
            diagnosis = "login_rejected_recheck_unavailable"
    elif preflight == "consistent" and redirect == "/dashboard" and dashboard_errors:
        diagnosis = "dashboard_authentication_redirect"

    return {
        "contract": "grindflow-production-smoke-auth-triage-v1",
        "preflight": preflight,
        "login_redirect": redirect,
        "recheck": recheck,
        "diagnosis": diagnosis,
    }


def markdown(summary: dict[str, str]) -> str:
    """Render a fixed-vocabulary incident summary without remote content."""
    labels = {
        "not_classified": "Sin diagnóstico de autenticación concluyente.",
        "anonymous_session_inconsistent": (
            "El preflight detectó cambio de sesión/CSRF anónimo antes del POST."
        ),
        "login_rejected_anonymous_session_stable": (
            "El POST volvió a /login y el recheck anónimo permaneció estable."
        ),
        "login_rejected_anonymous_session_changed": (
            "El POST volvió a /login y cambió la sesión/CSRF del recheck."
        ),
        "login_rejected_recheck_unavailable": (
            "El POST volvió a /login; no se pudo confirmar el recheck anónimo."
        ),
        "dashboard_authentication_redirect": (
            "El POST redirigió a /dashboard, pero el dashboard volvió a redirigir."
        ),
        "login_http_rejected": (
            "El endpoint de login rechazó la solicitud con un HTTP de autenticación."
        ),
    }
    return "\n".join([
        "### Diagnóstico de autenticación (señales permitidas)",
        "",
        f"- Preflight anónimo: `{summary['preflight']}`.",
        f"- Redirect del login: `{summary['login_redirect']}`.",
        f"- Recheck anónimo: `{summary['recheck']}`.",
        f"- Resultado: {labels[summary['diagnosis']]}",
        "- No determina si la contraseña, cuenta, rate-limit o configuración productiva son correctos.",
        "- No autoriza reintentos de credenciales ni cambios de cuentas.",
        "",
    ])


def main() -> int:
    """Read bounded input and emit a safe summary or a fixed error."""
    parser = argparse.ArgumentParser(description="Safe offline production smoke auth summary")
    parser.add_argument("--markdown", action="store_true")
    args = parser.parse_args()
    try:
        raw = sys.stdin.buffer.read(MAX_BYTES + 1)
        if len(raw) > MAX_BYTES:
            raise ValueError("log exceeds limit")
        summary = classify(raw.decode("utf-8"))
    except ValueError:
        print("ERROR: smoke auth summary unavailable", file=sys.stderr)
        return 2

    if args.markdown:
        print(markdown(summary))
    else:
        print(json.dumps(summary, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
