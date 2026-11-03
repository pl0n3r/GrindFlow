#!/usr/bin/env python3
"""#713: contratos GF-SEC-002/003/004 y SPEC:005/007, sin acreditar live.

Las observaciones locales NUNCA sustituyen CI exact-SHA, Hostinger,
seguridad de toda la aplicación, escalabilidad real ni pagos aprobados.
"""
from __future__ import annotations

from dataclasses import dataclass
import ast
import json
import os
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
MATRIX = "docs/matriz-cobertura.md"
REQ = "docs/REQUIREMENTS.md"
SPEC = "docs/GRINDFLOW-SPEC.md"
TRANSITION = "docs/STACK-TRANSITION-SYMFONY.md"
POLICY = "app/Support/Operations/ProductionWritePolicy.php"
ORG = "tests/Feature/OrganizationContextTest.php"
VISIBILITY = "tests/Feature/OrganizationVisibilityTest.php"
AUTH = "tests/Feature/AuthenticationTest.php"
CSRF = "symfony/tests/php/CsrfMutationCoverageTest.php"
COMPOSER = "symfony/composer.json"
FRONT = "symfony/package.json"
PLAN = "symfony/tests/php/PlanLimitsTest.php"

IDS = (
    "REQ:GF-SEC-002", "REQ:GF-SEC-003", "REQ:GF-SEC-004",
    "SPEC:005", "SPEC:007",
)
PATHS = (MATRIX, REQ, SPEC, ORG, VISIBILITY, AUTH, CSRF,
         POLICY, COMPOSER, FRONT, TRANSITION, PLAN)


@dataclass(frozen=True)
class Clause:
    identifier: str
    source: str
    heading: str
    tokens: tuple[str, ...]
    evidence: tuple[tuple[str, tuple[str, ...]], ...]
    local_contract: bool


CLAUSES = (
    Clause(IDS[0], REQ, "### GF-SEC-002 — Authorization",
           ("Every mutation performs server-side authorization.",),
           ((ORG, ("test_queue_context_revalidates_membership_before_running",
                    "test_queue_context_rejects_foreign_organization",
                    "AuthorizationException")),
            (VISIBILITY, ("test_dashboard_totals_are_scoped_to_memberships_not_foreign_tenant_data",
                          "test_guest_cannot_open_dashboard"))), True),
    Clause(IDS[1], REQ, "### GF-SEC-003 — CSRF/session",
           ("CSRF/session protections", "rechazadas", "identificador de sesión"),
           ((AUTH, ("test_rejected_login_preserves_anonymous_csrf_and_allows_a_later_valid_login",
                    "self::assertSame($beforeToken[1], $afterToken[1],",
                    "$anonymousSessionId")),
            (CSRF, ("testEveryMutatingSymfonyRouteHasCsrfOrSignedRequestAuthentication",
                    "testGuardScannerRejectsAnUnguardedMutation"))), True),
    Clause(IDS[2], REQ, "### GF-SEC-004 — Production safety",
           ("CI must not execute destructive production database operations",),
           ((POLICY, ("assertAutonomousWriteAllowed(",
                      "Destructive production writes require explicit owner authorization.",
                      "A recent verified database backup is required.",
                      "An exclusive operation lock is required.")),), True),
    Clause(IDS[3], SPEC, "### 1A.3. Público objetivo",
           ("Creadores independientes", "Estudios pequeños y medianos",
            "Productoras y agencias", "sin construir una plataforma distinta"),
           ((COMPOSER, ('"symfony/framework-bundle"', '"doctrine/orm"')),
            (FRONT, ('"react"',)),
            (TRANSITION, ("arquitectura objetivo", "Symfony 7.4 LTS"))), False),
    Clause(IDS[4], SPEC, "#### 1A.4.1. SaaS por suscripción",
           ("suscripciones periódicas", "no basará su modelo principal"),
           ((PLAN, ("test_criterio_principal", "unknown", "within", "exceeded")),), False),
)


