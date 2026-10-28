#!/usr/bin/env python3
"""GrindFlow #720: cinco contratos SPEC:039..043 con datos enteramente sintéticos.

CONTRATO_LOCAL nunca equivale a CI de HEAD, despliegue, acceso a Hostinger,
publicación, consentimiento, ni validación funcional en producción.
"""
from __future__ import annotations

from datetime import datetime, timedelta, time
from pathlib import Path
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/GRINDFLOW-SPEC.md"
BROWSER = "scripts/browser-workflow.sh"
DISTRIBUTION = "tests/Feature/DistributionTest.php"
DEPLOY = "tests/Feature/DeploymentMarkerTest.php"
WEEKLY = "symfony/tests/php/WeeklyRulePreviewTest.php"
FILES = (MATRIX, SPEC, BROWSER, DISTRIBUTION, DEPLOY, WEEKLY)
IDS = tuple(f"SPEC:{n:03d}" for n in range(39, 44))
CLAUSES = {
    "SPEC:039": (470, "## 20. Disposable authenticated browser workflow coverage",
                 ("isolated local/testing tenant", "27 scheduled",
                  "session and CSRF", "A leak detector discards"),
                 BROWSER, ("PASS: real browser login", "PASS: Scheduler 106 ready assets",
                           "PASS: Finance append-only allocation")),
    "SPEC:040": (495, "## 21. Distribution: historial paginado por organización",
                 ("paginado en SQL de 25", "created_at DESC, id DESC",
                  "nunca mezcla tenants"),
                 DISTRIBUTION,
                 ("test_distribution_history_pages_all_tenant_deliveries_with_stable_order_and_filters",
                  "Showing 1–25 of 106", "Showing 101–106 of 106")),
    "SPEC:041": (501, "## 22. Observación del release y regresión real-stack aislada",
                 ("release-only", "exact=false", "commit=null", "no-store",
                  "MariaDB 11.4"),
                 DEPLOY, ("test_public_marker_reports_only_the_human_release_without_remote_sha",
                          "assertExactJson", "'exact' => false", "'commit' => null")),
    "SPEC:042": (515, "## 23. S3 weekly review preview and conservative eligibility",
                 ("active Vault assets only", "at most 30", "blocking reasons",
                  "can_publish=false", "review_only"),
                 WEEKLY, ("testWeeklyPreviewIsTenantSafeAndExplainsEveryBlocker",
                          "total_active_assets", "blocking_reasons", "assertFalse")),
    "SPEC:043": (539, "## 24. S3 derived weekly slots",
                 ("not persisted schedules", "next future occurrence",
                  "IANA timezone", "max_per_day", "can_publish=false"),
                 WEEKLY, ("assertCount(3, $preview['slots'])",
                          "scheduled_at_utc", "capacity", "local_date")),
}
BROWSER_GATES = (
    "PASS: real browser login",
    "PASS: Scheduler 106 ready assets",
    "PASS: search deep media/link",
    "PASS: navigate real calendar page 2",
    "PASS: detach and reattach tracked link",
    "PASS: edit existing Traffic link",
    "PASS: pause/resume link",
    "PASS: authenticated Traffic CSV",
    "PASS: Finance append-only allocation",
    "PASS: reversal and filtered grouped Finance CSV",
)
WEEKDAYS = ("mon", "tue", "wed", "thu", "fri", "sat", "sun")


def parse_rows(raw: str) -> dict[str, tuple[str, ...]]:
    """La matriz ausente, duplicada o ambigua no otorga una acreditación."""
    rows = {}
    for line in raw.splitlines():
        if not any(line.startswith(f"| {ident} |") for ident in IDS):
            continue
        fields = tuple(part.strip() for part in line.strip("|").split("|"))
        if len(fields) != 8 or fields[0] in rows:
            raise ValueError("UNKNOWN: fila duplicada o incompleta")
        rows[fields[0]] = fields
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: faltan filas SPEC")
    return rows


def section(raw: str, heading: str) -> str | None:
    positions = list(re.finditer(r"(?m)^" + re.escape(heading) + r"$", raw))
    if len(positions) != 1:
        return None
    rest = raw[positions[0].end():]
    boundary = re.search(r"(?m)^## ", rest)
    return rest[:boundary.start()] if boundary else rest


