#!/usr/bin/env python3
"""Cobertura local, sin red, de SPEC:024..028. Nunca acredita CI ni producción."""
from __future__ import annotations

import ast
import importlib.util
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/GRINDFLOW-SPEC.md"
VAULT = "symfony/tests/php/VaultTrashTest.php"
AUDIT = "symfony/tests/php/VaultAuditCommandTest.php"
ACCOUNT = "symfony/tests/php/AccountSecurityTest.php"
CI = ".github/workflows/grindflow-ci.yml"
TRANSITION = "docs/STACK-TRANSITION-SYMFONY.md"
PARITY = "tests/test_data_schema_parity.py"
FINANCE = "tests/Feature/FinanceTest.php"

# Reuse the hardened PHP lexer: strings and comments cannot forge a method or an assertion.
HELPER = importlib.util.spec_from_file_location("brechas_09", ROOT / "tests/test_brechas_grindflow_09.py")
if HELPER is None or HELPER.loader is None:
    raise RuntimeError("UNKNOWN: parser de pruebas no disponible")
module = importlib.util.module_from_spec(HELPER)
HELPER.loader.exec_module(module)
php_test_method = module.php_test_method
has_assertion = module.has_assertion

ROWS = {
    "SPEC:024": ("### Vault S2: notas de trabajo privadas", 272,
        ("anotación interna", "tenant-safe", "CSRF"),
        ((VAULT, "testTrashAndRestoreKeepPrivateOriginalAndEnforceMembership",
            ("self::assertResponseStatusCodeSame(403);",
             "self::assertResponseStatusCodeSame(404);",
             "self::assertSame('Para revisar antes de usar',")),
         (AUDIT, "testAuditReportsTenantScopedIntegrityAndRejectsIncompleteRestore", ("self::assertSame(2, $recovered[\'assets\']);",)))),
    "SPEC:025": ("### Identidad Symfony: cambio de contraseña personal", 275,
        ("clave actual", "429", "Retry-After"),
        ((ACCOUNT, "testAuthenticatedUserCanChangeOnlyOwnPasswordAndMustSignInAgain",
            ("self::assertResponseStatusCodeSame(401);",
             "self::assertResponseStatusCodeSame(403);",
             "self::assertResponseStatusCodeSame(429);",
             "self::assertSame($otherHash,")),)),
    "SPEC:026": ("## 7. Quality model", 279,
        ("GrindFlow CI / validate", "Composer", "Playwright"), ()),
    "SPEC:027": ("## 8. Migration rule", 285,
        ("Laravel/MariaDB", "Symfony", "paridad"), ()),
    "SPEC:028": ("## 9. Finance core contract", 291,
        ("append-only", "reversal", "currencies"),
        ((FINANCE, "test_finance_ledger_entries_cannot_be_updated_or_deleted",
            ("$this->assertStringContainsString(", "$this->expectException(LogicException::class);")),
         (FINANCE, "test_finance_totals_never_mix_currencies",
            ("->assertSee('COP')", "->assertSee('USD')")),
         (FINANCE, "test_editor_and_model_cannot_manage_finance",
            ("->assertForbidden();",)))),
}
FILES = {MATRIX, SPEC, CI, TRANSITION, PARITY}
FILES.update(path for _, _, _, methods in ROWS.values() for path, _, _ in methods)

def read_files() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}

def matrix_rows(text: str) -> dict[str, tuple[str, ...]]:
    result: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        m = re.match(r"^\s*\|\s*(SPEC:0(?:24|25|26|27|28))\s*\|", line)
        if not m:
            continue
        fields = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if m.group(1) in result or len(fields) != 8 or fields[0] != m.group(1):
            raise ValueError("UNKNOWN: fila duplicada o ambigua")
        result[m.group(1)] = fields
    return result

