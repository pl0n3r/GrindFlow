#!/usr/bin/env python3
"""#754: real offline subcontrols for GrindFlow REQ:GF-FR/MIG/NFR.

Run actual PHP product methods and Python CI behavior against synthetic inputs.
The twenty *integral* requirements still need independent release/Hostinger
receipts or historical migration parity: local success never certifies live.
No providers, credentials, external HTTP, database or customer information.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
CI = ROOT / ".github/workflows/grindflow-ci.yml"
UNKNOWN = "UNKNOWN"

# These are independently named requirements; suffixes are not interchangeable.
CODES = (
    "REQ:GF-FR-001", "REQ:GF-FR-002", "REQ:GF-FR-003",
    "REQ:GF-FR-004", "REQ:GF-FR-004C", "REQ:GF-FR-004D",
    "REQ:GF-FR-004E", "REQ:GF-FR-005", "REQ:GF-FR-005D",
    "REQ:GF-FR-006", "REQ:GF-FR-006A", "REQ:GF-FR-006B",
    "REQ:GF-FR-006C", "REQ:GF-FR-006D", "REQ:GF-FR-007",
    "REQ:GF-FR-007B", "REQ:GF-MIG-001", "REQ:GF-MIG-002",
    "REQ:GF-NFR-001", "REQ:GF-NFR-002",
)

# B = real-world receipt required to establish the INTEGRAL criterion.
# Existing local Laravel tests may substantiate subsets; they cannot
# establish identity, version, permissions or observations on Hostinger.
REAL_WORLD_GAPS = {
    "REQ:GF-FR-001": "deployed tenant authorization and identity receipt",
    "REQ:GF-FR-002": "deployed upload, storage and media-ingestion receipt",
    "REQ:GF-FR-003": "real queue, persistent media processing and retry receipt",
    "REQ:GF-FR-004": "deployed Scheduler with user, tenant and timezone",
    "REQ:GF-FR-004C": "real Scheduler-to-Traffic update and integrity trace",
    "REQ:GF-FR-004D": "deployed calendar with deep pagination and isolation",
    "REQ:GF-FR-004E": "real picker search over deployed tenant resources",
    "REQ:GF-FR-005": "authorized provider delivery and immutable dispatch receipt",
    "REQ:GF-FR-005D": "live delivery pagination beyond first one hundred",
    "REQ:GF-FR-006": "real attribution and independent aggregate trace",
    "REQ:GF-FR-006A": "deployed tracked-link association and delivery",
    "REQ:GF-FR-006B": "authenticated tenant CSV against actual daily data",
    "REQ:GF-FR-006C": "real link pause/resume and historical metric preservation",
    "REQ:GF-FR-006D": "real deep link management and authorization receipt",
    "REQ:GF-FR-007": "authorized tenant finance journal against actual records",
    "REQ:GF-FR-007B": "deployed grouped reconciliation/CSV tenant evidence",
    "REQ:GF-MIG-001": "historical Laravel foundation parity and release receipt",
    "REQ:GF-MIG-002": "historical identity and organization migration parity",
    "REQ:GF-NFR-001": "sustained maintainability across release and CI history",
    "REQ:GF-NFR-002": "authenticated repeated CI timing/latency in real runs",
}

# Standalone PHP invokes the checked-in *product* classes by reflection.
# Only side-effect-free methods are invoked. We deliberately never bootstrap
# Laravel, query a database, dispatch a job, call external services or trigger
# a release. Result keys name subcontrols, not completed requirements.
PHP_PRODUCT_PROBE = r'''
require_once getcwd().'/app/Enums/UserRole.php';
require_once getcwd().'/app/Services/Media/MediaAssetProcessor.php';
require_once getcwd().'/app/Services/Distribution/PublicationDeliveryManager.php';
require_once getcwd().'/app/Services/Traffic/TrackedLinkManager.php';
require_once getcwd().'/app/Services/Finance/FinanceLedgerManager.php';
function subcontrol(string $class, string $method, array $args): mixed {
    $ref = new ReflectionClass($class);
    $instance = $ref->newInstanceWithoutConstructor();
    return $ref->getMethod($method)->invokeArgs($instance, $args);
}
$m = 'App\\Services\\Media\\MediaAssetProcessor';
$d = 'App\\Services\\Distribution\\PublicationDeliveryManager';
$t = 'App\\Services\\Traffic\\TrackedLinkManager';
$f = 'App\\Services\\Finance\\FinanceLedgerManager';
$roles = [];
foreach (App\Enums\UserRole::cases() as $role) {
    $roles[$role->value] = $role->canManageOrganization();
}
$out = [
  'roles' => $roles,
  'kind' => [
    subcontrol($m,'kind',['image/jpeg']),
    subcontrol($m,'kind',['video/mp4']),
    subcontrol($m,'kind',['application/octet-stream']),
  ],
  'version' => [
    subcontrol($m,'ffprobeEnabledForVersion',[2]),
    subcontrol($m,'ffprobeEnabledForVersion',[3]),
    subcontrol($m,'ffmpegEnabledForVersion',[4]),
    subcontrol($m,'previewEnabledForVersion',[4]),
    subcontrol($m,'previewEnabledForVersion',[6]),
  ],
  'delivery_backoff' => [
    subcontrol($d,'transientDelay',[0]),
    subcontrol($d,'transientDelay',[1]),
    subcontrol($d,'transientDelay',[2]),
    subcontrol($d,'transientDelay',[3]),
    subcontrol($d,'transientDelay',[99]),
  ],
  'delivery_attempts' => $d::MAX_ATTEMPTS,
  'idempotency' => subcontrol($d,'idempotencyKey',['org-a','publication-b']),
  'traffic_trim' => [
    subcontrol($t,'nullableTrim',[null]),
    subcontrol($t,'nullableTrim',['   ']),
    subcontrol($t,'nullableTrim',[' campaign ']),
  ],
  'finance_trim' => [
    subcontrol($f,'nullableTrim',[null]),
    subcontrol($f,'nullableTrim',['   ']),
    subcontrol($f,'nullableTrim',[' allocation ']),
  ],
];
echo json_encode($out, JSON_THROW_ON_ERROR);
'''

# A local subcontrol is evidence ONLY of the indicated real method.
SUBCONTROLS = {
    "REQ:GF-FR-001": "roles",
    "REQ:GF-FR-002": "kind",
    "REQ:GF-FR-003": "version",
    "REQ:GF-FR-005": "delivery_backoff",
    "REQ:GF-FR-005D": "delivery_attempts",
    "REQ:GF-FR-006": "traffic_trim",
    "REQ:GF-FR-006A": "idempotency",
    "REQ:GF-FR-006C": "traffic_trim",
    "REQ:GF-FR-007": "finance_trim",
    "REQ:GF-FR-007B": "finance_trim",
    "REQ:GF-MIG-002": "roles",
}


def extract_rows(text: object) -> dict[str, tuple[str, ...]]:
    """Reject malformed/duplicated 20-row matrix before any accreditation."""
    if type(text) is not str:
        raise ValueError("UNKNOWN: missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for raw in text.splitlines():
        match = re.match(r"^\|\s*(REQ:GF-(?:FR|MIG|NFR)-\d{3}[A-Z]?)\b", raw)
        if not match or match.group(1) not in REAL_WORLD_GAPS:
            continue
        code = match.group(1)
        fields = tuple(x.strip() for x in raw.strip().strip("|").split("|"))
        if (code in found or len(fields) != 8 or fields[0] != code
                or fields[2] != "NO_VERIFICABLE"
                or fields[5:8] != ("NO_ACREDITADO",) * 3
                or not all(fields)):
            raise ValueError("UNKNOWN: invalid or promoted " + code)
        found[code] = fields
    if set(found) != set(CODES):
        raise ValueError("UNKNOWN: missing/ambiguous required rows")
    return found


def real_php() -> dict:
    """Invoke product code in local PHP, fail closed when PHP cannot execute."""
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("PHP runtime missing; cannot prove real product code")
    result = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", "-r", PHP_PRODUCT_PROBE],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("Product PHP failed: " + result.stderr[-900:])
    payload = json.loads(result.stdout)
    if not isinstance(payload, dict) or set(payload) != {
        "roles", "kind", "version", "delivery_backoff",
        "delivery_attempts", "idempotency", "traffic_trim", "finance_trim",
    }:
        raise AssertionError("Invalid product method probe result")
    return payload


def integral_coverage(_code: object, _evidence: object = None) -> str:
    """No local fixture or forged PASS grants production authority."""
    return UNKNOWN


class CoberturaGrindflow01Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.rows = extract_rows(cls.matrix)
        cls.php = real_php()

    def test_contrato_principal(self) -> None:
        """AC-01: all 20 rows classified; genuinely execute PHP subcontrols."""
        self.assertEqual(20, len(CODES))
        self.assertEqual(set(CODES), set(REAL_WORLD_GAPS))
        self.assertEqual(set(CODES), set(self.rows))
        for code, reason in REAL_WORLD_GAPS.items():
            with self.subTest(requirement=code):
                self.assertGreater(len(reason), 28)
                self.assertEqual(self.rows[code][2], "NO_VERIFICABLE")
                self.assertIn("falta", self.rows[code][3].lower())
                self.assertEqual(integral_coverage(code, self.php), UNKNOWN)

        role = self.php["roles"]
        self.assertEqual(role, {
            "admin": True, "studio": True,
            "model": False, "editor": False,
        })
        self.assertEqual(self.php["kind"], ["image", "video", None])
        self.assertEqual(self.php["version"], [False, True, True, False, True])
        delays = self.php["delivery_backoff"]
        self.assertEqual(len(delays), 5)
        self.assertTrue(all(isinstance(n, int) and n > 0 for n in delays))
        self.assertEqual(delays[0], delays[1])
        self.assertLessEqual(delays[1], delays[2])
        self.assertLessEqual(delays[2], delays[3])
        self.assertEqual(delays[3], delays[4])
        self.assertEqual(self.php["delivery_attempts"], 4)
        self.assertEqual(
            self.php["idempotency"],
            "grindflow:publication:org-a:publication-b",
        )
        self.assertEqual(self.php["traffic_trim"], [None, None, "campaign"])
        self.assertEqual(self.php["finance_trim"], [None, None, "allocation"])
        self.assertEqual(set(SUBCONTROLS) - set(self.rows), set())
        self.assertGreaterEqual(len(SUBCONTROLS), 10)
        for code, key in SUBCONTROLS.items():
            with self.subTest(real_product_subcontrol=code):
                self.assertIn(key, self.php)
                self.assertEqual(integral_coverage(code, self.php[key]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: executable role/MIME rejection, bounded retries, tamper guard."""
        self.assertIs(self.php["roles"]["model"], False)
        self.assertIs(self.php["roles"]["editor"], False)
        self.assertIsNone(self.php["kind"][2])
        self.assertIsNone(self.php["traffic_trim"][1])
        self.assertIsNone(self.php["finance_trim"][1])
        self.assertEqual(self.php["delivery_backoff"][-1],
                         self.php["delivery_backoff"][-2])
        line = next(s for s in self.matrix.splitlines()
                    if s.startswith("| REQ:GF-FR-001 |"))
        tampering = (
            None,
            self.matrix.replace(line, "", 1),
            self.matrix + "\n" + line,
            self.matrix.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            self.matrix.replace(line, line.replace("NO_ACREDITADO", "PASS"), 1),
            self.matrix + "\n" + line.replace(
                "| REQ:GF-FR-001 |", "| REQ:GF-FR-001/ |", 1),
        )
        for value in tampering:
            with self.subTest(tampering=str(value)[:20]):
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    extract_rows(value)

        from scripts import ci_retry  # Actual product CI retry implementation
        self.assertTrue(ci_retry.transient(75, ""))
        self.assertTrue(ci_retry.transient(1, "HTTP 503"))
        self.assertFalse(ci_retry.transient(1, "syntax error"))
        for command, attempts, delay in (([], 1, 0), (["true"], 6, 0),
                                         (["true"], 1, -1)):
            with self.assertRaises(ValueError):
                ci_retry.validate(command, attempts, delay)
        for forged in (None, True, {"PASS": True}, {"live": "GO"}):
            self.assertEqual(integral_coverage("REQ:GF-FR-005", forged),
                             UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: only class methods and checked-in safe synthetic strings."""
        import os
        from unittest.mock import patch
        from workers.config import Config, ConfigError
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(ConfigError):
                Config.from_env()
        self.assertTrue(MATRIX.is_file())
        self.assertFalse(any("@" in str(x) for x in (
            self.php["idempotency"], *self.php["traffic_trim"][2:],
        )))
        self.assertNotIn("https://", PHP_PRODUCT_PROBE)
        self.assertNotIn("curl_", PHP_PRODUCT_PROBE)
        self.assertEqual(set(SUBCONTROLS) - set(REAL_WORLD_GAPS), set())
        self.assertIsNone(integral_coverage("REQ:GF-FR-001") == "CUBIERTO"
                          or None)

    def test_ci_gate_contract(self) -> None:
        """AC-04: verify CI wiring, NOT pretend exact-SHA GitHub was green."""
        workflow = CI.read_text(encoding="utf-8")
        self.assertIn("name: Test suite", workflow)
        self.assertIn("vendor/bin/phpunit", workflow)
        self.assertIn("composer install", workflow)
        self.assertIn("name: php-quality", workflow)
        self.assertEqual(integral_coverage("AC-04", "CI configured"), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
