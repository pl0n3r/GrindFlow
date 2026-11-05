#!/usr/bin/env python3
"""GF#948: trazabilidad offline exacta de 23 contratos SPEC/RM, sin autorizar live."""
from __future__ import annotations

import ast
import importlib
import json
from pathlib import Path
import re
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/GRINDFLOW-SPEC.md"
REPORT = ROOT / "docs/verificacion/verificacion-04.md"
sys.path.insert(0, str(ROOT / "tests"))
ROWS = json.loads(r'''[{"id":"SPEC:038","status":"NO_VERIFICABLE","summary":"19. Scheduler searchable eligible media and tracked-link options — filename or exact UUID, and active links by label, campaign or exact token,","grade":"PARCIAL","source":"docs/GRINDFLOW-SPEC.md","line":451,"heading":"## 19. Scheduler searchable eligible media and tracked-link options","anchor":"exact UUID","witness":"tests/test_brechas_grindflow_12.py::BrechasGrindflow12Tests.test_contrato_principal","child":"-","gap":"El buscador y filtros se ensayan en fuentes locales; faltan sesión Scheduler autenticada, consulta real y recorrido paginado."},{"id":"SPEC:039","status":"NO_VERIFICABLE","summary":"20. Disposable authenticated browser workflow coverage — processed-metadata media options and active tracked links, 27 scheduled","grade":"EXTERNO","source":"docs/GRINDFLOW-SPEC.md","line":470,"heading":"## 20. Disposable authenticated browser workflow coverage","anchor":"27 scheduled","witness":"tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal","child":"-","gap":"El script de navegador define un contrato descartable; falta ejecución autenticada real y recibo exact-SHA del workflow."},{"id":"SPEC:040","status":"NO_VERIFICABLE","summary":"21. Distribution: historial paginado por organización","grade":"PARCIAL","source":"docs/GRINDFLOW-SPEC.md","line":495,"heading":"## 21. Distribution: historial paginado por organización","anchor":"paginado en SQL de 25","witness":"tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal","child":"-","gap":"La prueba paginada de Distribution es sintética; faltan observación SQL y aislamiento real en entorno autorizado."},{"id":"SPEC:041","status":"NO_VERIFICABLE","summary":"22. Observación del release y regresión real-stack aislada — El marcador público de solo lectura /_deployment expone únicamente la versión","grade":"EXTERNO","source":"docs/GRINDFLOW-SPEC.md","line":501,"heading":"## 22. Observación del release y regresión real-stack aislada","anchor":"exact=false","witness":"tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal","child":"-","gap":"El marcador release-only no prueba commit de hosting; se exige observación independiente y regresión real-stack."},{"id":"SPEC:042","status":"NO_VERIFICABLE","summary":"23. S3 weekly review preview and conservative eligibility — active Vault assets only; every data query is joined to the live membership","grade":"PARCIAL","source":"docs/GRINDFLOW-SPEC.md","line":515,"heading":"## 23. S3 weekly review preview and conservative eligibility","anchor":"can_publish=false","witness":"tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal","child":"-","gap":"Elegibilidad semanal se verifica con fixtures; faltan permisos y datos Vault en sesión autenticada."},{"id":"SPEC:043","status":"NO_VERIFICABLE","summary":"24. S3 derived weekly slots — rows are derived at request time and are not persisted schedules, assignments","grade":"PARCIAL","source":"docs/GRINDFLOW-SPEC.md","line":539,"heading":"## 24. S3 derived weekly slots","anchor":"not persisted","witness":"tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal","child":"-","gap":"Los slots derivados se contrastan localmente; falta evidencia de aislamiento y UTC del runtime efectivo."},{"id":"SPEC:044","status":"NO_VERIFICABLE","summary":"25. Safe Symfony runtime readiness — compatible and the stable contract identifier symfony-mariadb-v1.","grade":"EXTERNO","source":"docs/GRINDFLOW-SPEC.md","line":557,"heading":"## 25. Safe Symfony runtime readiness","anchor":"symfony-mariadb-v1","witness":"tests/test_brechas_grindflow_14.py::BrechasGrindflow14Tests.test_contrato_principal","child":"-","gap":"El runtime puede responder degradado; falta observar extensiones, MariaDB y contrato en despliegue real."},{"id":"SPEC:045","status":"NO_VERIFICABLE","summary":"Library — relaciones lógicas de colecciones y variantes (hoja 414, en preparación) — La decisión de producto 42 permite asociar una identidad de Vault a múltipl","grade":"PARCIAL","source":"docs/GRINDFLOW-SPEC.md","line":576,"heading":"### Library — relaciones lógicas de colecciones y variantes (hoja #414, en preparación)","anchor":"storage_key","witness":"tests/test_brechas_grindflow_14.py::BrechasGrindflow14Tests.test_contrato_principal","child":"-","gap":"Relaciones de colecciones y variantes tienen contrato local; faltan migración y transacciones reales verificadas."},{"id":"SPEC:046","status":"NO_VERIFICABLE","summary":"Library — vigencia UTC de assets (hoja 407, decisión 41) — Un asset Symfony del Vault puede tener una ventana de vigencia opcional [valid_from_at, valid_until_a","grade":"PARCIAL","source":"docs/GRINDFLOW-SPEC.md","line":582,"heading":"### Library — vigencia UTC de assets (hoja #407, decisión #41)","anchor":"valid_from_at","witness":"tests/test_brechas_grindflow_14.py::BrechasGrindflow14Tests.test_contrato_principal","child":"-","gap":"Ventanas UTC y exclusión se prueban offline; faltan transacciones reales y autorización de despliegue."},{"id":"RM-342","status":"SIN_COBERTURA","summary":"E2E autenticado integral por tenant, permisos, fechas y estados","grade":"HOJA_PARCIAL","source":"GrindFlow#342","line":0,"heading":"","anchor":"","witness":"tests/test_cross_module_security_e2e_contract.py::CrossModuleSecurityE2ETests.test_guard_rejects_missing_cross_tenant_or_csrf_assertion","child":"#636","gap":"Falta E2E autenticado integral, no solo fixtures de aislamiento y CSRF."},{"id":"RM-343","status":"SIN_COBERTURA","summary":"Observabilidad operativa de 500, jobs y diagnósticos seguros","grade":"HOJA_PARCIAL","source":"GrindFlow#343","line":0,"heading":"","anchor":"","witness":"tests/test_operations_storage_readiness_contract.py::OperationsStorageReadinessTests.test_readonly_health_and_storage_contract","child":"#637","gap":"Falta observación operativa de 5xx, jobs y diagnósticos, con recibos sanitizados."},{"id":"RM-344","status":"SIN_COBERTURA","summary":"Accesibilidad y consistencia responsive transversal","grade":"HOJA_PARCIAL","source":"GrindFlow#344","line":0,"heading":"","anchor":"","witness":"tests/test_transversal_accessibility_contract.py::TransversalAccessibilityTests.test_negative_mutations_fail_closed","child":"#638","gap":"Falta recorrido responsive, teclado y lector en navegador de todas las pantallas."},{"id":"RM-345","status":"SIN_COBERTURA","summary":"Verificación de storage externo, CORS y binarios del hosting","grade":"HOJA_PARCIAL","source":"GrindFlow#345","line":0,"heading":"","anchor":"","witness":"tests/test_operations_storage_readiness_contract.py::OperationsStorageReadinessTests.test_readonly_health_and_storage_contract","child":"#637","gap":"Falta validar storage/CORS/binarios reales del hosting con autorización independiente."},{"id":"RM-346","status":"SIN_COBERTURA","summary":"Revisión transversal de seguridad tenant, CSRF, cifrado y uploads","grade":"HOJA_PARCIAL","source":"GrindFlow#346","line":0,"heading":"","anchor":"","witness":"tests/test_cross_module_security_e2e_contract.py::CrossModuleSecurityE2ETests.test_tenant_csrf_permissions_cases","child":"#636","gap":"Falta auditoría real integral de tenant, CSRF, cifrado y uploads por módulo."},{"id":"RM-347","status":"SIN_COBERTURA","summary":"Conectores y tareas programadas con cuotas, retry y evidencia","grade":"HOJA_PARCIAL","source":"GrindFlow#347","line":0,"heading":"","anchor":"","witness":"tests/test_connector_retry_quota_contract.py::ConnectorRetryQuotaTests.test_negative_mutations_reject_weakened_retry_guards","child":"#639","gap":"Faltan cuotas y reintentos observados en proveedores autorizados; no iniciar conectores."},{"id":"RM-348","status":"SIN_COBERTURA","summary":"Operación de equipos, destinos y reportes cross-module","grade":"BRECHA_PENDIENTE","source":"GrindFlow#348","line":0,"heading":"","anchor":"","witness":"NO_ACREDITADO","child":"#640","gap":"La hoja #640 sigue OPEN/planificada; su testigo de equipos/destinos no existe aún en main."},{"id":"RM-350","status":"SIN_COBERTURA","summary":"Cerrar GF-MIG-003 y ejecutar GF-MIG-004 tras paridad funcional","grade":"HOJA_PARCIAL","source":"GrindFlow#350","line":0,"heading":"","anchor":"","witness":"tests/test_migration_parity_exit_gate.py::MigrationParityExitGateTests.test_fake_full_parity_does_not_bypass_real_matrix_validator","child":"#641","gap":"El gate de paridad offline rechaza retiro sin recibos; faltan paridad y rollback reales autorizados."},{"id":"RM-351","status":"SIN_COBERTURA","summary":"Readiness comercial, soporte, costos y onboarding","grade":"HOJA_PARCIAL","source":"GrindFlow#351","line":0,"heading":"","anchor":"","witness":"tests/test_prelaunch_business_readiness_contract.py::PrelaunchBusinessReadinessTests.test_missing_market_or_support_evidence_is_not_ready","child":"#642","gap":"Faltan costes, soporte, SLA y onboarding aprobados por producto."},{"id":"RM-352","status":"SIN_COBERTURA","summary":"Decidir prueba comercial 30 días / dos redes después del piloto","grade":"HOJA_PARCIAL","source":"GrindFlow#352","line":0,"heading":"","anchor":"","witness":"tests/test_commercial_decisions_fail_closed.py::CommercialDecisionsFailClosedTests.test_no_trial_or_price_without_owner_gate","child":"#643","gap":"La prueba 30 días/dos redes requiere piloto concluido y decisión comercial explícita."},{"id":"RM-353","status":"SIN_COBERTURA","summary":"Definir precios, límites y condiciones de suscripción con evidencia","grade":"HOJA_PARCIAL","source":"GrindFlow#353","line":0,"heading":"","anchor":"","witness":"tests/test_commercial_decisions_fail_closed.py::CommercialDecisionsFailClosedTests.test_no_trial_or_price_without_owner_gate","child":"#643","gap":"Precios y límites carecen de decisión humana y evidencia comercial aprobada."},{"id":"RM-355","status":"SIN_COBERTURA","summary":"Validar segmentos, propuesta de valor y onboarding por modalidad","grade":"HOJA_PARCIAL","source":"GrindFlow#355","line":0,"heading":"","anchor":"","witness":"tests/test_prelaunch_business_readiness_contract.py::PrelaunchBusinessReadinessTests.test_segments_and_modalities_are_not_commercial_authorization","child":"#642","gap":"Segmentos/modalidades y propuesta de valor no cuentan con validación de mercado."},{"id":"RM-356","status":"SIN_COBERTURA","summary":"Readiness y gate final para GrindFlow 1.0.0","grade":"HOJA_PARCIAL","source":"GrindFlow#356","line":0,"heading":"","anchor":"","witness":"tests/test_prelaunch_business_readiness_contract.py::PrelaunchBusinessReadinessTests.test_p0_p1_or_missing_production_identity_vetoes_release","child":"#642","gap":"La salida 1.0.0 requiere gate comercial, técnico y operativo auténtico sin pendientes."},{"id":"RM-358","status":"SIN_COBERTURA","summary":"S7 extensión y escala post-MVP","grade":"HOJA_PARCIAL","source":"GrindFlow#358","line":0,"heading":"","anchor":"","witness":"tests/test_s7_postmvp_scope_gate.py::PostMvpScopeGateTests.test_future_scope_requires_explicit_product_decision","child":"#644","gap":"S7 es post-MVP; falta decisión explícita y prueba de capacidad, no iniciar integraciones."}]''')
EXPECTED = {row["id"]: row for row in ROWS}
IDENTIFIER = re.compile(r"^(?:SPEC:[0-9]{3}|RM-[0-9]{3})$")
FORBIDDEN = ("CUBIERTO", "VALIDATED_IN_PRODUCTION", "READY_FOR_GO_LIVE")


