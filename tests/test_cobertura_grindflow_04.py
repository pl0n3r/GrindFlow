#!/usr/bin/env python3
"""GrindFlow #757: synthetic PHP runtime/weekly/validity and real Ruby CI checks.

These are executable offline *subcontracts*, not deployment or full production
certification. Deliberately never read the coverage matrix or any tenant data.
"""
from __future__ import annotations

import ast
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
A = frozenset({
    "SPEC:043", "SPEC:044", "SPEC:046", "STACK:GF-OPS-013",
    "STACK:GF-OPS-014",
})
B = {
    "SPEC:039": "real authenticated browser/DB workflow with 27 scheduled cases",
    "SPEC:040": "real tenant SQL distribution pagination and login context",
    "SPEC:041": "actual deployment marker, real-stack and Hostinger release observation",
    "SPEC:042": "DB-backed membership join and weekly preview authorization",
    "SPEC:045": "DB composite keys and tenant-bound collection/variant records",
    "STACK:GF-ARCH-002": "full tenant/data parity requires reversible migration and SQL inventory",
    "STACK:GF-ARCH-003": "coexistence and rollback require real CI, smoke and browser receipts",
    "STACK:GF-FR-008": "end-to-end authorized pilot with tenant, media and schedules",
    "STACK:GF-FR-010": "private Vault note CRUD requires DB and authorized tenant",
    "STACK:GF-FR-011": "classification and tenant filters require authenticated DB queries",
    "STACK:GF-OPS-012": "own/direct Symfony deprecations need vendor PHPUnit/CI runtime",
    "STACK:GF-UX-001": "responsive authenticated Laravel navigation needs a real browser",
    "STACK:GF-UX-002": "shared Symfony header, CSRF and private navigation need browser",
}
C = {
    "STACK:GF-ARCH-001": "target architecture and cutover direction, not a callable behavior",
    "STACK:GF-FR-009": "commercial pilot evaluation requires a human decision",
}

PHP = r"""
require 'symfony/src/Infrastructure/Runtime/RuntimeReadiness.php';
require 'symfony/src/Scheduling/WeeklySlotCalculator.php';
require 'symfony/src/Scheduling/ScheduleDraftApplicationService.php';
require 'symfony/src/Library/AssetLifecycle.php';
require 'symfony/src/Identity/Application/MembershipContext.php';
$readiness = new GrindFlow\Infrastructure\Runtime\RuntimeReadiness();
$membershipReflection = new ReflectionClass(GrindFlow\Identity\Application\MembershipContext::class);
$membership = $membershipReflection->newInstanceWithoutConstructor();
$extensions = ['ctype','iconv','PDO','pdo_mysql'];
$calculator = new GrindFlow\Scheduling\WeeklySlotCalculator();
$rule = ['timezone'=>'UTC','weekdays'=>'thu,fri','local_time'=>'11:00',
         'local_times'=>['11:00'],'max_per_day'=>2];
$clock = new DateTimeImmutable('2026-10-29T10:00:00+00:00');
$invalidRule = $rule;
$invalidRule['local_times'] = ['25:00'];
$emptyRule = $rule;
$emptyRule['local_times'] = [];
$service = GrindFlow\Scheduling\ScheduleDraftApplicationService::class;
$lifecycle = GrindFlow\Library\AssetLifecycle::class;
echo json_encode([
  'supported'=>[
    $readiness->supports(80300,$extensions),
    $readiness->supports(80599,$extensions),
    $readiness->supports(80299,$extensions),
    $readiness->supports(90000,$extensions),
    $readiness->supports(80300,['ctype','iconv','PDO'])
  ],
  'public'=>$readiness->publicSummary(),
  'expected_compatible'=>$readiness->supports(PHP_VERSION_ID,get_loaded_extensions()),
  'membership_permissions'=>[
    $membership->permissions('admin'),
    $membership->permissions('model'),
    $membership->permissions('unknown'),
  ],
  'slots'=>$calculator->upcoming($rule,$clock),
  'invalid_slots'=>$calculator->upcoming($invalidRule,$clock),
  'empty_slots'=>$calculator->upcoming($emptyRule,$clock),
  'absent_rule'=>$calculator->upcoming(false,$clock),
  'validity'=>[
    $service::validityState('2026-10-29 11:00:00',null,'2026-10-29 10:00:00'),
    $service::validityState('2026-10-29 10:00:00','2026-10-29 11:00:00','2026-10-29 10:30:00'),
    $service::validityState(null,'2026-10-29 11:00:00','2026-10-29 11:00:00'),
    $service::validityState(null,null,'2026-10-29 10:00:00')
  ],
  'lifecycle'=>[
    $lifecycle::effectiveState('ready','expired'),
    $lifecycle::effectiveState('archived','expired'),
    $lifecycle::blockingReasons('unknown'),
    $lifecycle::blockingReasons('blocked')
  ]
],JSON_THROW_ON_ERROR);
"""


