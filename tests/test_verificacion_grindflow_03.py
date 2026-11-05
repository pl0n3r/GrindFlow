#!/usr/bin/env python3
"""GrindFlow #947: contraste offline/fail-closed de 25 SPEC del lote 03."""
from __future__ import annotations

import ast
import json
from pathlib import Path
import re
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/GRINDFLOW-SPEC.md"
REPORT = ROOT / "docs/verificacion/verificacion-03.md"
ROWS = json.loads(r'''[{"id":"SPEC:012","heading":"### S2 · Biblioteca multimedia privada","suite":"07","anchor":"SHA-256/deduplicación","gap":"Quick upload de fotos y videos pequeños está contrastado en fixtures; faltan sesión real, integridad y flujo autenticado.","grade":"PARCIAL"},{"id":"SPEC:013","heading":"### S2 · Fundamento de direct upload para media grande","suite":"08","anchor":"2 GiB por original","gap":"Port privado y configured=false prueban un fallback local; faltan adaptador autorizado y objetos staged reales.","grade":"PARCIAL"},{"id":"SPEC:014","heading":"### S2 · API de intención y verificación de staging de media grande","suite":"08","anchor":"verified_staging_only","gap":"Intención y complete verifican staging sintético; no acreditan backend S3 real ni registro durable de asset.","grade":"PARCIAL"},{"id":"SPEC:015","heading":"### S2 · Clasificación conservadora de recursos (integrada v0.1.62)","suite":"08","anchor":"unclassified","gap":"Etiquetas de clasificación tienen negativos tenant; no prueban licencia, consentimiento ni permiso de distribución.","grade":"PARCIAL"},{"id":"SPEC:016","heading":"### S2 · Clasificación múltiple explícita (candidato v0.1.63)","suite":"08","anchor":"hasta 30 originales","gap":"Seleccionar 30 activos con rechazo del conjunto completo es contrato local; falta transacción real y navegador.","grade":"PARCIAL"},{"id":"SPEC:017","heading":"### S3 · Revisión humana y readiness interno","suite":"08","anchor":"can_publish=false","gap":"Revisión humana append-only y readiness interno no prueban publicación, derechos ni plataforma externa.","grade":"PARCIAL"},{"id":"SPEC:018","heading":"### S4 · Agenda interna persistida y cancelable","suite":"09","anchor":"draft → cancelled","gap":"Slots y cancelación están modelados con fixtures; falta validar locks concurrencia y persistencia MariaDB real.","grade":"PARCIAL"},{"id":"SPEC:019","heading":"### S4 · Handoff manual auditado","suite":"09","anchor":"provider_calls=false","gap":"Handoff declarado por persona se audita localmente; no contiene acuse de proveedor ni publicación externa.","grade":"PARCIAL"},{"id":"SPEC:020","heading":"### S4 · Destinos manuales y cola interna","suite":"09","anchor":"destination_id","gap":"Destino interno tenant-owned e histórico trazado; falta observar migraciones y reintentos sobre base real.","grade":"PARCIAL"},{"id":"SPEC:021","heading":"## 4. Non-negotiable invariants","suite":"09","anchor":"Tenant data must not cross organization boundaries.","gap":"Guardas multi-tenant contractuales probadas localmente; falta auditar todos los endpoints y jobs autenticados.","grade":"PARCIAL"},{"id":"SPEC:022","heading":"## 5. Data model principles","suite":"09","anchor":"organization_id","gap":"Propiedad organizacional y scopes definidos; falta prueba integral del esquema real con accesos cruzados.","grade":"PARCIAL"},{"id":"SPEC:024","heading":"### Vault S2: notas de trabajo privadas","suite":"10","anchor":"content_prepare","gap":"Notas privadas requieren rol y CSRF; falta recuperación y permiso observado en sesión real del Vault.","grade":"PARCIAL"},{"id":"SPEC:025","heading":"### Identidad Symfony: cambio de contraseña personal","suite":"10","anchor":"Retry-After","gap":"Rotación y rate-limit locales no prueban caché compartido ni sesiones reales bajo concurrencia en hosting.","grade":"PARCIAL"},{"id":"SPEC:026","heading":"## 7. Quality model","suite":"10","anchor":"GrindFlow CI / validate","gap":"Modelo de calidad documentado; cada PR debe comprobar jobs terminales sobre su propio SHA, no por herencia.","grade":"PARCIAL"},{"id":"SPEC:027","heading":"## 8. Migration rule","suite":"10","anchor":"No retirar Laravel","gap":"Coexistencia histórica documentada; no hay prueba total de paridad, rollback observado ni cutover aprobado.","grade":"PARCIAL"},{"id":"SPEC:028","heading":"## 9. Finance core contract","suite":"10","anchor":"append-only revenue-allocation ledger","gap":"Ledger multimoneda y reversas cubiertos en fixtures; faltan triggers y conciliación con base de datos real.","grade":"PARCIAL"},{"id":"SPEC:029","heading":"## 10. Distribution attempt audit","suite":"11","anchor":"idempotency key","gap":"Eventos de intento y fencing inmutable modelados; no constituyen confirmación real de proveedores.","grade":"PARCIAL"},{"id":"SPEC:030","heading":"## 11. Product version and verified deployment identity","suite":"11","anchor":"config/version.php","gap":"Versión humana versionada no demuestra SHA de Hostinger; se necesita recibo autenticado independiente.","grade":"EXTERNO"},{"id":"SPEC:031","heading":"## 12. Traffic daily aggregate export","suite":"11","anchor":"no-store headers","gap":"CSV por días con filtros y mitigación de fórmulas está probado localmente; falta HTTP real autenticado.","grade":"PARCIAL"},{"id":"SPEC:032","heading":"## 13. Traffic link lifecycle and CSV calendar boundaries","suite":"11","anchor":"367","gap":"Pausa, reanudación y borde 366/367 días probados sintéticamente; falta redirección real del mismo token.","grade":"PARCIAL"},{"id":"SPEC:033","heading":"## 14. Authenticated production workspace smoke","suite":"11","anchor":"synthetic E2E production smoke","gap":"Guion de smoke read-only existe; solo recibo productivo de sesión y módulos acredita su ejecución real.","grade":"EXTERNO"},{"id":"SPEC:034","heading":"## 15. Reversible Scheduler-to-Traffic assignment management","suite":"12","anchor":"scoped row lock","gap":"Asociación reversible y rechazo entre tenants ensayados offline; falta transacción real y HTTP autenticado.","grade":"PARCIAL"},{"id":"SPEC:035","heading":"## 16. Scheduler calendar: full filtered pagination","suite":"12","anchor":"scheduled_for_utc, id","gap":"Paginación estable por filtros y 25 items es contrato local; falta navegador y SQL sobre hosting real.","grade":"PARCIAL"},{"id":"SPEC:036","heading":"## 17. Finance filtered beneficiary reconciliation (read-only)","suite":"12","anchor":"allocations-vs-reversals","gap":"Reconciliación monetaria y CSV sin datos sensibles modelados; falta contraste con ledger real y permisos.","grade":"PARCIAL"},{"id":"SPEC:037","heading":"## 18. Traffic link management and complete paginated reporting","suite":"12","anchor":"22-character token","gap":"Edición y lista paginada preservan token en fixtures; falta ensayo HTTP y métricas reales tenant-safe.","grade":"PARCIAL"}]''')
EXPECTED = {row["id"]: row for row in ROWS}
SUITES = ("07", "08", "09", "10", "11", "12")
EXTERNAL = {"SPEC:030", "SPEC:033"}