def matrix_rows(document: str) -> dict[str, tuple[str, ...]]:
    seen = {}
    for line in document.splitlines():
        if not line.startswith("| "):
            continue
        cols = tuple(x.strip() for x in line.strip("|").split("|"))
        if not cols or not IDENTIFIER.fullmatch(cols[0]):
            continue
        if cols[0] in seen or len(cols) != 8:
            raise ValueError("UNKNOWN: fila de matriz duplicada o malformada")
        seen[cols[0]] = cols
    for ident in EXPECTED:
        if ident not in seen:
            raise ValueError("UNKNOWN: requisito ausente en matriz: " + ident)
    return seen


def report_rows(document: str) -> dict[str, tuple[str, ...]]:
    found = {}
    for line in document.splitlines():
        if not line.startswith("| "):
            continue
        cols = tuple(x.strip() for x in line.strip("|").split("|"))
        if not cols or not IDENTIFIER.fullmatch(cols[0]):
            continue
        if cols[0] in found or cols[0] not in EXPECTED or len(cols) != 6:
            raise ValueError("UNKNOWN: fila de informe inesperada o duplicada")
        found[cols[0]] = cols
    if set(found) != set(EXPECTED):
        raise ValueError("UNKNOWN: 23 filas incompletas")
    return found


def test_method_exists(source: str, klass: str, method: str) -> bool:
    try:
        tree = ast.parse(source)
    except SyntaxError:
        return False
    for node in tree.body:
        if not isinstance(node, ast.ClassDef) or node.name != klass:
            continue
        for candidate in node.body:
            if not isinstance(candidate, (ast.FunctionDef, ast.AsyncFunctionDef)):
                continue
            if candidate.name != method:
                continue
            for call in ast.walk(candidate):
                if isinstance(call, ast.Assert):
                    return True
                if isinstance(call, ast.Call) and isinstance(call.func, ast.Attribute):
                    if isinstance(call.func.value, ast.Name) and call.func.value.id == "self":
                        if call.func.attr.startswith("assert"):
                            return True
    return False


