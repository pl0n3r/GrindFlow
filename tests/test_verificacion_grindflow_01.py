#!/usr/bin/env python3
"""GF#945: auditoría de 25 requisitos con trazabilidad exacta y negativos reales.

Pruebas y documentos locales no acreditan despliegue, cutover ni datos reales.
"""
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
REPORT = ROOT / "docs/verificacion/verificacion-01.md"
ROWS = json.loads(r'''[["REQ:GF-FR-001","Organization isolation — Statement: A user may access only records authorized for their organization","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_01.py::BrechasGrindflow01Tests.test_contrato_principal","Aislamiento cross-tenant probado con fixtures; faltan sesiones y jobs autenticados en servidor exact-SHA."],["REQ:GF-FR-002","Media ingestion — Statement: Authorized users can ingest supported media into the organization","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_01.py::BrechasGrindflow01Tests.test_contrato_principal","Ingesta, deduplicación y rechazos sintéticos; falta flujo real autorizado de almacenamiento y reintento."],["REQ:GF-FR-003","Media processing — Statement: Media can be processed through deterministic background jobs.","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_01.py::BrechasGrindflow01Tests.test_contrato_principal","Procesamiento y reintento idempotente offline; faltan cola, workers y recibos operativos auténticos."],["REQ:GF-FR-004","Scheduling — Statement: Authorized users can schedule eligible content for configured","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_01.py::BrechasGrindflow01Tests.test_contrato_principal","Elegibilidad, zona horaria y destino en pruebas locales; falta flujo Scheduler real desplegado."],["REQ:GF-FR-005","Distribution — Statement: GrindFlow can dispatch eligible scheduled content to supported","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_02.py::BrechasGrindflow02Tests.test_contrato_principal","Contratos de reintento y entrega con datos ficticios; faltan proveedor autorizado y acuse verificable."],["REQ:GF-FR-006","Traffic attribution — Statement: GrindFlow can create tracked links and aggregate attribution data","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_02.py::BrechasGrindflow02Tests.test_contrato_principal","Enlaces y agregación Traffic en entorno sintético; faltan redirecciones y atribución operativas sin PII."],["REQ:GF-FR-006A","Tracked links in scheduling and distribution — Statement: An eligible scheduled publication can optionally carry a","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_03.py::BrechasGrindflow03Tests.test_contrato_principal","Asociación de enlace trazado en pruebas locales; falta persistencia y distribución consentidas en hosting."],["REQ:GF-FR-007","Finance — Statement: Authorized roles can view and manage revenue-allocation records","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_03.py::BrechasGrindflow03Tests.test_contrato_principal","Permisos y ledger aislados en regresiones locales; falta conciliación de movimientos autorizados reales."],["REQ:GF-NFR-001","Maintainability","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_04.py::BrechasGrindflow04Tests.test_contrato_principal","Contratos de calidad locales; falta evidencia sostenida de mantenibilidad del stack Symfony vigente."],["REQ:GF-NFR-002","Fast feedback","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_04.py::BrechasGrindflow04Tests.test_contrato_principal","Definición de feedback y tests offline; faltan latencias y tiempos autenticados de CI/operación."],["REQ:GF-NFR-003","Stable merge gate","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_05.py::BrechasGrindflow05Tests.test_contrato_principal","Reglas de merge modeladas localmente; falta correlación con ruleset efectivo y SHA integrado."],["REQ:GF-NFR-004","Observability","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_05.py::BrechasGrindflow05Tests.test_contrato_principal","Señales y estados UNKNOWN probados con fixtures; faltan lecturas operativas, frescura y alertas."],["REQ:GF-NFR-005","Visual shell","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_05.py::BrechasGrindflow05Tests.test_contrato_principal","Contratos de interfaz versionados; falta inspección real de navegación y accesibilidad en navegador."],["REQ:GF-SEC-001","Secret handling","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_05.py::BrechasGrindflow05Tests.test_contrato_principal","Guardas de secreto en pruebas estáticas; falta auditoría de acceso y rotación en entorno autorizado."],["REQ:GF-SEC-002","Authorization","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_06.py::BrechasGrindflow06Tests.test_contrato_principal","Rechazos de autorización probados localmente; falta sesión real y endpoints mutadores desplegados."],["REQ:GF-SEC-003","CSRF/session","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_06.py::BrechasGrindflow06Tests.test_contrato_principal","Guardas CSRF/sesión en fixtures; faltan cookies y pruebas HTTP de endpoints mutadores reales."],["REQ:GF-SEC-004","Production safety","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_06.py::BrechasGrindflow06Tests.test_contrato_principal","Contrato de seguridad de despliegue offline; faltan backup y rollback observados y autorizados."],["REQ:GF-MIG-001","GF-MIG-001 — Laravel foundation (requisito histórico)","NO_VERIFICABLE","PARCIAL","docs/MIGRATION-LARAVEL.md","tests/test_brechas_grindflow_04.py::BrechasGrindflow04Tests.test_contrato_principal","Fundación Laravel histórica con pruebas locales; Symfony sigue siendo objetivo, paridad y host no acreditados."],["REQ:GF-MIG-002","GF-MIG-002 — Identity and organizations (requisito histórico)","NO_VERIFICABLE","PARCIAL","docs/MIGRATION-LARAVEL.md","tests/test_brechas_grindflow_04.py::BrechasGrindflow04Tests.test_contrato_principal","Identidad/organizaciones Laravel con negativos tenant; falta equivalencia observada durante migración Symfony."],["REQ:GF-MIG-003","GF-MIG-003 — Module parity (requisito histórico)","SIN_COBERTURA","BRECHA","docs/MIGRATION-LARAVEL.md","tests/test_migration_parity_exit_gate.py::MigrationParityExitGateTests.test_fake_full_parity_does_not_bypass_real_matrix_validator","SIN_COBERTURA integral: contrato de paridad parcial en #350/#641; #705 y matriz ejecutable comprueban subcontroles, pero aún hay capacidades diferidas. No retirar módulos ni inferir paridad completa."],["REQ:GF-MIG-004","GF-MIG-004 — Legacy retirement (requisito histórico)","SIN_COBERTURA","BRECHA","docs/MIGRATION-LARAVEL.md","tests/test_migration_parity_exit_gate.py::MigrationParityExitGateTests.test_source_only_plan_and_malformed_receipts_are_never_go","SIN_COBERTURA integral: #350/#641 y hoja #913 ya trazan no-retiro sin paridad y rollback; faltan pruebas reales de redundancia por ruta, backup restaurado, decisión de corte y observación. No borrar legado."],["REQ:GF-FR-006B","Tenant-scoped daily Traffic CSV — Statement: Authorized Traffic managers can download the same filtered daily","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_03.py::BrechasGrindflow03Tests.test_contrato_principal","Filtro y CSV Traffic probados con fixtures; falta descarga autenticada de CSV diario multi-tenant."],["REQ:GF-FR-006C","Tracked-link lifecycle — Statement: Authorized Traffic managers can pause and resume an existing","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_03.py::BrechasGrindflow03Tests.test_contrato_principal","Pausa/reanudación de enlaces simulada; falta persistencia real e historial de métricas en hosting."],["REQ:GF-OPS-009","Production workspace read-only verification — Statement: Synthetic production verification must validate functional","NO_VERIFICABLE","EXTERNO","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_05.py::BrechasGrindflow05Tests.test_contrato_principal","Guion de verificación de workspace sintético read-only; falta recibo autenticado de módulos en hosting exact-SHA."],["REQ:GF-FR-004C","Edit Scheduler-to-Traffic attribution association — Statement: A scheduling manager can add, swap and detach a tracked","NO_VERIFICABLE","PARCIAL","docs/REQUIREMENTS.md","tests/test_brechas_grindflow_01.py::BrechasGrindflow01Tests.test_contrato_principal","Cambio de enlace Scheduler-Traffic con negativos sintéticos; faltan integridad y acuse pre-entrega en entorno real."]]''')
EXPECTED = {item[0]: item for item in ROWS}
GRADES = {"PARCIAL", "EXTERNO", "BRECHA"}