def inspect_local(ident: str, rows: dict, sources: dict) -> dict[str, str]:
    """El código solo prueba un contrato local; la identidad remota es UNKNOWN."""
    result = {"code": UNKNOWN, "deployment": UNKNOWN, "production": UNKNOWN}
    if ident not in CLAUSES:
        return result
    row = rows.get(ident)
    if (not isinstance(row, tuple) or len(row) != 8
            or row[2] != "NO_VERIFICABLE"
            or row[5:8] != ("NO_ACREDITADO",) * 3):
        return result
    anchor, heading, phrases, path, markers = CLAUSES[ident]
    if (f"GRINDFLOW-SPEC.md:L{anchor}" not in row[3]
            or f"GRINDFLOW-SPEC.md#L{anchor}" not in row[3]):
        return result
    clause = section(sources.get(SPEC, ""), heading)
    evidence = sources.get(path, "")
    if not clause or not evidence:
        return result
    if not all(phrase in clause for phrase in phrases):
        return result
    if not all(marker in evidence for marker in markers):
        return result
    result["code"] = LOCAL
    return result


def browser_contract(payload: object) -> str:
    """SPEC:039: invariantes E2E reproducibles, sin ejecutar Chromium ni red."""
    if type(payload) is not dict or set(payload) != {
        "environment", "authenticated", "media", "scheduled", "links",
        "gates", "removed_bootstrap", "clean_failure_artifact", "finance_immutable",
    }:
        return UNKNOWN
    gates = payload["gates"]
    if (payload["environment"] != "testing"
            or payload["authenticated"] is not True
            or type(payload["media"]) is not int or payload["media"] < 106
            or type(payload["scheduled"]) is not int or payload["scheduled"] != 27
            or type(payload["links"]) is not int or payload["links"] < 1
            or type(gates) is not tuple or gates != BROWSER_GATES
            or payload["removed_bootstrap"] is not True
            or payload["clean_failure_artifact"] is not True
            or payload["finance_immutable"] is not True):
        return UNKNOWN
    return LOCAL


def history_page(records: object, tenant: str, page: int,
                 status: str | None = None):
    """SPEC:040: paginar 25 y filtrar tenant antes de contar u ordenar."""
    allowed = {"queued", "delivered", "failed", "retry"}
    if (type(records) is not list or type(tenant) is not str or not tenant
            or type(page) is not int or page < 1
            or status is not None and status not in allowed):
        return UNKNOWN
    for record in records:
        if (type(record) is not tuple or len(record) != 4
                or any(type(v) is not str or not v for v in record)
                or record[3] not in allowed
                or not re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z",
                                    record[1])):
            return UNKNOWN
    selected = sorted(
        (r for r in records if r[0] == tenant and (status is None or r[3] == status)),
        key=lambda r: (r[1], r[2]), reverse=True,
    )
    start = (page - 1) * 25
    return {"total": len(selected), "ids": tuple(r[2] for r in selected[start:start + 25]),
            "previous": page - 1 if page > 1 else None,
            "next": page + 1 if start + 25 < len(selected) else None}


def deployment_marker(payload: object, headers: object) -> dict[str, str]:
    """SPEC:041: release observado no es commit exacto ni smoke productivo."""
    result = {"code": UNKNOWN, "exact_sha": UNKNOWN, "production": UNKNOWN}
    if (type(payload) is not dict or type(headers) is not dict
            or set(payload) != {"version", "exact", "commit", "source"}
            or type(payload["version"]) is not str
            or re.fullmatch(r"\d+\.\d+\.\d+", payload["version"]) is None
            or payload["exact"] is not False or payload["commit"] is not None
            or payload["source"] != "release-only"
            or type(headers.get("Cache-Control")) is not str
            or "no-store" not in headers["Cache-Control"]):
        return result
    result["code"] = LOCAL
    return result