def validate(matrix: str, spec: str, report: str,
             proofs: dict[str, str]) -> None:
    expected_matrix = matrix_rows(matrix)
    expected_report = report_rows(report)
    spec_lines = spec.splitlines()
    bounds = sorted(row["line"] for row in ROWS if row["line"]) + [len(spec_lines)+1]
    for row in ROWS:
        ident = row["id"]
        fields = expected_matrix[ident]
        if fields[1] != row["summary"] or fields[2] != row["status"]:
            raise ValueError("UNKNOWN: fuente o estado alterado: " + ident)
        if fields[5:8] != ("NO_ACREDITADO",) * 3:
            raise ValueError("UNKNOWN: acreditación falsa: " + ident)
        if row["line"]:
            if spec_lines[row["line"] - 1].strip() != row["heading"]:
                raise ValueError("UNKNOWN: sección SPEC alterada: " + ident)
            end = bounds[bounds.index(row["line"]) + 1]
            section = "\n".join(spec_lines[row["line"]-1:end-1])
            if row["anchor"] not in section:
                raise ValueError("UNKNOWN: ancla SPEC ausente: " + ident)
        else:
            if "#"+ident[3:] not in row["source"]:
                raise ValueError("UNKNOWN: roadmap incoherente: " + ident)
            if row["child"] not in fields[4]:
                raise ValueError("UNKNOWN: hoja canónica no asociada: " + ident)
        displayed_source = (row["source"] + " (L"+str(row["line"])+")"
                            if row["line"] else row["source"]+" / "+row["child"])
        expected = (ident, row["status"], row["grade"], displayed_source,
                    row["witness"], row["gap"])
        if expected_report[ident] != expected:
            raise ValueError("UNKNOWN: informe derivado diferente: " + ident)
        if not row["gap"] or len(row["gap"]) < 38:
            raise ValueError("UNKNOWN: brecha no documentada: " + ident)
        if row["witness"] == "NO_ACREDITADO":
            if ident != "RM-348" or row["grade"] != "BRECHA_PENDIENTE":
                raise ValueError("UNKNOWN: ausencia de testigo no autorizada")
            continue
        path, target = row["witness"].split("::")
        klass, method = target.split(".")
        if not path.startswith("tests/test_") or not path.endswith(".py"):
            raise ValueError("UNKNOWN: ruta de testigo inválida")
        if not test_method_exists(proofs.get(path, ""), klass, method):
            raise ValueError("UNKNOWN: método/aserción ausentes: " + ident)
    if len(EXPECTED) != 23 or sum(r["status"] == "SIN_COBERTURA" for r in ROWS) != 14:
        raise ValueError("UNKNOWN: universo modificado")
    if any(f"| {word} |" in report for word in FORBIDDEN):
        raise ValueError("UNKNOWN: promoción ficticia")