def active_shell_gate(text: str) -> bool:
    jobs = re.findall(r"(?ms)^  validate:\n(.*?)(?=^  [\w-]+:\n|\Z)", text)
    if len(jobs) != 1:
        return False
    lines = [x for x in jobs[0].splitlines() if not x.lstrip().startswith("#")]
    body = "\n".join(lines)
    if not re.search(r"(?m)^\s+if: always\(\)\s*$", body):
        return False
    match = re.search(r"(?m)^\s+needs:\s*\[([^\]]+)\]", body)
    if not match:
        return False
    needs = {x.strip() for x in match.group(1).split(",")}
    if not {"preflight", "fast", "privacy", "tests", "legacy", "symfony-preview"} <= needs:
        return False
    try:
        shell = body.split("run: |", 1)[1].split("- name: Publish final gate summary", 1)[0]
    except IndexError:
        return False
    return (all(re.search(r"(?m)^\s+require_success\s+" + x + r"\s+", shell)
                for x in ("preflight", "fast", "privacy"))
            and all(re.search(r"(?m)^\s+require_optional\s+" + x + r"\s+", shell)
                    for x in ("tests", "legacy", "symfony-preview"))
            and "if [[ \"$failures\" -ne 0 ]]" in shell
            and re.search(r"(?m)^\s+exit 1\s*$", shell) is not None)

def parity_contract(text: str) -> bool:
    try:
        tree = ast.parse(text)
    except SyntaxError:
        return False
    classes = [x for x in tree.body if isinstance(x, ast.ClassDef) and x.name == "DataSchemaParityTest"]
    if len(classes) != 1:
        return False
    methods = {x.name: x for x in classes[0].body if isinstance(x, ast.FunctionDef)}
    required = {
        "test_matching_snapshot_is_compatible": {"assertTrue", "assertEqual"},
        "test_missing_source_table_fails": {"assertFalse", "assertEqual"},
        "test_snapshot_must_prohibit_row_data": {"assertRaisesRegex"},
    }
    if not all(name in methods for name in required):
        return False
    for name, assertions in required.items():
        calls = {node.func.attr for node in ast.walk(methods[name])
                 if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute)}
        if not assertions <= calls:
            return False
    # A negative test must corrupt a synthetic snapshot and assert the rejection.
    negative = methods["test_snapshot_must_prohibit_row_data"]
    corrupts = any(
        isinstance(node, ast.Assign) and isinstance(node.value, ast.Constant)
        and node.value.value is True
        and any(isinstance(target, ast.Subscript)
                and isinstance(target.slice, ast.Constant)
                and target.slice.value == "contains_row_data"
                for target in node.targets)
        for node in ast.walk(negative)
    )
    rejects = any(
        isinstance(node, ast.With)
        and any(isinstance(item.context_expr, ast.Call)
                and isinstance(item.context_expr.func, ast.Attribute)
                and item.context_expr.func.attr == "assertRaisesRegex"
                and len(item.context_expr.args) >= 2
                and isinstance(item.context_expr.args[0], ast.Name)
                and item.context_expr.args[0].id == "ValueError"
                and isinstance(item.context_expr.args[1], ast.Constant)
                and item.context_expr.args[1].value == "contains_row_data=false"
                for item in node.items)
        and any(isinstance(call, ast.Call)
                and isinstance(call.func, ast.Attribute)
                and call.func.attr == "build_report"
                for child in node.body for call in ast.walk(child))
        for node in ast.walk(negative)
    )
    return (corrupts and rejects and "metadata_only" in text
            and "contains_row_data" in text)