def parse_matrix(source: str) -> dict[str, tuple[str, ...]]:
    found = {}
    for line in source.splitlines():
        if not line.startswith("| SPEC:"):
            continue
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        if not cells or cells[0] not in EXPECTED:
            continue
        if len(cells) != 8 or cells[0] in found:
            raise ValueError("UNKNOWN: fila matriz ambigua")
        found[cells[0]] = cells
    if set(found) != set(EXPECTED):
        raise ValueError("UNKNOWN: matriz incompleta")
    return found


def section(source: str, heading: str) -> str | None:
    hits = list(re.finditer(r"(?m)^" + re.escape(heading) + r"[ \t]*$", source))
    if len(hits) != 1:
        return None
    remaining = source[hits[0].end():]
    level = len(heading.split(" ", 1)[0])
    end = re.search(r"(?m)^#{2," + str(level) + r"} ", remaining)
    return remaining[:end.start()] if end else remaining


def method_with_assertions(source: str, suite: str) -> bool:
    try:
        tree = ast.parse(source)
    except SyntaxError:
        return False
    name = "BrechasGrindflow" + suite + "Tests"
    classes = [x for x in tree.body
               if isinstance(x, ast.ClassDef) and x.name == name]
    if len(classes) != 1:
        return False
    methods = [x for x in classes[0].body
               if isinstance(x, ast.FunctionDef)
               and x.name == "test_contrato_principal"]
    if len(methods) != 1:
        return False
    return any(
        isinstance(node, ast.Assert) or (
            isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute)
            and isinstance(node.func.value, ast.Name)
            and node.func.value.id == "self"
            and node.func.attr.startswith("assert")
        )
        for node in ast.walk(methods[0])
    )