def php_probe() -> dict:
    """Execute real PHP contracts in a disposable offline process."""
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP required for actual product probe")
    with tempfile.TemporaryDirectory(prefix="gf-757-php-") as home:
        env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
               "HOME": home, "LANG": "C", "APP_ENV": "testing"}
        result = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-r", PHP],
            cwd=ROOT, env=env, capture_output=True, text=True,
            timeout=30, check=False,
        )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: PHP product probe failed: " + result.stderr[-1200:])
    try:
        value = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: invalid product output: " + result.stdout[:1200]) from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: invalid product output")
    return value


def workflow_probe() -> str:
    """Execute the real YAML/security validator; it never runs workflows."""
    ruby = shutil.which("ruby")
    if ruby is None:
        raise AssertionError("UNKNOWN: Ruby needed for workflow validator")
    with tempfile.TemporaryDirectory(prefix="gf-757-ruby-") as home:
        result = subprocess.run(
            [ruby, "scripts/workflow-syntax-check.rb"],
            cwd=ROOT, env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
                           "HOME": home, "LANG": "C"},
            capture_output=True, text=True, timeout=45, check=False,
        )
    if result.returncode:
        raise AssertionError("UNKNOWN: real workflow validator failed: " + result.stderr[-1200:])
    return result.stdout


class CoberturaGrindflow04Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        """Run probes once, failing closed when tools or evidence are missing."""
        cls.actual = php_probe()
        cls.actions = workflow_probe()

    def test_contrato_principal(self) -> None:
        """AC-01: 5 real executable subcontracts; 15 classified B/C."""
        self.assertEqual(len(A | B.keys() | C.keys()), 20)
        self.assertFalse((A & B.keys()) | (A & C.keys()) | (B.keys() & C.keys()))
        self.assertTrue(all(B.values()) and all(C.values()))
        slots = self.actual["slots"]
        cases = {
            "SPEC:043": len(slots) == 2
                and slots[0]["weekday"] == "thu"
                and slots[0]["scheduled_at_utc"] == "2026-10-29T11:00:00Z"
                and slots[1]["scheduled_at_utc"] == "2026-10-30T11:00:00Z"
                and all(slot["capacity"] == 2 for slot in slots),
            "SPEC:044": self.actual["supported"] == [True, True, False, False, False]
                and self.actual["public"]["contract"] == "symfony-mariadb-v1"
                and isinstance(self.actual["public"]["compatible"], bool)
                and self.actual["public"]["compatible"] == self.actual["expected_compatible"]
                and set(self.actual["public"]) == {"compatible", "contract"},
            "SPEC:046": self.actual["validity"] == [
                "not_yet_valid", "valid", "expired", "valid",
            ] and self.actual["lifecycle"][:2] == ["expired", "archived"],
            "STACK:GF-OPS-013":
                "PASS official GitHub Actions use Node 24-capable majors" in self.actions,
            "STACK:GF-OPS-014":
                "PASS checkout credentials are not persisted" in self.actions,
        }
        self.assertEqual(set(cases), A)
        for code, valid in cases.items():
            with self.subTest(row=code):
                self.assertTrue(valid, "Unproven executable behavior: " + code)

    def test_falla_cerrado(self) -> None:
        """AC-02: reject invalid scopes, unsupported PHP and unsafe lifecycle."""
        self.assertEqual(self.actual["absent_rule"], [])
        self.assertEqual(self.actual["invalid_slots"], [])
        self.assertEqual(self.actual["empty_slots"], [])
        self.assertEqual(self.actual["supported"][2:], [False, False, False])
        self.assertEqual(self.actual["lifecycle"][2], ["lifecycle_blocked"])
        self.assertEqual(self.actual["lifecycle"][3], ["lifecycle_blocked"])
        self.assertEqual(self.actual["validity"][0], "not_yet_valid")
        self.assertEqual(self.actual["validity"][2], "expired")
        self.assertNotIn("script", json.dumps(self.actual["public"]).lower())
        # MembershipContext is the real role capability boundary; this offline
        # probe exercises policy, not tenant DB membership or revocation state.
        permissions = self.actual["membership_permissions"]
        self.assertTrue(permissions[0]["distribution_authorize"])
        self.assertFalse(permissions[1]["distribution_authorize"])
        self.assertFalse(permissions[1]["organization_manage"])
        self.assertFalse(any(permissions[2].values()))

    def test_sin_datos_reales(self) -> None:
        """AC-03: disposable local process, synthetic instants, no client data."""
        own = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                self.assertFalse(
                    {a.name.split(".")[0] for a in node.names} &
                    {"requests", "socket", "urllib", "httpx", "pymysql"}
                )
        self.assertNotIn("new PDO(", PHP)
        self.assertNotIn("fetch(", PHP)
        self.assertNotIn("curl", PHP.lower())
        self.assertEqual(self.actual["slots"][0]["timezone"], "UTC")
        self.assertEqual((len(A), len(B), len(C)), (5, 13, 2))


if __name__ == "__main__":
    unittest.main()
