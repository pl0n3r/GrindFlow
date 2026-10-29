#!/usr/bin/env python3
"""Issue #721: SPEC:044..046 y GF-ARCH-001..002 con fixtures sintéticos.

CONTRATO_LOCAL prueba invariantes de código, nunca CI exact-HEAD, hosting,
paridad completa, despliegue ni producción. UNKNOWN falla cerrado.
"""
from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/GRINDFLOW-SPEC.md"
STACK = "docs/STACK-TRANSITION-SYMFONY.md"
RUNTIME = "symfony/src/Infrastructure/Runtime/RuntimeReadiness.php"
HEALTH = "symfony/src/Http/Controller/HealthController.php"
RUNTIME_TEST = "symfony/tests/php/RuntimeReadinessTest.php"
COLLECTION = "symfony/src/Library/AssetCollectionApplication.php"
COLLECTION_TEST = "symfony/tests/php/LibraryCollectionsTest.php"
MIGRATION = "symfony/migrations/Version20261009233000.php"
VALIDITY = "symfony/src/Scheduling/ScheduleDraftApplicationService.php"
VALIDITY_TEST = "symfony/tests/php/VaultValidityTest.php"
JOURNEY = "symfony/tests/php/MvpVaultToDistributionJourneyTest.php"
FILES = (MATRIX, SPEC, STACK, RUNTIME, HEALTH, RUNTIME_TEST,
         COLLECTION, COLLECTION_TEST, MIGRATION, VALIDITY, VALIDITY_TEST,
         JOURNEY)
IDS = ("SPEC:044", "SPEC:045", "SPEC:046",
       "STACK:GF-ARCH-001", "STACK:GF-ARCH-002")
CLAUSES = {
    "SPEC:044": (SPEC, 557, "## 25. Safe Symfony runtime readiness",
                 ("symfony-mariadb-v1", "PHP >= 8.3", "HTTP 503")),
    "SPEC:045": (SPEC, 576, "### Library — relaciones lógicas",
                 ("múltiples colecciones", "storage_key", "format")),
    "SPEC:046": (SPEC, 582, "### Library — vigencia UTC",
                 ("valid_from_at", "valid_until_at", "asset_expired")),
    "STACK:GF-ARCH-001": (STACK, 102, "### GF-ARCH-001",
                          ("Symfony 7.4", "Doctrine/MariaDB", "Twig/SSR")),
    "STACK:GF-ARCH-002": (STACK, 207, "### GF-ARCH-002",
                          ("cross-tenant", "un único dueño de escritura",
                           "schema:update --force")),
}
EVIDENCE = {
    "SPEC:044": {
        RUNTIME: ("MINIMUM_PHP_VERSION_ID = 80300",
                  "MAXIMUM_PHP_VERSION_ID = 90000",
                  "symfony-mariadb-v1", "'pdo_mysql'", "publicSummary"),
        HEALTH: ("Route('/health'", "'degraded'", "503", "no-store"),
        RUNTIME_TEST: ("testUnsupportedPhpVersionsFailClosed",
                       "testMissingRequiredExtensionFailsClosed"),
    },
    "SPEC:045": {
        COLLECTION: ("organization_id", "gf_vault_collection_assets",
                     "gf_vault_asset_variants"),
        COLLECTION_TEST: ("testAssetInMultipleCollectionsKeepsOnePhysicalBlob",
                          "testVariantsHaveTypedMasterAndCanBeListedWithoutMutation",
                          "storage_key"),
        MIGRATION: ("FOREIGN KEY (organization_id, asset_id)",
                    "FOREIGN KEY (organization_id, master_asset_id)",
                    "FOREIGN KEY (organization_id, variant_asset_id)"),
    },
    "SPEC:046": {
        VALIDITY: ("validityState", "not_yet_valid", "expired",
                   "asset_not_yet_valid", "asset_expired", "FOR UPDATE"),
        VALIDITY_TEST: ("testExpiredAssetRemainsVisibleWithStableValidityState",
                        "testPreviewAndDraftFailClosedOutsideValidityWindow",
                        "not_yet_valid", "expired"),
    },
    "STACK:GF-ARCH-001": {
        RUNTIME: ("symfony-mariadb-v1", "MINIMUM_PHP_VERSION_ID"),
        HEALTH: ("Route('/health'", "s0-preview"),
    },
    "STACK:GF-ARCH-002": {
        COLLECTION: ("WHERE link.organization_id = :organization",
                     "INNER JOIN gf_vault_assets"),
        MIGRATION: ("FOREIGN KEY (organization_id, asset_id)",
                    "FOREIGN KEY (organization_id, variant_asset_id)"),
        JOURNEY: ("foreignAsset", "organization_id"),
    },
}
GAPS = {
    "STACK:GF-ARCH-001": (
        "UNKNOWN: falta paridad integral y PHP 8.5 observado en Hostinger"),
    "STACK:GF-ARCH-002": (
        "UNKNOWN: falta inventario completo de tablas/IDs/triggers, "
        "migración reversible y paridad de tenencia coexistente"),
}