def matrix_rows(raw: str) -> dict[str, list[str]]:
    """Exigir exactamente las 25 claves, sin duplicados ni acreditación ficticia."""
    found = {}
    for line in raw.splitlines():
        if not line.startswith("| REQ:GF-"):
            continue
        cells = [part.strip() for part in line.strip("|").split("|")]
        if not cells or cells[0] not in EXPECTED:
            continue
        if len(cells) != 8 or cells[0] in found:
            raise ValueError("UNKNOWN: matriz duplicada/malformada: " + cells[0])
        found[cells[0]] = cells
    if set(found) != set(EXPECTED):
        raise ValueError("UNKNOWN: inventario matriz incompleto")
    return found


def report_rows(raw: str) -> dict[str, list[str]]:
    """Las filas ajenas, truncadas o repetidas nunca pasan desapercibidas."""
    if raw.count("## Evidencia por fila") != 1 or raw.count("## GF-MIG-003") != 1:
        raise ValueError("UNKNOWN: secciones del informe incorrectas")
    section = raw.split("## Evidencia por fila", 1)[1].split(
        "## GF-MIG-003", 1
    )[0]
    found = {}
    for line in section.splitlines():
        if not line.startswith("| REQ:GF-"):
            continue
        cells = [part.strip() for part in line.strip("|").split("|")]
        if (len(cells) != 7 or cells[0] in found or
                cells[0] not in EXPECTED):
            raise ValueError("UNKNOWN: fila informe extra/duplicada/malformada")
        found[cells[0]] = cells
    if set(found) != set(EXPECTED):
        raise ValueError("UNKNOWN: faltan filas del informe")
    return found