def valid_rule(rule: object) -> bool:
    if type(rule) is not dict or set(rule) != {
        "timezone", "weekdays", "local_time", "max_per_day"
    }:
        return False
    if (type(rule["weekdays"]) is not tuple or not rule["weekdays"]
            or len(rule["weekdays"]) != len(set(rule["weekdays"]))
            or any(day not in WEEKDAYS for day in rule["weekdays"])
            or type(rule["max_per_day"]) is not int
            or not 1 <= rule["max_per_day"] <= 30
            or type(rule["local_time"]) is not str
            or re.fullmatch(r"(?:[01]\d|2[0-3]):[0-5]\d",
                            rule["local_time"]) is None
            or type(rule["timezone"]) is not str):
        return False
    try:
        ZoneInfo(rule["timezone"])
    except (ValueError, ZoneInfoNotFoundError):
        return False
    return True


def weekly_preview(assets: object, tenant: str, active_member: object,
                   rule: object):
    """SPEC:042: 30 visibles, total activo completo, blockers antes de eligible."""
    if (type(assets) is not list or not tenant or active_member is not True
            or rule is not None and not valid_rule(rule)):
        return UNKNOWN
    required = {"tenant", "id", "state", "scope", "reviewed", "authorized"}
    for asset in assets:
        if (type(asset) is not dict or set(asset) != required
                or type(asset["tenant"]) is not str
                or type(asset["id"]) is not str or not asset["id"]
                or asset["state"] not in ("active", "trash")
                or asset["scope"] not in ("unclassified", "internal_only", "public")
                or type(asset["reviewed"]) is not bool
                or type(asset["authorized"]) is not bool):
            return UNKNOWN
    visible = [a for a in assets if a["tenant"] == tenant and a["state"] == "active"]
    entries = []
    for asset in visible[:30]:
        blockers = []
        if rule is None:
            blockers.append("weekly_rule_missing")
        if asset["scope"] == "unclassified":
            blockers.append("classification_missing")
        elif asset["scope"] == "internal_only":
            blockers.append("internal_only")
        if not asset["reviewed"]:
            blockers.append("content_review_required")
        if not asset["authorized"]:
            blockers.append("distribution_authorization_missing")
        entries.append({"id": asset["id"], "eligible": not blockers,
                        "blocking_reasons": tuple(blockers)})
    return {"total_active_assets": len(visible), "assets": tuple(entries),
            "mode": "review_only", "can_publish": False}


def weekly_slots(rule: object, now: object):
    """SPEC:043: una próxima fecha por weekday, sin escrituras ni reservas."""
    if rule is None:
        return ()
    if (not valid_rule(rule) or not isinstance(now, datetime)
            or now.tzinfo is None or now.utcoffset() is None):
        return UNKNOWN
    tz = ZoneInfo(rule["timezone"])
    current = now.astimezone(tz)
    hour, minute = map(int, rule["local_time"].split(":"))
    slots = []
    for weekday in rule["weekdays"]:
        step = (WEEKDAYS.index(weekday) - current.weekday()) % 7
        day = current.date() + timedelta(days=step)
        future = datetime.combine(day, time(hour, minute), tzinfo=tz)
        if future <= current:
            future += timedelta(days=7)
        utc = future.astimezone(ZoneInfo("UTC"))
        slots.append({
            "weekday": weekday, "local_date": future.date().isoformat(),
            "local_time": rule["local_time"], "timezone": rule["timezone"],
            "scheduled_at_utc": utc.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "capacity": rule["max_per_day"],
        })
    return tuple(sorted(slots, key=lambda slot: slot["scheduled_at_utc"]))


def operational_attestation(ident: str, evidence: object = None) -> str:
    """Nunca convertir test sintético, release o CI en validación de Hostinger."""
    return UNKNOWN


