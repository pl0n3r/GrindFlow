#!/usr/bin/env python3
"""GrindFlow #718: evidencia offline SPEC:029..033; nunca valida Hostinger.

Sólo lee contratos versionados y ejecuta escenarios enteramente sintéticos.
El estado de despliegue, smoke real, consentimiento y tráfico permanecen UNKNOWN.
"""
from __future__ import annotations

from datetime import date
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/GRINDFLOW-SPEC.md"
DISTRIBUTION = "tests/Feature/DistributionTest.php"
TRAFFIC = "tests/Feature/TrafficAttributionTest.php"
VERSION = "config/version.php"
SMOKE = "scripts/production-smoke.sh"
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
IDS = tuple(f"SPEC:{n:03d}" for n in range(29, 34))
CASES = {
    "SPEC:029": (
        "## 10. Distribution attempt audit",
        "immutable tenant-owned event", DISTRIBUTION,
        ("test_delivery_attempt_timeline_is_immutable_tenant_scoped_and_visible",
         "test_rate_limits_append_safe_outcome_without_consuming_attempt_budget",
         "test_audit_events_refuse_model_updates"),
    ),
    "SPEC:030": (
        "## 11. Product version and verified deployment identity",
        "exact deployed source identity", VERSION, (),
    ),
    "SPEC:031": (
        "## 12. Traffic daily aggregate export",
        "100-link UI preview never", TRAFFIC,
        ("test_daily_csv_exports_all_filtered_links_beyond_dashboard_preview",
         "test_daily_csv_export_rejects_unprivileged_invalid_or_unbounded_requests",
         "test_daily_csv_export_applies_filters_and_never_leaks_another_tenant"),
    ),
    "SPEC:032": (
        "## 13. Traffic link lifecycle and CSV calendar boundaries",
        "366 allowed, 367", TRAFFIC,
        ("test_traffic_manager_can_pause_resume_link_without_losing_history",
         "test_traffic_link_status_rejects_model_foreign_link_and_invalid_status"),
    ),
    "SPEC:033": (
        "## 14. Authenticated production workspace smoke",
        "shares one login", SMOKE, (),
    ),
}
FILES = {MATRIX, SPEC, DISTRIBUTION, TRAFFIC, VERSION, SMOKE}


def parse_rows(document: str) -> dict[str, tuple[str, ...]]:
    """No usar una matriz con filas duplicadas, ausentes o no acreditadas."""
    found = {}
    for line in document.splitlines():
        if not any(line.startswith(f"| {ident} |") for ident in IDS):
            continue
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if len(cells) != 8 or cells[0] in found:
            raise ValueError("UNKNOWN: fila duplicada o truncada")
        found[cells[0]] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: fila SPEC ausente")
    return found


def spec_section(document: str, title: str) -> str | None:
    marker = title + "\n"
    if document.count(marker) != 1:
        return None
    text = document.split(marker, 1)[1]
    return text.split("\n## ", 1)[0]


def assertion_method(document: str, method: str) -> bool:
    """Evitar acreditar una firma de PHPUnit sin una aserción verificable."""
    match = re.search(r"\bpublic\s+function\s+" + re.escape(method) + r"\s*\(", document)
    if match is None:
        return False
    body = document[match.end():].split("public function ", 1)[0]
    return re.search(r"(?:->assert[A-Z]|::assert[A-Z]|->expectException\()", body) is not None


def inspect_local(identifier: str, rows: dict, sources: dict) -> dict[str, str]:
    result = {"code": UNKNOWN, "deployment": UNKNOWN, "production": UNKNOWN}
    if identifier not in CASES:
        return result
    row = rows.get(identifier)
    if (row is None or len(row) != 8 or row[2] != "NO_VERIFICABLE"
            or row[5:8] != ("NO_ACREDITADO",) * 3
            or "GRINDFLOW-SPEC.md" not in row[3]):
        return result
    title, token, path, methods = CASES[identifier]
    clause = spec_section(sources.get(SPEC, ""), title)
    source = sources.get(path, "")
    if not clause or token not in clause or not source:
        return result
    if not all(assertion_method(source, name) for name in methods):
        return result
    if identifier == "SPEC:030":
        version = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", source)
        smoke = sources.get(SMOKE, "")
        if version is None or "EXPECTED_RELEASE=" not in smoke:
            return result
    if identifier == "SPEC:033":
        if not all(x in source for x in (
            "EXPECTED_RELEASE=", "check_workspace_modules() {",
            "for module in scheduler distribution traffic finance; do",
            "traffic/export", "MODULE_READ_ONLY=", "data-pending-migrations",
        )):
            return result
    result["code"] = LOCAL
    return result