def parse_rows(raw: str) -> dict[str, tuple[str, ...]]:
    """IDs ambiguos, ausentes o filas que afirman cobertura fallan cerrado."""
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        if not line.startswith("| "):
            continue
        fields = tuple(part.strip() for part in line.strip("|").split("|"))
        if not fields or fields[0] not in IDS:
            continue
        if len(fields) != 8 or fields[0] in found:
            raise ValueError("UNKNOWN: fila duplicada o incompleta")
        found[fields[0]] = fields
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: cobertura de IDs incompleta")
    return found


def section(raw: str, heading: str) -> str | None:
    """No aceptar dos secciones homónimas o encabezados aproximados."""
    pattern = r"(?m)^" + re.escape(heading) + r"[^\n]*$"
    hits = list(re.finditer(pattern, raw))
    if len(hits) != 1:
        return None
    remainder = raw[hits[0].end():]
    level = len(heading) - len(heading.lstrip("#"))
    boundary = re.search(r"(?m)^#{2," + str(level) + r"} ", remainder)
    return remainder[:boundary.start()] if boundary else remainder


def inspect(ident: str, rows: dict, sources: dict) -> dict[str, str]:
    """Distinguir evidencias estáticas de autoridad operacional externa."""
    result = {"code": UNKNOWN, "production": UNKNOWN, "cutover": UNKNOWN}
    if ident not in CLAUSES or ident not in rows:
        return result
    cells = rows[ident]
    if (not isinstance(cells, tuple) or len(cells) != 8
            or cells[2] != "NO_VERIFICABLE"
            or cells[5:] != ("NO_ACREDITADO",) * 3):
        return result
    doc, anchor, heading, clauses = CLAUSES[ident]
    if (not any(token in cells[3] for token in
                (doc.rsplit("/", 1)[-1] + ":L" + str(anchor),
                 doc.rsplit("/", 1)[-1] + "#L" + str(anchor)))):
        return result
    content = section(sources.get(doc, ""), heading)
    if content is None or not all(s in content for s in clauses):
        return result
    for path, markers in EVIDENCE[ident].items():
        data = sources.get(path, "")
        if not data or not all(value in data for value in markers):
            return result
    # El stack de coexistencia no demuestra PHP en hosting ni paridad global.
    if ident not in GAPS:
        result["code"] = LOCAL
    return result


def runtime_contract(version: object, extensions: object):
    """SPEC:044: simulación de compatibilidad, sin consultar servidor."""
    required = {"ctype", "iconv", "pdo", "pdo_mysql"}
    if (type(version) is not int or type(extensions) not in (set, tuple, list)
            or any(type(x) is not str for x in extensions)):
        return UNKNOWN
    return (80300 <= version < 90000
            and required.issubset({x.lower() for x in extensions}))


def asset_relationship(asset: object, collections: object, variants: object):
    """SPEC:045: referencias lógicas tenant-bound, no copies ni publish."""
    if (type(asset) is not tuple or len(asset) != 3
            or any(type(s) is not str or not s for s in asset)
            or type(collections) is not list or type(variants) is not list):
        return UNKNOWN
    org, identity, blob = asset
    if not collections or len(set(collections)) != len(collections):
        return UNKNOWN
    if any(type(c) is not tuple or len(c) != 2
           or c[0] != org or not isinstance(c[1], str) or not c[1]
           for c in collections):
        return UNKNOWN
    if any(type(v) is not tuple or len(v) != 4
           or v[0] != org or v[1] != identity or v[2] == identity
           or v[3] not in ("format", "network", "campaign")
           for v in variants):
        return UNKNOWN
    if len({v[2] for v in variants}) != len(variants):
        return UNKNOWN
    return {"asset": identity, "storage_keys": (blob,),
            "collections": len(collections), "variants": len(variants),
            "can_publish": False}