def inspect(rid: str, rows: dict[str, tuple[str, ...]], files: dict[str, str]) -> dict[str, str]:
    outcome = {"code": UNKNOWN, "ci": UNKNOWN, "hostinger": UNKNOWN, "production": UNKNOWN}
    if rid not in ROWS:
        return outcome
    heading, line, tokens, methods = ROWS[rid]
    fields = rows.get(rid)
    if not fields or not fields[1].startswith(heading.lstrip("# ").strip() + " — "):
        return outcome
    if fields[3].count(f"[GRINDFLOW-SPEC.md:L{line}]") != 1:
        return outcome
    sections = re.findall(r"(?m)^" + re.escape(heading) + r"\s*$", files.get(SPEC, ""))
    if len(sections) != 1:
        return outcome
    section = files[SPEC].split(heading, 1)[1]
    section = re.split(r"(?m)^#{1,6}\s+", section, maxsplit=1)[0]
    if not all(token.lower() in section.lower() for token in tokens):
        return outcome
    for path, name, assertions in methods:
        body = php_test_method(files.get(path, ""), name)
        if body is None or not all(has_assertion(body, a) for a in assertions):
            return outcome
    if rid == "SPEC:026" and not active_shell_gate(files.get(CI, "")):
        return outcome
    if rid == "SPEC:027" and not (
        "propietario de escritura por módulo" in files.get(TRANSITION, "").lower()
        and parity_contract(files.get(PARITY, ""))):
        return outcome
    outcome["code"] = LOCAL
    return outcome

class BrechasGrindflow10Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.files = read_files()
        cls.rows = matrix_rows(cls.files[MATRIX])

    def test_contrato_principal(self):
        """AC-01: cinco filas, evidencia local independiente por sección."""
        self.assertEqual(set(ROWS), set(self.rows))
        for rid in ROWS:
            with self.subTest(rid=rid):
                self.assertEqual(LOCAL, inspect(rid, self.rows, self.files)["code"])

    def test_falla_cerrado(self):
        """AC-02: ausencias, duplicados, comentarios y afirmaciones falsas => UNKNOWN."""
        for rid in ROWS:
            with self.subTest(rid=rid):
                rows = dict(self.rows)
                rows.pop(rid)
                self.assertEqual(UNKNOWN, inspect(rid, rows, self.files)["code"])
                files = dict(self.files)
                files[SPEC] = ""
                self.assertEqual(UNKNOWN, inspect(rid, self.rows, files)["code"])
                rows = dict(self.rows)
                fields = list(rows[rid])
                fields[3] = "enlace ausente"
                rows[rid] = tuple(fields)
                self.assertEqual(UNKNOWN, inspect(rid, rows, self.files)["code"])
                if ROWS[rid][3]:
                    files = dict(self.files)
                    files[ROWS[rid][3][0][0]] = ""
                    self.assertEqual(UNKNOWN, inspect(rid, self.rows, files)["code"])
        sample = next(x for x in self.files[MATRIX].splitlines() if x.startswith("| SPEC:024 |"))
        with self.assertRaises(ValueError):
            matrix_rows(self.files[MATRIX] + "\n" + sample)
        files = dict(self.files)
        files[CI] = "# require_success preflight \"fake\"\n"
        self.assertEqual(UNKNOWN, inspect("SPEC:026", self.rows, files)["code"])
        files = dict(self.files)
        files[PARITY] = "class DataSchemaParityTest: pass"
        self.assertEqual(UNKNOWN, inspect("SPEC:027", self.rows, files)["code"])
        files[PARITY] = self.files[PARITY].replace(
            'self.assertRaisesRegex(ValueError, "contains_row_data=false")',
            'self.assertTrue(True)', 1)
        self.assertEqual(UNKNOWN, inspect("SPEC:027", self.rows, files)["code"])
        files = dict(self.files)
        files[TRANSITION] = files[TRANSITION].replace(
            "propietario de escritura por módulo", "varios escritores", 1)
        self.assertEqual(UNKNOWN, inspect("SPEC:027", self.rows, files)["code"])

    def test_sin_datos_reales(self):
        """AC-03: verificación read-only del checkout, sin contactos ni credenciales."""
        own = (ROOT / "tests/test_brechas_grindflow_10.py").read_text(encoding="utf-8")
        for prohibited in ("requests.", "urllib.request", "subprocess.", "os.environ",
                           "mysql.connector", "DB_PASSWORD", "API_TOKEN"):
            self.assertNotIn(prohibited, own.split("    def test_sin_datos_reales")[0])
        for rid in ROWS:
            result = inspect(rid, self.rows, self.files)
            self.assertEqual([UNKNOWN] * 3,
                             [result["ci"], result["hostinger"], result["production"]])

if __name__ == "__main__":
    unittest.main()