def parse_matrix(raw: str) -> dict[str, tuple[str, ...]]:
    """Una fila inequívoca de ocho columnas por ID; duplicado => fail-closed."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        if not any(re.match(r"\|\s*" + re.escape(key) + r"\s*\|", line.lstrip()) for key in IDS):
            continue
        columns = tuple(value.strip() for value in line.strip().strip("|").split("|"))
        if len(columns) != 8 or columns[0] in rows:
            raise ValueError("UNKNOWN: fila ausente de contrato único/íntegro")
        rows[columns[0]] = columns
    return rows


def section(raw: str, heading: str) -> str | None:
    """La sección debe existir exactamente una vez y tener cuerpo no vacío."""
    hits = list(re.finditer(r"(?m)^" + re.escape(heading) + r"[ \t]*$", raw))
    if len(hits) != 1:
        return None
    tail = raw[hits[0].end():]
    boundary = re.search(r"(?m)^#{2,4}\s+", tail)
    result = tail[:boundary.start()] if boundary else tail
    return result if result.strip() else None


PHP_COMMENTS_FILTER = r'''
    $source = stream_get_contents(STDIN);
    if ($source === false || !function_exists('token_get_all')) { exit(1); }
    foreach (token_get_all($source) as $token) {
        if (is_array($token) &&
            ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
        }
        echo is_array($token) ? $token[1] : $token;
    }
'''


def php_without_comments(source: str) -> str | None:
    """Do not count commented-out PHP tests as local-contract evidence."""
    try:
        process = subprocess.run(
            ["php", "-r", PHP_COMMENTS_FILTER], input=source, text=True,
            capture_output=True, timeout=15, check=False,
        )
        return process.stdout if process.returncode == 0 else None
    except (OSError, subprocess.TimeoutExpired):
        return None


def inspect(clause: Clause, rows: dict[str, tuple[str, ...]],
            sources: dict[str, str], policy_results: list[str] | None) -> dict[str, str]:
    """Evidencia local de fuente; toda dimensión no demostrada queda UNKNOWN."""
    result = {"code": UNKNOWN, "production": UNKNOWN, "hostinger": UNKNOWN,
              "commercial": UNKNOWN, "scalability": UNKNOWN}
    row = rows.get(clause.identifier)
    if (not row or len(row) != 8 or row[0] != clause.identifier or
            row[2] != "NO_VERIFICABLE" or row[5:8] != ("NO_ACREDITADO",) * 3 or
            Path(clause.source).name not in row[3]):
        return result
    content = section(sources.get(clause.source, ""), clause.heading)
    if not content or not all(word.casefold() in content.casefold()
                              for word in clause.tokens):
        return result
    for path, tokens in clause.evidence:
        code = sources.get(path, "")
        if clause.identifier in IDS[:2]:
            code = php_without_comments(code) if code else None
        if not code or not all(token in code for token in tokens):
            return result
    if clause.identifier == IDS[2] and policy_results != EXPECTED_POLICY:
        return result
    if clause.local_contract:
        result["code"] = LOCAL
    elif clause.identifier == IDS[3]:
        # Dependencias y propuesta modular NO acreditan crecimiento real.
        try:
            composer = json.loads(sources[COMPOSER])
            front = json.loads(sources[FRONT])
            required = composer["require"]
            dependencies = front["dependencies"]
            if not (
                isinstance(required, dict)
                and isinstance(dependencies, dict)
                and isinstance(required.get("symfony/framework-bundle"), str)
                and required["symfony/framework-bundle"].startswith("7.4")
                and "doctrine/orm" in required
                and "doctrine/dbal" in required
                and isinstance(dependencies.get("react"), str)
                and dependencies["react"].startswith("^19")
            ):
                return result
        except (KeyError, TypeError, ValueError):
            return result
        # El contrato objetivo existe; se mantiene UNKNOWN sobre el requisito
        # global hasta medir segmentación, aislamiento y escalabilidad reales.
    else:
        # PlanLimits prueba cuotas sintéticas, NO cobros SaaS habilitados.
        pass
    return result


# Entradas sintéticas: (fase, operación, destructivo, masivo, versionado,
#                     backup verificado, lock), con decisión exacta permitida.
CASES = (
    ("construccion", "synthetic", False, False, True, False, False),
    ("construccion", "synthetic", False, True, True, True, True),
    ("construccion", "migration", False, False, True, True, True),
    ("construccion", "synthetic", False, False, True, True, False),
    ("live", "synthetic", False, False, True, True, True),
    ("invalid", "synthetic", False, False, True, True, True),
    ("construccion", "synthetic", True, False, True, True, True),
    ("construccion", "synthetic", False, False, False, True, True),
    ("construccion", "migration", False, False, True, False, True),
    ("construccion", "migration", False, False, True, True, False),
    ("construccion", "synthetic", False, True, True, False, True),
    ("construccion", "synthetic", False, True, True, True, False),
)
EXPECTED_POLICY = ["ALLOWED"] * 4 + ["DENIED"] * 8


def run_policy(policy_path: Path) -> list[str] | None:
    """Ejecuta SOLO política PHP local aislada con fase/sesión sintéticas."""
    if not policy_path.is_file():
        return None
    payload = json.dumps(CASES)
    script = r'''
        $phase = 'live';
        function config($name, $default = null) { global $phase; return $phase; }
        require $argv[1];
        $policy = new \App\Support\Operations\ProductionWritePolicy();
        $cases = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
        $results = [];
        foreach ($cases as $c) {
            $phase = $c[0];
            try {
                $policy->assertAutonomousWriteAllowed($c[1], $c[2], $c[3], $c[4], $c[5], $c[6]);
                $results[] = 'ALLOWED';
            } catch (\RuntimeException $ex) {
                $results[] = 'DENIED';
            }
        }
        echo json_encode($results, JSON_THROW_ON_ERROR);
    '''
    try:
        p = subprocess.run(["php", "-n", "-r", script, str(policy_path), payload],
                           text=True, capture_output=True, timeout=15,
                           check=False, env={**os.environ, "APP_PHASE": "test"})
        if p.returncode != 0:
            return None
        value = json.loads(p.stdout)
        return value if isinstance(value, list) else None
    except (OSError, subprocess.TimeoutExpired, ValueError):
        return None


class BrechasGrindflow06Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {path: (ROOT / path).read_text(encoding="utf-8")
                       for path in PATHS}
        cls.rows = parse_matrix(cls.sources[MATRIX])
        cls.actual = run_policy(ROOT / POLICY)

    def test_contrato_principal(self) -> None:
        """AC-01: cinco cláusulas, tres contratos y dos UNKNOWN explícitos."""
        self.assertEqual(set(IDS), set(self.rows))
        self.assertEqual(set(IDS), {c.identifier for c in CLAUSES})
        self.assertEqual(EXPECTED_POLICY, self.actual,
                         "REQ:GF-SEC-004 UNKNOWN: la política PHP no pasó 12 escenarios")
        for clause in CLAUSES:
            with self.subTest(clause=clause.identifier):
                actual = inspect(clause, self.rows, self.sources, self.actual)
                expected = LOCAL if clause.local_contract else UNKNOWN
                self.assertEqual(expected, actual["code"],
                                 clause.identifier + " falta o contradice la fuente")

    def test_falla_cerrado(self) -> None:
        """AC-02: sin filas, sección, fuente o política dinámica no se acredita."""
        for clause in CLAUSES:
            with self.subTest(clause=clause.identifier):
                missing_row = dict(self.rows)
                missing_row.pop(clause.identifier)
                self.assertEqual(UNKNOWN, inspect(clause, missing_row,
                                                 self.sources, self.actual)["code"])
                missing_source = dict(self.sources)
                missing_source[clause.source] = ""
                self.assertEqual(UNKNOWN, inspect(clause, self.rows,
                                                 missing_source, self.actual)["code"])
                missing_test = dict(self.sources)
                missing_test[clause.evidence[0][0]] = ""
                self.assertEqual(UNKNOWN, inspect(clause, self.rows,
                                                 missing_test, self.actual)["code"])
                forged = dict(self.rows)
                row = list(forged[clause.identifier]); row[2] = "CUBIERTO"
                forged[clause.identifier] = tuple(row)
                self.assertEqual(UNKNOWN, inspect(clause, forged,
                                                 self.sources, self.actual)["code"])
        self.assertEqual(UNKNOWN, inspect(CLAUSES[2], self.rows,
                                         self.sources, None)["code"])
        duplicate = next(line for line in self.sources[MATRIX].splitlines()
                         if line.startswith("| " + IDS[0] + " |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_matrix(self.sources[MATRIX] + "\n" + duplicate)
        padded = duplicate.replace("| " + IDS[0] + " |",
                                   "|  " + IDS[0] + "   |", 1)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_matrix(self.sources[MATRIX] + "\n" + padded)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_matrix("| " + IDS[0] + " | truncated |")
        self.assertIsNone(run_policy(ROOT / "not-a-real-policy.php"))
        active = php_without_comments(
            "<?php /** ghost_assertion */ function active_test() {} // ghost_method\n"
        )
        self.assertIsNotNone(active)
        self.assertIn("active_test", active)
        self.assertNotIn("ghost_assertion", active)
        self.assertNotIn("ghost_method", active)

    def test_sin_datos_reales(self) -> None:
        """AC-03: no requiere datos de usuario, endpoints ni claves reales."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^\s*(?:import|from)\s+(?:requests|httpx|urllib|socket)\b")
        self.assertNotRegex(source, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(source, r"(?i)(?:secret|token|password)\s*=\s*['\"][^'\"]{8,}['\"]")
        self.assertEqual(set(PATHS), set(self.sources))
        self.assertTrue(all(not Path(p).is_absolute() for p in PATHS))
        self.assertEqual(12, len(CASES))
        # `php -n -r` ejecuta exclusivamente una clase pura con config() falsa.
        imported = set()
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                imported.update(item.name.split(".")[0] for item in node.names)
            if isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertTrue(imported.isdisjoint({"requests", "httpx", "socket", "urllib"}))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: pasar fuente no equivale a live o facturación real."""
        for clause in CLAUSES:
            r = inspect(clause, self.rows, self.sources, self.actual)
            with self.subTest(requirement=clause.identifier):
                for dimension in ("production", "hostinger", "commercial", "scalability"):
                    self.assertEqual(UNKNOWN, r[dimension])
        for clause in CLAUSES[-2:]:
            self.assertEqual(UNKNOWN, inspect(clause, self.rows,
                                             self.sources, self.actual)["code"])
        for clause in CLAUSES[:3]:
            self.assertEqual(LOCAL, inspect(clause, self.rows,
                                           self.sources, self.actual)["code"])


if __name__ == "__main__":
    unittest.main()