def validity_state(start: object, end: object, now: object) -> str:
    """SPEC:046: intervalo UTC [inicio, fin) sin inferir autorización."""
    def parse(value: object):
        if (type(value) is not str or
                re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", value) is None):
            return None
        try:
            return datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(
                tzinfo=timezone.utc)
        except ValueError:
            return None
    instant = parse(now)
    lower = parse(start) if start is not None else None
    upper = parse(end) if end is not None else None
    if (instant is None or start is not None and lower is None
            or end is not None and upper is None
            or lower is not None and upper is not None and upper <= lower):
        return UNKNOWN
    if lower is not None and instant < lower:
        return "not_yet_valid"
    if upper is not None and instant >= upper:
        return "expired"
    return "valid"


def operational_attestation(ident: str, evidence: object = None) -> str:
    """Ninguna fixture implica PHP observado, D-043, cutover o paridad live."""
    return UNKNOWN


class BrechasGrindflow14Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {path: (ROOT / path).read_text(encoding="utf-8")
                       for path in FILES}
        cls.rows = parse_rows(cls.sources[MATRIX])

    def test_contrato_principal(self) -> None:
        """AC-01: cinco cláusulas contrastadas o brecha UNKNOWN explícita."""
        self.assertEqual(set(IDS), set(CLAUSES))
        for ident in IDS:
            with self.subTest(ident=ident):
                result = inspect(ident, self.rows, self.sources)
                self.assertEqual(result["code"], UNKNOWN if ident in GAPS else LOCAL)
                self.assertEqual(result["production"], UNKNOWN)
                if ident in GAPS:
                    self.assertIn("falta", GAPS[ident])
        extensions = {"ctype", "iconv", "PDO", "pdo_mysql"}
        self.assertTrue(runtime_contract(80300, extensions))
        self.assertTrue(runtime_contract(89999, extensions))
        links = asset_relationship(("tenant-a", "asset-a", "blob-one"),
                                   [("tenant-a", "collection-1"),
                                    ("tenant-a", "collection-2")],
                                   [("tenant-a", "asset-a", "variant-b", "format")])
        self.assertEqual(links["storage_keys"], ("blob-one",))
        self.assertEqual((links["collections"], links["can_publish"]), (2, False))
        since, until = "2030-01-01T00:00:00Z", "2030-01-02T00:00:00Z"
        self.assertEqual(validity_state(since, until, since), "valid")
        self.assertEqual(validity_state(since, until, until), "expired")
        self.assertEqual(validity_state(since, until, "2029-12-31T23:59:59Z"),
                         "not_yet_valid")
        self.assertEqual(validity_state(None, None, since), "valid")

    def test_falla_cerrado(self) -> None:
        """AC-02: fuentes, tenants y límites ambiguos no dan éxito implícito."""
        for ident in IDS:
            with self.subTest(ident=ident):
                missing = dict(self.rows)
                missing.pop(ident)
                self.assertEqual(inspect(ident, missing, self.sources)["code"], UNKNOWN)
                damaged = dict(self.sources)
                damaged[next(iter(EVIDENCE[ident]))] = ""
                self.assertEqual(inspect(ident, self.rows, damaged)["code"], UNKNOWN)
                changed = dict(self.rows)
                cells = list(changed[ident])
                cells[2] = "CUBIERTO"
                changed[ident] = tuple(cells)
                self.assertEqual(inspect(ident, changed, self.sources)["code"], UNKNOWN)
        line = next(x for x in self.sources[MATRIX].splitlines()
                    if x.startswith("| SPEC:044 |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_rows(self.sources[MATRIX] + "\n" + line)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_rows(self.sources[MATRIX].replace(line + "\n", "", 1))
        for version in (80299, 90000):
            self.assertFalse(runtime_contract(version, {"ctype", "iconv", "pdo", "pdo_mysql"}))
        self.assertFalse(runtime_contract(80500, {"ctype", "iconv", "pdo"}))
        self.assertEqual(runtime_contract("80500", []), UNKNOWN)
        self.assertEqual(asset_relationship(("tenant-a", "asset-a", "blob-a"),
                         [("tenant-b", "other")], []), UNKNOWN)
        self.assertEqual(asset_relationship(("tenant-a", "asset-a", "blob-a"),
                         [("tenant-a", "c")],
                         [("tenant-a", "asset-a", "asset-a", "format")]), UNKNOWN)
        self.assertEqual(validity_state("2030-01-02T00:00:00Z",
                         "2030-01-01T00:00:00Z",
                         "2030-01-01T00:00:00Z"), UNKNOWN)
        self.assertEqual(validity_state(None, None, "not-a-timestamp"), UNKNOWN)
        self.assertEqual(validity_state(None, None,
                         "2030-01-01T00:00:00+00:00"), UNKNOWN)
        self.assertEqual(asset_relationship(("tenant-a", "asset-a", "blob-a"),
                         [("tenant-a", "c"), ("tenant-a", "c")], []), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: lectura local de fuentes y fixtures sin red ni secretos."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|socket|httpx|urllib|subprocess)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(set(FILES), set(self.sources))
        for path in FILES:
            self.assertTrue((ROOT / path).is_file())
            self.assertFalse(Path(path).is_absolute())

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: cinco filas carecen de attestación externa verificable."""
        for ident in IDS:
            self.assertEqual(operational_attestation(ident), UNKNOWN)
            self.assertEqual(operational_attestation(ident, {"ci": "success"}), UNKNOWN)
            self.assertEqual(inspect(ident, self.rows, self.sources)["cutover"], UNKNOWN)
        self.assertEqual(set(GAPS), {"STACK:GF-ARCH-001", "STACK:GF-ARCH-002"})


if __name__ == "__main__":
    unittest.main()