def method_has_assertion(path: str, source: str) -> bool:
    """Exigir un método en la clase citada y una aserción Python real."""
    try:
        _, target = path.split("::", 1)
        class_name, method_name = target.split(".", 1)
        tree = ast.parse(source)
    except (ValueError, SyntaxError):
        return False
    classes = [node for node in tree.body
               if isinstance(node, ast.ClassDef) and node.name == class_name]
    if len(classes) != 1:
        return False
    methods = [node for node in classes[0].body
               if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
               and node.name == method_name and node.name.startswith("test_")]
    if len(methods) != 1:
        return False
    return any(
        isinstance(node, ast.Assert) or (
            isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute)
            and isinstance(node.func.value, ast.Name)
            and node.func.value.id == "self"
            and node.func.attr.startswith(("assert", "expectException"))
        )
        for node in ast.walk(methods[0])
    )


def validate(report: str, matrix: str, files: dict[str, str]) -> None:
    """Nunca convertir inventario o fixture local en prueba integral."""
    ledger = report_rows(report)
    actual = matrix_rows(matrix)
    if len(ROWS) != 25 or len(EXPECTED) != 25:
        raise ValueError("UNKNOWN: 25 filas requeridas")
    for key, req, original, grade, source, probe, gap in ROWS:
        if grade not in GRADES or len(gap) < 45 or gap.endswith((" por", " prueba", " en")):
            raise ValueError("UNKNOWN: brecha truncada " + key)
        if (actual[key][1] != req or actual[key][2] != original or
                actual[key][5:8] != ["NO_ACREDITADO"] * 3):
            raise ValueError("UNKNOWN: matriz/estado alterados " + key)
        if original != ("SIN_COBERTURA" if key in
                       {"REQ:GF-MIG-003", "REQ:GF-MIG-004"} else "NO_VERIFICABLE"):
            raise ValueError("UNKNOWN: promoción histórica " + key)
        if ledger[key] != [
            key, original, grade, req,
            "`" + source + "`",
            "`" + probe + "`",
            gap,
        ]:
            raise ValueError("UNKNOWN: veredicto/testigo no correlacionado " + key)
        source_text = files.get(source)
        marker = key.removeprefix("REQ:")
        if not source_text or len(re.findall(
                r"(?m)^### " + re.escape(marker) + r" [—-] ",
                source_text)) != 1:
            raise ValueError("UNKNOWN: fuente normativa ausente " + key)
        target = probe.split("::", 1)[0]
        test_text = files.get(target)
        if (not test_text or marker not in test_text or
                not method_has_assertion(probe, test_text)):
            raise ValueError("UNKNOWN: testigo ejecutable ausente " + key)
        if not (ROOT / source).is_file() or not (ROOT / target).is_file():
            raise ValueError("UNKNOWN: archivo faltante " + key)


def accreditation(*_claims: object) -> str:
    """La fuente local no concede autoridad de producción ni estado CUBIERTO."""
    return "NO_ACREDITADO"