def parse_report(text: str) -> dict[str, tuple[str, ...]]:
    if text.count("## Auditoría fila a fila") != 1 or (
            text.count("## Pruebas y reversión") != 1):
        raise ValueError("UNKNOWN: informe incompleto")
    lines = text.split("## Auditoría fila a fila", 1)[1].split(
        "## Pruebas y reversión", 1
    )[0]
    found = {}
    for line in lines.splitlines():
        if not line.startswith("| SPEC:"):
            continue
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        if len(cells) != 7 or cells[0] not in EXPECTED or cells[0] in found:
            raise ValueError("UNKNOWN: fila informe ambigua")
        found[cells[0]] = cells
    if set(found) != set(EXPECTED):
        raise ValueError("UNKNOWN: faltan filas del informe")
    return found


def validate(matrix: str, spec: str, report: str,
             suites: dict[str, str]) -> None:
    if len(ROWS) != 25 or len(EXPECTED) != 25:
        raise ValueError("UNKNOWN: 25 claves exactas")
    if {item["suite"] for item in ROWS} != set(SUITES):
        raise ValueError("UNKNOWN: suite faltante")
    original, ledger = parse_matrix(matrix), parse_report(report)
    backtick = chr(96)
    for item in ROWS:
        key = item["id"]
        if (original[key][2] != "NO_VERIFICABLE" or
                original[key][5:] != ("NO_ACREDITADO",) * 3):
            raise ValueError("UNKNOWN: fuente no acreditada " + key)
        if item["grade"] != ("EXTERNO" if key in EXTERNAL else "PARCIAL"):
            raise ValueError("UNKNOWN: dictamen inflado " + key)
        block = section(spec, item["heading"])
        if block is None or item["anchor"].casefold() not in block.casefold():
            raise ValueError("UNKNOWN: norma no verificada " + key)
        if len(item["gap"]) < 75 or item["gap"].endswith((" por", " prueba", " en")):
            raise ValueError("UNKNOWN: brecha sin detalle " + key)
        number = item["suite"]
        path = "tests/test_brechas_grindflow_" + number + ".py"
        script = suites.get(number, "")
        if key not in script or not method_with_assertions(script, number):
            raise ValueError("UNKNOWN: testigo inexistente " + key)
        witness = path + "::BrechasGrindflow" + number + "Tests.test_contrato_principal"
        expected = (
            key, "NO_VERIFICABLE", item["grade"],
            backtick + item["heading"] + backtick,
            backtick + witness + backtick,
            backtick + item["anchor"] + backtick,
            item["gap"],
        )
        if ledger[key] != expected:
            raise ValueError("UNKNOWN: traza alterada " + key)


def production(*_receipts: object) -> str:
    """Código, fixtures y CI no prueban Hostinger."""
    return "NO_ACREDITADO"


