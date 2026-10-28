#!/usr/bin/env python3
"""GrindFlow #712: evidencia sintética de cinco brechas NFR/OPS/SEC.

Las pruebas de contratos versionados no certifican branch protection actual,
Hostinger, secretos reales, deploy ni verificación de producción.
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import re
import runpy
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"

IDS = (
    "REQ:GF-NFR-003",
    "REQ:GF-NFR-004",
    "REQ:GF-NFR-005",
    "REQ:GF-OPS-009",
    "REQ:GF-SEC-001",
)
MATRIX = "docs/matriz-cobertura.md"
REQUIREMENTS = "docs/REQUIREMENTS.md"


@dataclass(frozen=True)
class Evidence:
    row_id: str
    section: str
    files: tuple[tuple[str, tuple[str, ...]], ...]


EVIDENCE = (
    Evidence("REQ:GF-NFR-003", "GF-NFR-003", (
        (".github/workflows/grindflow-ci.yml", (
            "  validate:", "    name: validate", "    if: always()",
            "require_success preflight", "require_success fast",
            "require_success privacy", "require_optional",
        )),
    )),
    Evidence("REQ:GF-NFR-004", "GF-NFR-004", (
        ("scripts/operations-health.py", (
            "def evaluate(", "def _signal(", "OperationsHealthError",
            "evidence_missing", "head_sha_mismatch",
        )),
        ("tests/test_operations_health_contract.py", (
            "test_sources_have_severity_state_and_freshness",
            "test_alert_is_deduplicated_and_sanitized",
        )),
    )),
    Evidence("REQ:GF-NFR-005", "GF-NFR-005", (
        ("tests/Feature/VisualShellTest.php", (
            "test_home_renders_grindflow_visual_shell",
            "test_login_renders_grindflow_visual_shell",
            "test_mobile_workspace_navigation_keeps_all_links_scrollable_and_named",
            "->assertOk()", "->assertSee(", "overflow-x: auto;",
        )),
        ("public/css/grindflow.css", ("@media",)),
    )),
    Evidence("REQ:GF-OPS-009", "GF-OPS-009", (
        ("scripts/production-smoke.sh", (
            "check_workspace_modules() {",
            "for module in scheduler distribution traffic finance; do",
            "MODULE_READ_ONLY=", "traffic/export",
            "date_utc,label,short_link,channel,campaign,status,clicks",
            "safe_module_http_class",
        )),
        ("tests/test_production_smoke_ephemeral_credentials.py", (
            "test_failure_reporting_remains_secret_free_and_fail_closed",
        )),
    )),
    Evidence("REQ:GF-SEC-001", "GF-SEC-001", (
        ("app/Support/Security/SecretCipher.php", (
            "aes-256-gcm", "random_bytes(", "openssl_encrypt(",
            "openssl_decrypt(", "SecretCryptoException",
        )),
        ("tests/Feature/DropboxOAuthConnectionTest.php", (
            "test_callback_exchanges_code_and_creates_encrypted_tenant_connection",
            "assertStringNotContainsString(", "->access_ciphertext",
            "->refresh_ciphertext",
        )),
    )),
)
FILES = {MATRIX, REQUIREMENTS} | {
    path for item in EVIDENCE for path, _ in item.files
}


def parse_rows(text: str) -> dict[str, tuple[str, ...]]:
    """Fail closed on duplicates or malformed matrix rows."""
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        if not any(line.startswith("| " + row_id + " |") for row_id in IDS):
            continue
        fields = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if len(fields) != 8 or fields[0] in found:
            raise ValueError("UNKNOWN: ambiguous matrix row")
        found[fields[0]] = fields
    return found


def section_text(source: str, header: str) -> str | None:
    hits = list(re.finditer(
        r"(?m)^### " + re.escape(header) + r"\s+[^\n]*$", source
    ))
    if len(hits) != 1:
        return None
    rest = source[hits[0].end():]
    return re.split(r"(?m)^#{2,3}\s+", rest, maxsplit=1)[0]


def inspect(item: Evidence, rows: dict[str, tuple[str, ...]],
            requirements: str, sources: dict[str, str]) -> dict[str, str]:
    result = {"code": UNKNOWN, "production": UNKNOWN, "branch_rule": UNKNOWN}
    row = rows.get(item.row_id)
    if row is None or row[2] != "NO_VERIFICABLE":
        return result
    if row[5:8] != ("NO_ACREDITADO",) * 3:
        return result
    if not section_text(requirements, item.section):
        return result
    for path, tokens in item.files:
        source = sources.get(path)
        if not source or not all(token in source for token in tokens):
            return result
    result["code"] = LOCAL
    return result


def synthetic_signals() -> dict[str, object]:
    sha = "a" * 40
    return {
        "version": 1,
        "signals": [
            {
                "source": source,
                "status": "completed",
                "conclusion": "success",
                "head_sha": sha,
                "run_id": ix,
                "url": "https://github.com/pl0n3r/GrindFlow/actions/runs/" + str(ix),
                "updated_at": "2026-10-01T00:00:00Z",
            }
            for ix, source in enumerate(
                ("ci_health", "deploy_observer", "production_smoke"), start=1
            )
        ],
    }


class BrechasGrindflow05Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {
            path: (ROOT / path).read_text(encoding="utf-8")
            for path in FILES
        }
        cls.rows = parse_rows(cls.sources[MATRIX])
        cls.operations = runpy.run_path(str(ROOT / "scripts/operations-health.py"))

    def test_contrato_principal(self) -> None:
        # AC-01: los cinco IDs apuntan a contratos concretos, nunca a live.
        self.assertEqual(set(IDS), set(self.rows))
        self.assertEqual(len(IDS), len(EVIDENCE))
        for item in EVIDENCE:
            with self.subTest(requirement=item.row_id):
                self.assertEqual(
                    LOCAL, inspect(
                        item, self.rows, self.sources[REQUIREMENTS], self.sources
                    )["code"],
                    f"{item.row_id}: UNKNOWN, falta evidencia local",
                )

        # REQ:GF-NFR-004: ejecutar el evaluador real con datos sintéticos.
        evaluate = self.operations["evaluate"]
        healthy = evaluate(synthetic_signals(), main_sha="a" * 40)
        self.assertEqual("HEALTHY", healthy["state"])
        self.assertTrue(all(s["state"] == "HEALTHY" for s in healthy["signals"]))

        # REQ:GF-NFR-005: aserciones reales PHPUnit, no nombres vacíos.
        visual = self.sources["tests/Feature/VisualShellTest.php"]
        for name in (
            "test_home_renders_grindflow_visual_shell",
            "test_login_renders_grindflow_visual_shell",
            "test_mobile_workspace_navigation_keeps_all_links_scrollable_and_named",
        ):
            self.assertRegex(visual, r"public function " + name + r"\(\): void")
        self.assertIn("->assertOk()", visual)

        # REQ:GF-OPS-009: contratos para GETs, markers, CSV y fallo explícito.
        smoke = self.sources["scripts/production-smoke.sh"]
        block = smoke.split("check_workspace_modules() {", 1)[1].split(
            "\n}\n", 1
        )[0]
        for expected in ("curl_common --cookie", "--output \"$module_html\"",
                         "if [[ \"$status\" != \"200\" ]]", "module_failure"):
            self.assertIn(expected, block)
        self.assertNotIn("--request POST", block)

        # REQ:GF-SEC-001: tests existentes comprueban cifrado y no plaintext.
        secure = self.sources["tests/Feature/DropboxOAuthConnectionTest.php"]
        self.assertIn("app(SecretCipher::class)->decrypt(", secure)
        self.assertGreaterEqual(secure.count("assertStringNotContainsString("), 2)

    def test_falla_cerrado(self) -> None:
        # AC-02: ausencia/duplicación de filas, requisitos o fuente => UNKNOWN.
        req = self.sources[REQUIREMENTS]
        for item in EVIDENCE:
            with self.subTest(requirement=item.row_id):
                missing = dict(self.rows)
                del missing[item.row_id]
                self.assertEqual(UNKNOWN, inspect(item, missing, req, self.sources)["code"])
                self.assertEqual(UNKNOWN, inspect(item, self.rows, "", self.sources)["code"])
                broken = dict(self.sources)
                broken[item.files[0][0]] = ""
                self.assertEqual(UNKNOWN, inspect(item, self.rows, req, broken)["code"])
                tampered = dict(self.rows)
                row = list(tampered[item.row_id])
                row[2] = "CUBIERTO"
                tampered[item.row_id] = tuple(row)
                self.assertEqual(UNKNOWN, inspect(item, tampered, req, self.sources)["code"])
        sample = next(x for x in self.sources[MATRIX].splitlines()
                      if x.startswith("| " + IDS[0] + " |"))
        with self.assertRaises(ValueError):
            parse_rows(self.sources[MATRIX] + "\n" + sample)

        # La salud operativa real no debe exponer ni aceptar datos arbitrarios.
        payload = synthetic_signals()
        payload["signals"][0]["head_sha"] = "b" * 40
        result = self.operations["evaluate"](payload, main_sha="a" * 40)
        self.assertEqual("UNKNOWN", result["state"])
        self.assertEqual("STALE", result["signals"][0]["freshness"])
        tainted = synthetic_signals()
        tainted["signals"][0]["password"] = "SYNTHETIC_DO_NOT_ECHO"
        with self.assertRaises(self.operations["OperationsHealthError"]) as error:
            self.operations["evaluate"](tainted, main_sha="a" * 40)
        self.assertNotIn("SYNTHETIC_DO_NOT_ECHO", str(error.exception))

    def test_sin_datos_reales(self) -> None:
        # AC-03: solo lectura de fuentes del checkout y ejecución Python pura.
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|subprocess)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(FILES, set(self.sources))
        for path in FILES:
            self.assertFalse(Path(path).is_absolute())
            self.assertTrue((ROOT / path).is_file())
        # No llamar scripts/production-smoke.sh: su ejecución requeriría red.

    def test_estados_unknown_explicitos(self) -> None:
        # AC-04: cada fila es una brecha local y nunca acredita Hostinger.
        for item in EVIDENCE:
            with self.subTest(requirement=item.row_id):
                row = self.rows[item.row_id]
                self.assertEqual("NO_VERIFICABLE", row[2])
                self.assertEqual(("NO_ACREDITADO",) * 3, row[5:8])
                result = inspect(
                    item, self.rows, self.sources[REQUIREMENTS], self.sources
                )
                self.assertEqual(UNKNOWN, result["production"])
                self.assertEqual(UNKNOWN, result["branch_rule"])
                self.assertEqual(LOCAL, result["code"])
        # El test de código no convierte check de GitHub ni prod en GREEN.
        incomplete = synthetic_signals()
        incomplete["signals"][1] = {
            "source": "deploy_observer", "status": "missing",
            "conclusion": None, "head_sha": None, "run_id": None,
            "url": None, "updated_at": None,
        }
        self.assertEqual(
            UNKNOWN, self.operations["evaluate"](
                incomplete, main_sha="a" * 40
            )["state"]
        )


if __name__ == "__main__":
    unittest.main()