def production(*_signals: object) -> str:
    """Ningún fixture local concede acreditación de CI o de producción."""
    return "NO_ACREDITADO"


class VerificacionGrindflow04Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.report = REPORT.read_text(encoding="utf-8")
        cls.proofs = {path: (ROOT / path).read_text(encoding="utf-8")
                      for path in {r["witness"].split("::")[0] for r in ROWS
                                   if "::" in r["witness"]}}

    def test_23_filas_fuente_y_testigo_exactos(self) -> None:
        validate(self.matrix, self.spec, self.report, self.proofs)
        self.assertEqual(len(report_rows(self.report)), 23)
        self.assertEqual(sum(x["id"].startswith("SPEC:") for x in ROWS), 9)
        self.assertEqual(sum(x["id"].startswith("RM-") for x in ROWS), 14)
        self.assertEqual({x["status"] for x in ROWS},
                         {"SIN_COBERTURA", "NO_VERIFICABLE"})
        self.assertEqual([r["witness"] for r in ROWS
                          if r["witness"] == "NO_ACREDITADO"], ["NO_ACREDITADO"])

    def test_mutaciones_matriz_e_informe_fallan_cerrado(self) -> None:
        target = next(x for x in self.report.splitlines()
                      if x.startswith("| SPEC:038 |"))
        changes = (
            self.report.replace(target+"\n", "", 1),
            self.report.replace(target, target+"\n"+target, 1),
            self.report.replace(target, target.replace("SPEC:038", "SPEC:099"), 1),
            self.report.replace(target, target.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            self.report.replace(target, target.replace("test_contrato_principal",
                                                       "test_ficticio"), 1),
            self.report.replace(target, target.replace(ROWS[0]["gap"],
                                                       "Producción verificada."), 1),
        )
        for text in changes:
            with self.subTest(part=text[:65]), self.assertRaises(ValueError):
                validate(self.matrix, self.spec, text, self.proofs)
        line = next(x for x in self.matrix.splitlines()
                    if x.startswith("| RM-348 |"))
        bad = self.matrix.replace(line, line.replace("SIN_COBERTURA",
                                                      "CUBIERTO", 1), 1)
        with self.assertRaises(ValueError):
            validate(bad, self.spec, self.report, self.proofs)
        bad = self.matrix.replace(line, line.replace(
            "NO_ACREDITADO", "VALIDATED_IN_PRODUCTION", 1), 1)
        with self.assertRaises(ValueError):
            validate(bad, self.spec, self.report, self.proofs)

    def test_anchors_y_metodos_inventados_se_rechazan(self) -> None:
        for corrupt in (
            self.spec.replace(ROWS[0]["heading"], "## SPEC alterado", 1),
            self.spec.replace(ROWS[0]["anchor"], "no existe", 1),
        ):
            with self.assertRaises(ValueError):
                validate(self.matrix, corrupt, self.report, self.proofs)
        ref = ROWS[0]["witness"]
        path = ref.split("::")[0]
        mutated = dict(self.proofs)
        mutated[path] = mutated[path].replace(
            "def test_contrato_principal(", "def test_ficticio(", 1)
        with self.assertRaises(ValueError):
            validate(self.matrix, self.spec, self.report, mutated)
        fake = 'class Fake:\n    def test_false(self):\n        note = "self.assertTrue(True)"\n'
        self.assertFalse(test_method_exists(fake, "Fake", "test_false"))

    def test_regresiones_originales_se_ejecutan(self) -> None:
        """Ejecutar 3 suites SPEC (contrato y negativo) y 8 hojas RM sin red."""
        names = [
            "test_brechas_grindflow_"+code+".BrechasGrindflow"+code+
            "Tests.test_"+kind
            for code in ("12", "13", "14")
            for kind in ("contrato_principal", "falla_cerrado")
        ] + [
            row["witness"].replace("tests/", "").replace(".py::", ".")
            for row in ROWS if row["id"].startswith("RM-")
            and row["witness"] != "NO_ACREDITADO"
        ]
        names = sorted(set(names))
        suite = unittest.defaultTestLoader.loadTestsFromNames(names)
        result = unittest.TestResult()
        suite.run(result)
        self.assertEqual(result.testsRun, len(names), str(result.errors)[-1200:])
        self.assertFalse(result.failures, str(result.failures)[-1200:])
        self.assertFalse(result.errors, str(result.errors)[-1200:])

    def test_no_hay_acreditacion_ni_runtime_externo(self) -> None:
        for signal in (None, True, {"ci": "success"},
                       {"deploy": True}, {"production": "VALIDATED_IN_PRODUCTION"}):
            self.assertEqual(production(signal), "NO_ACREDITADO")
        self.assertEqual(next(r for r in ROWS if r["id"] == "RM-348")["child"], "#640")
        forbidden = {"requests", "urllib", "socket", "httpx", "selenium", "playwright"}
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        for node in tree.body:
            if isinstance(node, ast.Import):
                self.assertFalse({name.name.split(".")[0] for name in node.names}
                                 & forbidden)
            if isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], forbidden)
        self.assertNotIn("| CUBIERTO |", self.report)
        self.assertNotIn("| VALIDATED_IN_PRODUCTION |", self.report)


if __name__ == "__main__":
    unittest.main()