class VerificacionGrindflow03Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.report = REPORT.read_text(encoding="utf-8")
        cls.suites = {
            n: (ROOT / ("tests/test_brechas_grindflow_" + n + ".py"))
            .read_text(encoding="utf-8")
            for n in SUITES
        }

    def test_25_filas_exactas_y_estado_conservador(self) -> None:
        validate(self.matrix, self.spec, self.report, self.suites)
        self.assertEqual(len(parse_matrix(self.matrix)), 25)
        self.assertEqual(len(parse_report(self.report)), 25)
        self.assertEqual(len({x["heading"] for x in ROWS}), 25)
        self.assertEqual(sum(x["grade"] == "EXTERNO" for x in ROWS), 2)

    def test_falla_cerrado_ante_falsificacion_de_filas(self) -> None:
        line = next(x for x in self.report.splitlines()
                    if x.startswith("| SPEC:012 |"))
        changes = (
            self.report.replace(line + "\n", "", 1),
            self.report.replace(line, line + "\n" + line, 1),
            self.report.replace(line, line.replace("SPEC:012", "SPEC:099"), 1),
            self.report.replace(line, line.replace("PARCIAL", "EXTERNO"), 1),
            self.report.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            self.report.replace(line, line.replace("test_contrato_principal",
                                                   "test_ficticio"), 1),
        )
        for altered in changes:
            with self.subTest(altered=altered[:40]):
                with self.assertRaises(ValueError):
                    validate(self.matrix, self.spec, altered, self.suites)
        original = next(x for x in self.matrix.splitlines()
                        if x.startswith("| SPEC:012 |"))
        for corrupt in (
            original.replace("NO_VERIFICABLE", "CUBIERTO", 1),
            original.replace("NO_ACREDITADO", "VALIDATED_IN_PRODUCTION", 1),
        ):
            with self.assertRaises(ValueError):
                validate(self.matrix.replace(original, corrupt, 1),
                         self.spec, self.report, self.suites)

    def test_fuente_y_metodo_falsos_son_unknown(self) -> None:
        row = ROWS[0]
        for broken_spec in (
            self.spec.replace(row["heading"], "### Sección incorrecta", 1),
            self.spec + "\n" + row["heading"] + "\n",
            self.spec.replace(row["anchor"], "ancla eliminada", 1),
        ):
            with self.assertRaises(ValueError):
                validate(self.matrix, broken_spec, self.report, self.suites)
        altered = dict(self.suites)
        altered["07"] = altered["07"].replace(
            "def test_contrato_principal(", "def test_ficticio(", 1
        )
        with self.assertRaises(ValueError):
            validate(self.matrix, self.spec, self.report, altered)
        phantom = ("class BrechasGrindflow07Tests:\n"
                   "    def test_contrato_principal(self):\n"
                   "        text = 'self.assertEqual(1, 1)'\n"
                   "        # self.assertEqual(1, 1)\n")
        self.assertFalse(method_with_assertions(phantom, "07"))

    def test_regresiones_negativas_existentes(self) -> None:
        names = [
            "test_brechas_grindflow_" + n + ".BrechasGrindflow" + n +
            "Tests.test_falla_cerrado"
            for n in SUITES
        ]
        suite = unittest.defaultTestLoader.loadTestsFromNames(names)
        result = unittest.TestResult()
        suite.run(result)
        self.assertEqual(result.testsRun, len(names))
        self.assertFalse(result.errors, str(result.errors)[-800:])
        self.assertFalse(result.failures, str(result.failures)[-800:])

    def test_no_simular_produccion(self) -> None:
        self.assertEqual(EXTERNAL, {"SPEC:030", "SPEC:033"})
        for claim in (None, True, {"green": True}, {"deployed": True}):
            self.assertEqual(production(claim), "NO_ACREDITADO")
        self.assertEqual({x["grade"] for x in ROWS}, {"PARCIAL", "EXTERNO"})
        forbidden = {"requests", "urllib", "socket", "httpx", "selenium"}
        source = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                self.assertFalse({m.name.split(".")[0] for m in node.names}
                                 & forbidden)
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], forbidden)


if __name__ == "__main__":
    unittest.main()
