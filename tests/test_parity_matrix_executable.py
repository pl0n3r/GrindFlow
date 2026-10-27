#!/usr/bin/env python3
"""GF-MIG-003: execute the Laravel/Symfony parity inventory offline."""
from __future__ import annotations

from copy import deepcopy
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/PARITY-MATRIX-EXECUTABLE.md"
SOURCE = ROOT / "docs/STACK-TRANSITION-SYMFONY.md"
MARKER = "<!-- gf-mig-003-parity-json:v1 -->"
CODE = "RUTA_Y_PRUEBA_EN_CODIGO"
DEFERRED = "DIFERIDA"


def unique(pairs: list[tuple[str, object]]) -> dict:
    output = {}
    for key, value in pairs:
        if key in output:
            raise ValueError("duplicate JSON key: " + key)
        output[key] = value
    return output


def load_manifest(raw: str) -> dict:
    if raw.count(MARKER) != 1:
        raise ValueError("matrix marker not unique")
    match = re.search(re.escape(MARKER) + r"\s*~~~json\n(.*?)\n~~~", raw, re.DOTALL)
    if match is None or len(match.group(1).encode("utf-8")) > 65536:
        raise ValueError("missing or oversized matrix")
    value = json.loads(match.group(1), object_pairs_hook=unique)
    if not isinstance(value, dict):
        raise ValueError("manifest must be an object")
    return value


def domains(text: str) -> list[str]:
    first = "## 2. Mapa de activos: portar comportamiento, no copiar framework"
    last = "**Regla migratoria fundamental:**"
    if text.count(first) != 1 or last not in text.split(first, 1)[1]:
        raise ValueError("missing canonical domain table")
    part = text.split(first, 1)[1].split(last, 1)[0]
    result = [
        line.split("|")[1].strip() for line in part.splitlines()
        if line.startswith("| ") and line.split("|")[1].strip() not in ("Dominio", "---")
    ]
    if not result or len(set(result)) != len(result):
        raise ValueError("duplicate or missing domains")
    return result


def file_text(name: str) -> str:
    if (
        not isinstance(name, str)
        or re.fullmatch(r"[A-Za-z0-9_./-]+", name) is None
        or name.startswith("/")
        or any(part in (".", "..") for part in name.split("/"))
    ):
        raise ValueError("unsafe path")
    path = ROOT / name
    if not path.is_file():
        raise ValueError("missing evidence file: " + name)
    return path.read_text(encoding="utf-8")


def validate(data: dict, source: str) -> None:
    if set(data) != {"contract", "source", "capabilities"}:
        raise ValueError("invalid manifest envelope")
    if data["contract"] != "GF-MIG-003-FUNCTIONAL-PARITY-OFFLINE-V1":
        raise ValueError("invalid matrix contract")
    if data["source"] != "docs/STACK-TRANSITION-SYMFONY.md":
        raise ValueError("invalid source reference")
    rows = data["capabilities"]
    if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
        raise ValueError("invalid rows")
    if [row.get("domain") for row in rows] != domains(source):
        raise ValueError("canonical domains differ from executable matrix")
    for row in rows:
        domain = row["domain"]
        if set(row) != {"domain", "laravel", "symfony", "evidence", "status", "deferred_reason"}:
            raise ValueError("invalid row fields")
        if row["status"] not in (CODE, DEFERRED):
            raise ValueError("unknown parity status: " + domain)
        evidence = row["evidence"]
        if not isinstance(evidence, list) or any(not isinstance(e, str) for e in evidence):
            raise ValueError("invalid evidence: " + domain)
        if len(evidence) != len(set(evidence)):
            raise ValueError("duplicate evidence: " + domain)
        for path in evidence:
            file_text(path)
        for name in ("laravel", "symfony"):
            info = row[name]
            required = {"route", "definition", "marker", "parent"} if name == "laravel" else {"route", "definition"}
            if not isinstance(info, dict) or set(info) != required:
                raise ValueError("invalid route fields: " + domain)
            route, path = info["route"], info["definition"]
            if (route is None) != (path is None):
                raise ValueError("route and source mismatch: " + domain)
            if route is None:
                if name == "laravel" and (info["marker"] is not None or info["parent"] is not None):
                    raise ValueError("orphan Laravel marker: " + domain)
                continue
            if not isinstance(route, str) or not route.startswith("/") or len(route) > 200:
                raise ValueError("invalid route: " + domain)
            code = file_text(path)
            if name == "symfony":
                if re.search(r"['\"]" + re.escape(route) + r"['\"]", code) is None:
                    raise ValueError("missing Symfony route: " + domain)
            else:
                marker, parent = info["marker"], info["parent"]
                if not isinstance(marker, str) or marker not in code:
                    raise ValueError("missing Laravel route: " + domain)
                if parent is not None and (not isinstance(parent, str) or parent not in code):
                    raise ValueError("missing Laravel parent route: " + domain)
                tail = marker.split("'")[1]
                if route != ("/organizations/{organizationId}" + tail if parent else tail):
                    raise ValueError("mismatched Laravel route: " + domain)
        if row["status"] == CODE:
            if row["deferred_reason"] is not None:
                raise ValueError("coded row has deferred reason: " + domain)
            if not row["laravel"]["route"] or not row["symfony"]["route"]:
                raise ValueError("coded row missing route: " + domain)
            if not evidence or not any(
                path.startswith("symfony/tests/php/") and path.endswith("Test.php") for path in evidence
            ):
                raise ValueError("missing code evidence: " + domain)
        elif not isinstance(row["deferred_reason"], str) or len(row["deferred_reason"].strip()) < 30:
            raise ValueError("deferred row needs reason: " + domain)


class ParityMatrixExecutableTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.raw = DOC.read_text(encoding="utf-8")
        cls.source = SOURCE.read_text(encoding="utf-8")
        cls.manifest = load_manifest(cls.raw)

    def test_canonical_rows_and_routes_exist(self) -> None:
        validate(self.manifest, self.source)
        self.assertEqual(len(self.manifest["capabilities"]), 10)

    def test_missing_evidence_fails_closed(self) -> None:
        changed = deepcopy(self.manifest)
        item = next(row for row in changed["capabilities"] if row["status"] == CODE)
        item["evidence"] = []
        with self.assertRaisesRegex(ValueError, "evidence"):
            validate(changed, self.source)
        changed = deepcopy(self.manifest)
        item = next(row for row in changed["capabilities"] if row["status"] == DEFERRED)
        item["status"], item["deferred_reason"] = CODE, None
        with self.assertRaises(ValueError):
            validate(changed, self.source)
        changed = deepcopy(self.manifest)
        item = next(row for row in changed["capabilities"] if row["status"] == DEFERRED)
        item["deferred_reason"] = ""
        with self.assertRaisesRegex(ValueError, "reason"):
            validate(changed, self.source)

    def test_bad_routes_status_and_inventory_fail_closed(self) -> None:
        changed = deepcopy(self.manifest)
        changed["capabilities"][0]["symfony"]["route"] = "/nonexistent"
        with self.assertRaisesRegex(ValueError, "Symfony route"):
            validate(changed, self.source)
        changed = deepcopy(self.manifest)
        changed["capabilities"][0]["status"] = "PRODUCTION_GREEN"
        with self.assertRaisesRegex(ValueError, "status"):
            validate(changed, self.source)
        changed = deepcopy(self.manifest)
        changed["capabilities"].pop()
        with self.assertRaisesRegex(ValueError, "canonical"):
            validate(changed, self.source)
        with self.assertRaisesRegex(ValueError, "duplicate JSON key"):
            load_manifest(MARKER + '\n~~~json\n{"x":1,"x":2}\n~~~')

    def test_matrix_is_offline_and_contains_no_secrets(self) -> None:
        validate(self.manifest, self.source)
        self.assertNotRegex(self.raw, r"(?i)-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(self.raw, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertNotRegex(self.raw, r"(?i)(?:api_key|password|access_token)\s*[:=]\s*\S+")
        self.assertNotIn("https://api.", self.raw)
        for item in self.manifest["capabilities"]:
            self.assertEqual(item["laravel"]["definition"], "routes/web.php" if item["laravel"]["route"] else None)
            if item["symfony"]["definition"]:
                self.assertTrue(item["symfony"]["definition"].startswith("symfony/src/Http/Controller/"))
        test_source = Path(__file__).read_text(encoding="utf-8")
        for module, suffix in (("requests", ".get("), ("url", "open("),
                               ("urllib", ".request"), ("subprocess", ".run(")):
            self.assertNotIn(module + suffix, test_source)


if __name__ == "__main__":
    unittest.main()