class BrechasGrindflow13Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {path: (ROOT / path).read_text(encoding="utf-8")
                       for path in FILES}
        cls.rows = parse_rows(cls.sources[MATRIX])

    def test_contrato_principal(self) -> None:
        """AC-01: cinco referencias contractuales y cinco comportamientos."""
        self.assertEqual(set(IDS), set(CLAUSES))
        for ident in IDS:
            with self.subTest(spec=ident):
                self.assertEqual(inspect_local(ident, self.rows, self.sources)["code"],
                                 LOCAL, ident)
        # SPEC:039: recorrido Chromium autenticado, fixture descartable.
        browser = {"environment": "testing", "authenticated": True, "media": 106,
                   "scheduled": 27, "links": 3, "gates": BROWSER_GATES,
                   "removed_bootstrap": True, "clean_failure_artifact": True,
                   "finance_immutable": True}
        self.assertEqual(browser_contract(browser), LOCAL)

        # SPEC:040: 106 filas reales del filtro, paginadas sin límite 100.
        records = [("tenant-a", "2026-10-01T00:00:00Z",
                    f"synthetic-{n:03d}", "failed") for n in range(106)]
        records += [("tenant-b", "2026-10-02T00:00:00Z", "foreign", "failed")]
        first = history_page(records, "tenant-a", 1, "failed")
        second = history_page(records, "tenant-a", 2, "failed")
        last = history_page(records, "tenant-a", 5, "failed")
        self.assertEqual((first["total"], len(first["ids"]), first["next"]),
                         (106, 25, 2))
        self.assertEqual((second["previous"], len(second["ids"])), (1, 25))
        self.assertEqual((len(last["ids"]), last["next"]), (6, None))
        self.assertEqual(first["ids"][0], "synthetic-105")
        self.assertNotIn("foreign", str((first, second, last)))

        # SPEC:041: marker público anuncia release, nunca SHA ni test live.
        marker = deployment_marker(
            {"version": "0.1.219", "exact": False, "commit": None,
             "source": "release-only"}, {"Cache-Control": "no-store"})
        self.assertEqual(marker["code"], LOCAL)
        self.assertEqual(marker["exact_sha"], UNKNOWN)

        # SPEC:042: visibilidad limitada, sin filtraciones tenant ni permisos de publicar.
        rule = {"timezone": "America/Bogota", "weekdays": ("mon", "wed", "fri"),
                "local_time": "10:30", "max_per_day": 2}
        assets = [
            {"tenant": "tenant-a", "id": f"fake-{n}", "state": "active",
             "scope": "public", "reviewed": True, "authorized": True}
            for n in range(34)
        ]
        assets += [{"tenant": "tenant-b", "id": "foreign", "state": "active",
                    "scope": "public", "reviewed": True, "authorized": True},
                   {"tenant": "tenant-a", "id": "trashed", "state": "trash",
                    "scope": "public", "reviewed": True, "authorized": True},
                   {"tenant": "tenant-a", "id": "needs-review", "state": "active",
                    "scope": "unclassified", "reviewed": False, "authorized": False}]
        preview = weekly_preview(assets, "tenant-a", True, rule)
        self.assertEqual(preview["total_active_assets"], 35)
        self.assertEqual(len(preview["assets"]), 30)
        self.assertTrue(preview["assets"][0]["eligible"])
        self.assertFalse(preview["can_publish"])
        self.assertEqual(preview["mode"], "review_only")
        blocked = weekly_preview(assets[-1:], "tenant-a", True, rule)["assets"][0]
        self.assertEqual(set(blocked["blocking_reasons"]),
                         {"classification_missing", "content_review_required",
                          "distribution_authorization_missing"})
        self.assertFalse(blocked["eligible"])

        # SPEC:043: la ocurrencia ya pasada del miércoles salta 7 días.
        now = datetime(2026, 10, 28, 11, 0, tzinfo=ZoneInfo("America/Bogota"))
        slots = weekly_slots(rule, now)
        self.assertEqual(len(slots), 3)
        self.assertEqual({s["weekday"] for s in slots}, set(rule["weekdays"]))
        self.assertEqual(next(s for s in slots if s["weekday"] == "wed")[
            "local_date"], "2026-11-04")
        self.assertTrue(all(s["capacity"] == 2 for s in slots))
        self.assertTrue(all(s["scheduled_at_utc"].endswith("15:30:00Z")
                            for s in slots))

    def test_falla_cerrado(self) -> None:
        """AC-02: sin fuentes fiables, acceso u orden no hay éxito implícito."""
        for ident in IDS:
            with self.subTest(spec=ident):
                missing = dict(self.rows)
                missing.pop(ident)
                self.assertEqual(inspect_local(ident, missing, self.sources)["code"],
                                 UNKNOWN)
                broken = dict(self.sources)
                broken[CLAUSES[ident][3]] = ""
                self.assertEqual(inspect_local(ident, self.rows, broken)["code"],
                                 UNKNOWN)
                changed = dict(self.rows)
                cells = list(changed[ident])
                cells[2] = "CUBIERTO"
                changed[ident] = tuple(cells)
                self.assertEqual(inspect_local(ident, changed, self.sources)["code"],
                                 UNKNOWN)
        original = next(line for line in self.sources[MATRIX].splitlines()
                        if line.startswith("| SPEC:039 |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_rows(self.sources[MATRIX] + "\n" + original)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_rows(self.sources[MATRIX].replace(original + "\n", "", 1))

        valid_browser = {"environment": "testing", "authenticated": True,
                         "media": 106, "scheduled": 27, "links": 1,
                         "gates": BROWSER_GATES, "removed_bootstrap": True,
                         "clean_failure_artifact": True, "finance_immutable": True}
        for change in ({"environment": "production"}, {"authenticated": False},
                       {"gates": BROWSER_GATES[:-1]},
                       {"clean_failure_artifact": False},
                       {"media": 100}, {"scheduled": 26}):
            self.assertEqual(browser_contract({**valid_browser, **change}), UNKNOWN)

        self.assertEqual(history_page([("tenant-a",)], "tenant-a", 1), UNKNOWN)
        self.assertEqual(history_page([], "tenant-a", 0), UNKNOWN)
        self.assertEqual(history_page([], "tenant-a", 1, "arbitrary"), UNKNOWN)
        self.assertEqual(deployment_marker(
            {"version": "0.1.219", "exact": True, "commit": "a" * 40,
             "source": "release-only"}, {"Cache-Control": "no-store"})["code"],
            UNKNOWN)
        self.assertEqual(deployment_marker(
            {"version": "0.1.219", "exact": False, "commit": None,
             "source": "release-only"}, {})["code"], UNKNOWN)

        good_asset = {"tenant": "tenant-a", "id": "synthetic", "state": "active",
                      "scope": "public", "reviewed": True, "authorized": True}
        self.assertEqual(weekly_preview([good_asset], "tenant-a", False, None),
                         UNKNOWN)
        self.assertEqual(weekly_preview([{"tenant": "tenant-a"}],
                                        "tenant-a", True, None), UNKNOWN)
        self.assertEqual(weekly_preview([good_asset], "tenant-a", True, None)[
            "assets"][0]["eligible"], False)
        self.assertEqual(weekly_preview([good_asset], "tenant-a", True, None)[
            "assets"][0]["blocking_reasons"], ("weekly_rule_missing",))
        self.assertEqual(weekly_slots(None, datetime.now(ZoneInfo("UTC"))), ())
        self.assertEqual(weekly_slots({"timezone": "Bad/Zone"},
                                      datetime.now(ZoneInfo("UTC"))), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo fixtures falsas y contratos de archivos versionados."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|socket|httpx|urllib|subprocess)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(set(FILES), set(self.sources))
        for path in FILES:
            self.assertFalse(Path(path).is_absolute())
            self.assertTrue((ROOT / path).is_file())
        self.assertEqual(weekly_preview([], "tenant-a", True, None)[
            "can_publish"], False)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: cada SPEC permanece UNKNOWN para despliegue y producción."""
        for ident in IDS:
            with self.subTest(spec=ident):
                inspected = inspect_local(ident, self.rows, self.sources)
                self.assertEqual(inspected["deployment"], UNKNOWN)
                self.assertEqual(inspected["production"], UNKNOWN)
                self.assertEqual(operational_attestation(ident, inspected), UNKNOWN)
                altered = dict(self.rows)
                fields = list(altered[ident])
                anchor = CLAUSES[ident][0]
                fields[3] = fields[3].replace(f"L{anchor}", "L999")
                altered[ident] = tuple(fields)
                self.assertEqual(inspect_local(ident, altered, self.sources)[
                    "code"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
