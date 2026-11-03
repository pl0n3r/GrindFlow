#!/usr/bin/env python3
"""Pruebas sintéticas de evidencia SPEC:018..022; no acreditan CI ni producción."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/GRINDFLOW-SPEC.md"
SCHEDULE = "symfony/tests/php/ScheduleDraftTest.php"
SERVICE = "symfony/tests/php/ScheduleDraftApplicationServiceTest.php"
HANDOFF = "symfony/tests/php/ManualHandoffTest.php"
DESTINATION = "symfony/tests/php/ManualDestinationTest.php"
TENANT = "tests/Feature/TenantScopedModelTest.php"

# ID, sección, línea, tokens de contrato, (archivo, método, aserciones)
ROWS = (
("SPEC:018","### S4 · Agenda interna persistida y cancelable",177,("capacidad","cancelar","idempotente"),(
 (SCHEDULE,"testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish",(
  "self::assertResponseStatusCodeSame(409);",
  "self::assertSame('draft', $created['draft']['status']);",
  "self::assertFalse($created['publishes']);",
  "self::assertSame('cancelled', $cancelled['draft']['status']);")),
 (SERVICE,"testRevokedOrganizationFailsBeforeDraftQueriesOrMutation",(
  "$db->expects(self::never())->method('insert');","['status' => 'revoked']")))),
("SPEC:019","### S4 · Handoff manual auditado",196,("prepare","complete","fail","append-only"),(
 (HANDOFF,"testManualHandoffRequiresExplicitTenantDestinationAndNeverPublishes",(
  "self::assertSame('prepared', $preparedFuture['status']);",
  "self::assertSame('failed', $failed['status']);",
  "self::assertSame('completed', $completed['status']);",
  "self::assertFalse($completed['external_evidence']);",
  "self::assertTrue($deleteBlocked")),)),
("SPEC:020","### S4 · Destinos manuales y cola interna",215,("tenant","cola interna","proveedor"),(
 (DESTINATION,"testDestinationCatalogIsTenantSafeReversibleAndRoleGuarded",(
  "self::assertNotContains($foreignDestination",
  "self::assertFalse($disabled['destination']['active']);",
  "self::assertTrue($reactivated['destination']['active']);",
  "self::assertTrue($deleteBlocked")),
 (HANDOFF,"testManualHandoffRequiresExplicitTenantDestinationAndNeverPublishes",(
  "self::assertSame(1, $futureQueue['total']);",
  "self::assertFalse($futureQueue['provider_calls']);")))),
("SPEC:021","## 4. Non-negotiable invariants",237,(
 "Tenant data must not cross organization boundaries.","Authorization is server-side"),(
 (TENANT,"test_context_scopes_reads_and_forces_organization_on_create",(
  "['Visible A']","TenantScopeRecord::query()->pluck('name')->all()")),
 (TENANT,"test_cross_tenant_create_is_rejected",(
  "$this->expectException(AuthorizationException::class);",
  "'organization_id' => $organizationB->id")))),
("SPEC:022","## 5. Data model principles",252,(
 "organization_id","TenantContext","Cross-tenant"),(
 (TENANT,"test_tenant_models_fail_closed_without_organization_context",(
  "$this->assertSame(0, TenantScopeRecord::query()->count());",)),
 (TENANT,"test_cross_tenant_create_is_rejected",(
  "$this->expectException(AuthorizationException::class);",
  "'organization_id' => $organizationB->id")))),
)

LEX = re.compile(r"""(?P<quoted>"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')
|(?P<comment>/\*.*?\*/|//[^\n]*|\#[^\n]*)
|(?P<shell>\x60(?:\\.|[^\x60\\])*\x60)""", re.S | re.X)
ALLOWED = {"PHPUnit\\Framework\\TestCase",
           "Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase",
           "Symfony\\Bundle\\FrameworkBundle\\Test\\KernelTestCase",
           "Tests\\TestCase"}

def masked(source, strings=True, comments=True):
    chars = list(source)
    for m in LEX.finditer(source):
        is_comment = m.group("comment") is not None
        if (is_comment and comments) or (not is_comment and strings):
            for i in range(m.start(), m.end()):
                if chars[i] not in "\r\n":
                    chars[i] = " "
    return "".join(chars)

def closing_brace(structure, opening):
    depth = 0
    for i in range(opening, len(structure)):
        if structure[i] == "{":
            depth += 1
        elif structure[i] == "}":
            depth -= 1
            if depth == 0:
                return i
    return None

def php_test_method(source, method):
    """Método público en una clase PHPUnit concreta; evita comentarios y clases falsas."""
    if not source.lstrip().startswith("<?php"):
        return None
    structural = masked(source)
    m = list(re.finditer(r"(?m)^\s*(public|protected|private)\s+function\s+" +
                        re.escape(method) + r"\s*\(", structural))
    if len(m) != 1 or m[0].group(1) != "public":
        return None
    start = m[0].start()
    imports = {}
    for use in re.finditer(r"(?m)^\s*use\s+([A-Za-z_]\w*(?:\\[A-Za-z_]\w*)+)"
                           r"(?:\s+as\s+([A-Za-z_]\w*))?\s*;", structural):
        full, alias = use.groups()
        imports.setdefault(alias or full.rsplit("\\",1)[-1], []).append(full)
    owners = 0
    classes = re.finditer(r"\b(?:(abstract|final)\s+)?class\s+\w+\s+extends\s+"
                          r"(\\?[A-Za-z_]\w*(?:\\[A-Za-z_]\w*)*)\b[^{};]*\{", structural)
    for cls in classes:
        if cls.group(1) == "abstract":
            continue
        base = cls.group(2)
        base_ok = base.lstrip("\\") in ALLOWED if "\\" in base else imports.get(base) in ([p] for p in ALLOWED)
        end = closing_brace(structural, cls.end()-1)
        if base_ok and end is not None and cls.end() <= start < end:
            owners += 1
    if owners != 1:
        return None
    opening = structural.find("{",m[0].end())
    if opening < 0 or ";" in structural[m[0].end():opening]:
        return None
    end = closing_brace(structural,opening)
    if end is None:
        return None
    body = source[opening+1:end]
    if re.search(r"\bmarkTest(?:Skipped|Incomplete)\s*\(", masked(body)):
        return None
    return body

def has_assertion(body, assertion):
    # Exige literal exacto y no lo cuenta dentro de comentarios ni cadenas.
    code = masked(body, strings=False, comments=True)
    shape = masked(code)
    wanted = masked(assertion)
    offset = code.find(assertion)
    while offset >= 0:
        if shape[offset:offset+len(assertion)] == wanted:
            return True
        offset = code.find(assertion,offset+1)
    return False

def parse_rows(matrix):
    result = {}
    for line in matrix.splitlines():
        m = re.match(r"^\s*\|\s*(SPEC:0(?:18|19|20|21|22))\s*\|",line)
        if not m:
            continue
        fields = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if m.group(1) in result or len(fields) != 8 or fields[0] != m.group(1):
            raise ValueError("UNKNOWN: fila duplicada o ambigua")
        result[m.group(1)] = fields
    return result

def inspect(requirement, rows, files):
    rid, heading, line, tokens, methods = requirement
    outcome = {"code":UNKNOWN,"ci":UNKNOWN,"production":UNKNOWN,"hostinger":UNKNOWN}
    fields = rows.get(rid)
    if not fields or not fields[1].startswith(heading.lstrip("#").strip()+" — "):
        return outcome
    if fields[3].count(f"[GRINDFLOW-SPEC.md:L{line}]") != 1 or fields[3].count(f"/docs/GRINDFLOW-SPEC.md#L{line})") != 1:
        return outcome
    src = files.get(SPEC,"")
    sections = list(re.finditer(r"(?m)^"+re.escape(heading)+r"[ \t]*$", src))
    if len(sections) != 1:
        return outcome
    section = re.split(r"(?m)^#{1,6}\s+",src[sections[0].end():],maxsplit=1)[0]
    if not all(t in section for t in tokens):
        return outcome
    for path,name,assertions in methods:
        body = php_test_method(files.get(path,""),name)
        if body is None or not all(has_assertion(body,t) for t in assertions):
            return outcome
    outcome["code"]="CONTRATO_LOCAL"
    return outcome

class BrechasGrindflow09Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        paths = {MATRIX,SPEC}|{path for *_,methods in ROWS for path,_,_ in methods}
        cls.files = {p:(ROOT/p).read_text(encoding="utf-8") for p in paths}
        cls.rows = parse_rows(cls.files[MATRIX])

    def test_contrato_principal(self):
        """AC-01: cinco SPEC con aserciones en métodos ejecutables."""
        self.assertEqual({r[0] for r in ROWS},set(self.rows))
        for req in ROWS:
            with self.subTest(spec=req[0]):
                self.assertEqual("CONTRATO_LOCAL",inspect(req,self.rows,self.files)["code"])

    def test_falla_cerrado(self):
        """AC-02: faltantes, duplicados, métodos falsos y clases abstractas -> UNKNOWN."""
        for req in ROWS:
            with self.subTest(spec=req[0]):
                absent = dict(self.rows)
                del absent[req[0]]
                self.assertEqual(UNKNOWN,inspect(req,absent,self.files)["code"])
                missing = dict(self.files)
                missing[req[4][0][0]] = ""
                self.assertEqual(UNKNOWN,inspect(req,self.rows,missing)["code"])
                invalid = dict(self.rows)
                row = list(invalid[req[0]])
                row[3] = "no link"
                invalid[req[0]] = tuple(row)
                self.assertEqual(UNKNOWN,inspect(req,invalid,self.files)["code"])
        sample = next(line for line in self.files[MATRIX].splitlines() if line.startswith("| SPEC:018 |"))
        with self.assertRaises(ValueError):
            parse_rows(self.files[MATRIX]+"\n "+sample)
        for kind,tail,expected in (
            ("abstract class","",False),
            ("final class","self::markTestSkipped('skip');",False),
            ("final class","self::markTestIncomplete('skip');",False),
            ("final class","",True)):
            php = ("<?php\nuse PHPUnit\\Framework\\TestCase;\n"+kind+
                   " ExampleTest extends TestCase {\n public function testEvidence() {\n"
                   "self::assertTrue(true); "+tail+"\n }\n}\n")
            self.assertEqual(expected,php_test_method(php,"testEvidence") is not None)

    def test_sin_datos_reales(self):
        """AC-03: solo archivos locales, sin red/PII/credenciales ni mutaciones."""
        own = (ROOT/"tests/test_brechas_grindflow_09.py").read_text(encoding="utf-8")
        for term in ("requests.", "urllib.request", "subprocess.", "os.environ",
                     "mysql.connector", "DB_PASSWORD", "API_TOKEN"):
            self.assertNotIn(term,own.split("    def test_sin_datos_reales")[0])

    def test_estados_unknown_explicitos(self):
        """La inspección de código no afirma CI/deploy/Hostinger ejecutados."""
        for req in ROWS:
            result = inspect(req,self.rows,self.files)
            self.assertEqual((UNKNOWN,UNKNOWN,UNKNOWN),
                             (result["ci"],result["production"],result["hostinger"]))

if __name__ == "__main__":
    unittest.main()