class VerificacionGrindflow01Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.report = REPORT.read_text(encoding="utf-8")
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        paths = {item[4] for item in ROWS} | {
            item[5].split("::", 1)[0] for item in ROWS
        }
        cls.files = {path: (ROOT / path).read_text(encoding="utf-8")
                     for path in paths}

    def test_trazabilidad_25_filas_sin_promocion(self) -> None:
        """Cada fila coincide con el requisito original y testigo ejecutable."""
        validate(self.report, self.matrix, self.files)
        self.assertEqual(len(report_rows(self.report)), 25)
        self.assertEqual(sum(item[2] == "SIN_COBERTURA" for item in ROWS), 2)
        self.assertEqual(sum(item[2] == "NO_VERIFICABLE" for item in ROWS), 23)
        self.assertNotIn("| CUBIERTO |", self.report)
        self.assertIn("#913", self.report)
        self.assertIn("#641", self.report)

    def test_mutaciones_fallan_cerrado(self) -> None:
        """Detectar ID ausente, extra, duplicado, testigo falso y estado inflado."""
        line = next(row for row in self.report.splitlines()
                    if row.startswith("| REQ:GF-FR-001 |"))
        mutations = (
            self.report.replace(line, "", 1),
            self.report.replace(line, line + "\n" + line, 1),
            self.report.replace(line, line.replace("REQ:GF-FR-001", "REQ:GF-FR-999"), 1),
            self.report.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            self.report.replace(line, line.replace("PARCIAL", "CUBIERTO"), 1),
            self.report.replace(line, line.replace("test_contrato_principal", "test_inventado"), 1),
            self.report.replace(line, line.replace(ROWS[0][6], "Producción verificada."), 1),
        )
        for changed in mutations:
            with self.subTest(change=changed[:20]):
                self.assertNotEqual(changed, self.report)
                with self.assertRaises(ValueError):
                    validate(changed, self.matrix, self.files)
        matrix_line = next(row for row in self.matrix.splitlines()
                           if row.startswith("| REQ:GF-MIG-003 |"))
        changed = self.matrix.replace(
            matrix_line,
            matrix_line.replace("SIN_COBERTURA", "CUBIERTO", 1), 1
        )
        with self.assertRaises(ValueError):
            validate(self.report, changed, self.files)

    def test_fuentes_y_metodos_mutados_fallan(self) -> None:
        """Una fuente inventada o método renombrado pierde su acreditación."""
        source = ROWS[0][4]
        corrupted = dict(self.files)
        corrupted[source] = corrupted[source].replace(
            "### GF-FR-001 —", "### GF-FR-001-ROTO —", 1
        )
        with self.assertRaises(ValueError):
            validate(self.report, self.matrix, corrupted)
        probe = ROWS[0][5]
        path = probe.split("::", 1)[0]
        corrupted = dict(self.files)
        corrupted[path] = corrupted[path].replace(
            "def test_contrato_principal(", "def test_inventado(", 1
        )
        with self.assertRaises(ValueError):
            validate(self.report, self.matrix, corrupted)

    def test_probe_ignora_afirmaciones_ficticias(self) -> None:
        """Comentarios, cadenas y métodos de clases ajenas no acreditan prueba."""
        probe = "tests/fake.py::EvidenceTests.test_contrato_principal"
        phantom = (
            "class EvidenceTests:\n"
            "    def test_contrato_principal(self):\n"
            "        value = 'self.assertTrue(True)'\n"
            "        # self.assertTrue(True)\n"
            "        helper.assertTrue(True)\n"
        )
        self.assertFalse(method_has_assertion(probe, phantom))
        self.assertFalse(method_has_assertion(probe,
                         phantom.replace("class EvidenceTests", "class Other")))
        self.assertFalse(method_has_assertion(probe,
                         "class EvidenceTests:\n"
                         "    def test_other(self):\n"
                         "        self.assertTrue(True)\n"))
        self.assertTrue(method_has_assertion(
            probe, phantom.replace("helper.assertTrue(True)",
                                   "self.assertTrue(True)")))

    def test_regresiones_negativas_preexistentes(self) -> None:
        """Ejecutar fixtures de las seis brechas y del veto real GF-MIG-003/004."""
        names = [
            f"test_brechas_grindflow_0{i}.BrechasGrindflow0{i}Tests.test_falla_cerrado"
            for i in range(1, 7)
        ] + [
            "test_migration_parity_exit_gate.MigrationParityExitGateTests.test_fake_full_parity_does_not_bypass_real_matrix_validator",
            "test_migration_parity_exit_gate.MigrationParityExitGateTests.test_source_only_plan_and_malformed_receipts_are_never_go",
        ]
        suite = unittest.defaultTestLoader.loadTestsFromNames(names)
        result = unittest.TestResult()
        suite.run(result)
        self.assertEqual(result.testsRun, len(names))
        self.assertFalse(result.failures, str(result.failures)[-800:])
        self.assertFalse(result.errors, str(result.errors)[-800:])

    def test_solo_sintetico_y_no_live(self) -> None:
        """Ningún fixture o confirmación falsa cambia la autoridad operativa."""
        for claim in (None, True, {"success": True},
                      {"production": "VALIDATED_IN_PRODUCTION"}):
            self.assertEqual(accreditation(claim), "NO_ACREDITADO")
        self.assertEqual({item[3] for item in ROWS}, GRADES)
        own = Path(__file__).read_text(encoding="utf-8")
        blocked = {"requests", "urllib", "httpx", "socket", "selenium", "playwright"}
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                self.assertFalse({n.name.split(".")[0] for n in node.names} & blocked)
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], blocked)
        self.assertNotIn("| CUBIERTO |", self.report)


if __name__ == "__main__":
    unittest.main()