def append_attempt(history: object, delivery: object, event: object):
    """Modelo puro de auditoría; una transición fenced produce fila inmutable."""
    required = {"tenant", "key", "fenced"}
    accepted = {"tenant", "key", "type", "attempt", "error"}
    if (type(history) is not tuple or type(delivery) is not dict
            or type(event) is not dict or set(delivery) != required
            or set(event) != accepted or delivery["fenced"] is not True
            or not isinstance(delivery["tenant"], str)
            or not delivery["tenant"] or not isinstance(delivery["key"], str)
            or not delivery["key"] or event["tenant"] != delivery["tenant"]
            or event["key"] != delivery["key"]
            or event["type"] not in ("started", "retry", "delivered", "failed")
            or type(event["attempt"]) is not int or event["attempt"] < 0
            or event["error"] not in (None, "rate_limited", "transient")):
        return UNKNOWN
    return history + ((len(history) + 1, event["type"], event["attempt"],
                       event["error"]),)


def csv_days(start: str, end: str) -> int | str:
    try:
        first, last = date.fromisoformat(start), date.fromisoformat(end)
    except (TypeError, ValueError):
        return UNKNOWN
    span = (last - first).days + 1
    return span if 1 <= span <= 366 else UNKNOWN


def daily_export(rows: object, tenant: str, start: str, end: str):
    """Sin límite de preview, PII ni destinos; sólo agregados tenant-scoped."""
    if (type(rows) is not list or not tenant
            or csv_days(start, end) == UNKNOWN):
        return UNKNOWN
    result = []
    for entry in rows:
        if (type(entry) is not dict or set(entry) !=
                {"tenant", "day", "label", "clicks"}
                or not isinstance(entry["tenant"], str)
                or not isinstance(entry["day"], str)
                or not isinstance(entry["label"], str)
                or type(entry["clicks"]) is not int or entry["clicks"] < 0):
            return UNKNOWN
        if csv_days(entry["day"], entry["day"]) == UNKNOWN:
            return UNKNOWN
        if entry["tenant"] == tenant and start <= entry["day"] <= end:
            label = entry["label"]
            if label.startswith(("=", "+", "-", "@")):
                label = "'" + label
            result.append((entry["day"], label, entry["clicks"]))
    return sorted(result)


def set_link_status(link: object, tenant: str, status: str):
    """Un cambio de estado no puede reemplazar token ni métricas."""
    if (type(link) is not dict or set(link) !=
            {"tenant", "token", "status", "clicks"}
            or not tenant or link["tenant"] != tenant
            or not isinstance(link["token"], str) or not link["token"]
            or link["status"] not in ("active", "disabled")
            or status not in ("active", "disabled")
            or type(link["clicks"]) is not int or link["clicks"] < 0):
        return UNKNOWN
    return {**link, "status": status}


def synthetic_smoke(responses: object, version: str, pending: object):
    """Simula GET read-only; NO contacta entorno ni valida SHA remoto."""
    if (type(responses) is not dict or pending is not False
            or not re.fullmatch(r"\d+\.\d+\.\d+", version)
            or set(responses) != {
                "login_count", "version", "vault", "scheduler",
                "distribution", "traffic", "finance", "csv",
            }
            or responses["login_count"] != 1
            or responses["version"] != version):
        return UNKNOWN
    markers = {
        "vault": "Vault",
        "scheduler": "Scheduling schema ready",
        "distribution": "Distribution ready",
        "traffic": "Traffic schema ready",
        "finance": "Finance schema ready",
    }
    for key, marker in markers.items():
        entry = responses.get(key)
        if (type(entry) is not tuple or len(entry) != 2
                or entry[0] != 200 or marker not in entry[1]):
            return UNKNOWN
    if responses["csv"] != (200, "text/csv", "attachment",
                            "date_utc,label,short_link,channel,campaign,status,clicks"):
        return UNKNOWN
    return LOCAL


def operational_attestation(identifier: str, evidence: object = None) -> str:
    """Ningún dato o CI simulado otorga validación productiva real."""
    return UNKNOWN


class BrechasGrindflow11Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {path: (ROOT / path).read_text(encoding="utf-8")
                       for path in FILES}
        cls.rows = parse_rows(cls.sources[MATRIX])

    def test_contrato_principal(self) -> None:
        """AC-01: cinco filas, tests PHP reales + casos sintéticos distintos."""
        self.assertEqual(set(IDS), set(CASES))
        for ident in IDS:
            with self.subTest(row=ident):
                self.assertEqual(
                    inspect_local(ident, self.rows, self.sources)["code"],
                    LOCAL, ident + ": contrato local ausente",
                )

        # SPEC:029: eventos ordenados, inmutables, rate-limit sin consumir budget.
        delivery = {"tenant": "org-fake", "key": "fixed-key", "fenced": True}
        base = {"tenant": "org-fake", "key": "fixed-key", "attempt": 0,
                "type": "started", "error": None}
        first = append_attempt((), delivery, base)
        second = append_attempt(first, delivery,
                                {**base, "type": "retry", "error": "rate_limited"})
        self.assertEqual(first, ((1, "started", 0, None),))
        self.assertEqual(second[-1], (2, "retry", 0, "rate_limited"))
        self.assertEqual(len(first), 1)

        # SPEC:030: versión publicada no es SHA desplegado.
        version = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'",
                            self.sources[VERSION])
        self.assertIsNotNone(version)
        self.assertEqual(operational_attestation("SPEC:030", {
            "version": version.group(1), "ci": "success",
        }), UNKNOWN)

        # SPEC:031: exportar todos los links, aun si el preview fuera de uno.
        rows = [{"tenant": "org-fake", "day": "2026-01-01",
                 "label": f"link-{n}", "clicks": n} for n in range(103)]
        rows += [{"tenant": "other", "day": "2026-01-01",
                  "label": "foreign", "clicks": 900}]
        result = daily_export(rows, "org-fake", "2026-01-01", "2026-01-01")
        self.assertEqual(len(result), 103)
        self.assertEqual(sum(x[2] for x in result), sum(range(103)))
        self.assertNotIn("foreign", str(result))

        # SPEC:032: 366 UTC inclusivos y estado sin pérdida del token/clicks.
        self.assertEqual(csv_days("2024-01-01", "2024-12-31"), 366)
        link = {"tenant": "org-fake", "token": "test-token",
                "status": "active", "clicks": 11}
        disabled = set_link_status(link, "org-fake", "disabled")
        self.assertEqual(disabled["status"], "disabled")
        self.assertEqual((disabled["token"], disabled["clicks"]),
                         (link["token"], link["clicks"]))
        self.assertEqual(set_link_status(disabled, "org-fake", "active"), link)

        # SPEC:033: un login y sólo GETs con markers/CSV, nunca live.
        responses = {"login_count": 1, "version": version.group(1),
                     "vault": (200, "Vault ready"),
                     "scheduler": (200, "Scheduling schema ready"),
                     "distribution": (200, "Distribution ready"),
                     "traffic": (200, "Traffic schema ready"),
                     "finance": (200, "Finance schema ready"),
                     "csv": (200, "text/csv", "attachment",
                             "date_utc,label,short_link,channel,campaign,status,clicks")}
        self.assertEqual(synthetic_smoke(responses, version.group(1), False), LOCAL)
        self.assertEqual(operational_attestation("SPEC:033", responses), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: faltantes/duplicados, cruces tenant, fechas, locks y smoke."""
        for ident in IDS:
            with self.subTest(row=ident):
                missing = dict(self.rows)
                del missing[ident]
                self.assertEqual(inspect_local(ident, missing, self.sources)["code"],
                                 UNKNOWN)
                broken = dict(self.sources)
                broken[CASES[ident][2]] = ""
                self.assertEqual(inspect_local(ident, self.rows, broken)["code"],
                                 UNKNOWN)
                changed = dict(self.rows)
                fields = list(changed[ident])
                fields[2] = "CUBIERTO"
                changed[ident] = tuple(fields)
                self.assertEqual(inspect_local(ident, changed, self.sources)["code"],
                                 UNKNOWN)
        row = next(x for x in self.sources[MATRIX].splitlines()
                   if x.startswith("| SPEC:029 |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_rows(self.sources[MATRIX] + "\n" + row)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_rows(self.sources[MATRIX].replace(row + "\n", "", 1))
        sample = {"tenant": "org-fake", "key": "k", "fenced": False}
        event = {"tenant": "org-fake", "key": "k", "type": "started",
                 "attempt": 1, "error": None}
        self.assertEqual(append_attempt((), sample, event), UNKNOWN)
        sample["fenced"] = True
        for extra in ({"provider_response": "disallowed"}, {"header": "disallowed"}):
            self.assertEqual(append_attempt((), sample, {**event, **extra}),
                             UNKNOWN)
        self.assertEqual(append_attempt((), sample,
                                        {**event, "tenant": "foreign"}), UNKNOWN)
        self.assertEqual(csv_days("2024-01-01", "2025-01-01"), UNKNOWN)
        self.assertEqual(csv_days("2024-02-31", "2024-03-01"), UNKNOWN)
        self.assertEqual(daily_export([{"tenant": "org-fake"}],
                                      "org-fake", "2026-01-01", "2026-01-02"),
                         UNKNOWN)
        link = {"tenant": "org-fake", "token": "t",
                "status": "active", "clicks": 4}
        self.assertEqual(set_link_status(link, "foreign", "disabled"), UNKNOWN)
        self.assertEqual(set_link_status(link, "org-fake", "deleted"), UNKNOWN)
        self.assertEqual(synthetic_smoke({}, "0.1.219", False), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures offline; no llamadas de red, datos o secretos."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|subprocess|urllib)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(set(FILES), set(self.sources))
        for path in FILES:
            with self.subTest(path=path):
                self.assertFalse(Path(path).is_absolute())
                self.assertTrue((ROOT / path).is_file())
        sanitized = daily_export(
            [{"tenant": "org-fake", "day": "2026-01-01",
              "label": "=SUM(1+1)", "clicks": 1}],
            "org-fake", "2026-01-01", "2026-01-01",
        )
        self.assertEqual(sanitized, [("2026-01-01", "'=SUM(1+1)", 1)])
        self.assertNotIn("https://" + "api.", own)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI debe comprobar HEAD; prueba local nunca implica live."""
        for ident in IDS:
            with self.subTest(row=ident):
                row = self.rows[ident]
                self.assertEqual(row[2], "NO_VERIFICABLE")
                self.assertEqual(row[5:8], ("NO_ACREDITADO",) * 3)
                self.assertEqual(inspect_local(ident, self.rows, self.sources),
                                 {"code": LOCAL, "deployment": UNKNOWN,
                                  "production": UNKNOWN})
                for fake in (None, {}, {"ci": "success"},
                             {"hostinger_sha": "a" * 40, "smoke": "PASS"},
                             {"version": "0.1.219", "authenticated": True}):
                    self.assertEqual(operational_attestation(ident, fake), UNKNOWN)
        self.assertIsNone(spec_section("", CASES[IDS[0]][0]))
        self.assertIsNone(spec_section(
            self.sources[SPEC] + "\n" + CASES[IDS[0]][0] + "\n",
            CASES[IDS[0]][0],
        ))


if __name__ == "__main__":
    unittest.main()
