#!/usr/bin/env python3
"""GF#946: matriz de evidencia por requisito, estrictamente offline/fail-closed.

No certifica despliegue, pruebas de navegador realizadas, decisiones comerciales,
datos reales ni cobertura integral. El CI real y el SHA se verifican por separado.
"""
from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
REPORT = ROOT / "docs/verificacion/verificacion-02.md"
ROWS = json.loads(r'''[["REQ:GF-FR-004D","docs/REQUIREMENTS.md","### GF-FR-004D —","tests/test_brechas_grindflow_02.py","REQ:GF-FR-004D","PARCIAL","calendario: SQL paginado, filtros y destinos deshabilitados; falta E2E independiente"],["REQ:GF-FR-007B","docs/REQUIREMENTS.md","### GF-FR-007B —","tests/test_brechas_grindflow_04.py","REQ:GF-FR-007B","PARCIAL","ledger/CSV multimoneda y aislamiento; falta observación integral"],["REQ:GF-FR-006D","docs/REQUIREMENTS.md","### GF-FR-006D —","tests/test_brechas_grindflow_03.py","GF-FR-006D","PARCIAL","enlaces Traffic >100, edición y filtros; falta ejecución completa"],["REQ:GF-FR-004E","docs/REQUIREMENTS.md","### GF-FR-004E —","tests/test_brechas_grindflow_02.py","REQ:GF-FR-004E","PARCIAL","buscadores profundos y old IDs; falta cobertura final de navegador"],["REQ:GF-NFR-006","docs/REQUIREMENTS.md","### GF-NFR-006 —","tests/test_cross_module_security_e2e_contract.py","GF-NFR-006","BRECHA","hoja #636 ya completada con prueba parcial; falta receipt Chromium exact-SHA y reconciliación por consolidación, sin reabrir la hoja"],["REQ:GF-FR-005D","docs/REQUIREMENTS.md","### GF-FR-005D —","tests/test_brechas_grindflow_02.py","REQ:GF-FR-005D","PARCIAL","entregas paginadas por tenant; falta observación desplegada"],["STACK:GF-ARCH-001","docs/STACK-TRANSITION-SYMFONY.md","### GF-ARCH-001 —","tests/test_cobertura_grindflow_04.py","STACK:GF-ARCH-001","EXTERNO","paridad Symfony/Hostinger y PHP observado aún no acreditados"],["STACK:GF-UX-003","docs/STACK-TRANSITION-SYMFONY.md","### GF-UX-003 —","tests/test_brechas_grindflow_17.py","STACK:GF-UX-003","PARCIAL","nav React/preview y CSS; falta recepción Chromium conjunta exact-SHA"],["STACK:GF-UX-002","docs/STACK-TRANSITION-SYMFONY.md","### GF-UX-002 —","tests/test_cobertura_grindflow_04.py","STACK:GF-UX-002","PARCIAL","cabecera home/login/selector; falta E2E completo"],["STACK:GF-ARCH-002","docs/STACK-TRANSITION-SYMFONY.md","### GF-ARCH-002 —","tests/test_data_schema_parity.py","GF-ARCH-002","EXTERNO","paridad de esquema y tenencia real reversible pendiente"],["STACK:GF-ARCH-003","docs/STACK-TRANSITION-SYMFONY.md","### GF-ARCH-003 —","tests/test_cobertura_grindflow_04.py","STACK:GF-ARCH-003","EXTERNO","coexistencia, rollback y smoke exactos sin recibo terminal"],["STACK:GF-OPS-012","docs/STACK-TRANSITION-SYMFONY.md","### GF-OPS-012 —","tests/test_cobertura_grindflow_04.py","STACK:GF-OPS-012","PARCIAL","policy deprecations CI: comprobar reporte vendor y HEAD final"],["STACK:GF-OPS-013","docs/STACK-TRANSITION-SYMFONY.md","### GF-OPS-013 —","tests/test_cobertura_grindflow_04.py","STACK:GF-OPS-013","PARCIAL","pins oficiales Node 24 y sintaxis Ruby; falta acreditar todos los jobs"],["STACK:GF-OPS-014","docs/STACK-TRANSITION-SYMFONY.md","### GF-OPS-014 —","tests/test_cobertura_grindflow_04.py","STACK:GF-OPS-014","PARCIAL","checkout persist-credentials false; falta corrida completa con jobs"],["STACK:GF-FR-008","docs/STACK-TRANSITION-SYMFONY.md","### GF-FR-008 —","tests/test_cobertura_grindflow_04.py","STACK:GF-FR-008","EXTERNO","piloto integral autenticado exige evidencia autorizada y negativa tenant"],["STACK:GF-FR-009","docs/STACK-TRANSITION-SYMFONY.md","### GF-FR-009 —","tests/test_cobertura_grindflow_04.py","STACK:GF-FR-009","DECISION","evaluación comercial humana de cinco participantes no ejecutada"],["STACK:GF-FR-010","docs/STACK-TRANSITION-SYMFONY.md","### GF-FR-010 —","tests/test_cobertura_grindflow_04.py","STACK:GF-FR-010","PARCIAL","notas Vault CRUD privado y tenant; falta DB/HTTP integral"],["STACK:GF-FR-011","docs/STACK-TRANSITION-SYMFONY.md","### GF-FR-011 —","tests/test_cobertura_grindflow_04.py","STACK:GF-FR-011","PARCIAL","clasificación y filtro Vault; faltan negativos de sesión y rol"],["STACK:GF-UX-001","docs/STACK-TRANSITION-SYMFONY.md","### GF-UX-001 —","tests/test_cobertura_grindflow_04.py","STACK:GF-UX-001","PARCIAL","shell Laravel responsive distinto al Symfony; navegador pendiente"],["SPEC:005","docs/GRINDFLOW-SPEC.md","### 1A.3. Público objetivo","tests/test_brechas_grindflow_06.py","SPEC:005","EXTERNO","escalado multisegmento requiere experiencia real de varios perfiles"],["SPEC:007","docs/GRINDFLOW-SPEC.md","#### 1A.4.1. SaaS por suscripción","tests/test_brechas_grindflow_06.py","SPEC:007","DECISION","cobros y precios precisan decisión comercial y proveedor autorizado"],["SPEC:008","docs/GRINDFLOW-SPEC.md","#### 1A.4.2. Piloto inicial","","","DECISION","piloto de cinco creadoras necesita consentimiento/operación real; no simular"],["SPEC:009","docs/GRINDFLOW-SPEC.md","#### 1A.4.3. Prueba gratuita comercial","","","DECISION","30 días y hasta dos redes son propuesta, no política aprobada"],["SPEC:010","docs/GRINDFLOW-SPEC.md","## 2. Arquitectura objetivo confirmada el 20/09/2026","","","DOCUMENTADO","arquitectura objetivo es decisión, no release verificado"],["SPEC:011","docs/GRINDFLOW-SPEC.md","## 3. Arquitectura y límites funcionales","","","DOCUMENTADO","monolito Symfony es objetivo; cutover y paridad sin atestar"]]''')
EXPECTED = {record[0]: record for record in ROWS}
ALLOWED = {"PARCIAL", "EXTERNO", "DECISION", "DOCUMENTADO", "BRECHA"}


def matrix_rows(source: str) -> dict[str, tuple[str, ...]]:
    """Rechazar duplicados, columnas ambiguas y reclasificaciones implícitas."""
    result: dict[str, tuple[str, ...]] = {}
    for line in source.splitlines():
        if not line.startswith("| "):
            continue
        cells = tuple(value.strip() for value in line.strip("|").split("|"))
        if not cells or cells[0] not in EXPECTED:
            continue
        if len(cells) != 8 or cells[0] in result:
            raise AssertionError("UNKNOWN: fila duplicada o inválida: " + cells[0])
        result[cells[0]] = cells
    if set(result) != set(EXPECTED):
        raise AssertionError("UNKNOWN: falta fila del lote 02")
    return result


def source_evidence(row: list[str], files: dict[str, str]) -> str:
    """La existencia y la aserción local no son un recibo operativo."""
    identifier, source, heading, proof, marker, grade, _reason = row
    if grade not in ALLOWED:
        raise AssertionError("UNKNOWN: clasificación inesperada")
    text = files.get(source)
    if not text or text.count(heading) != 1:
        raise AssertionError("UNKNOWN: fuente ausente o ambigua: " + identifier)
    if grade not in {"DOCUMENTADO", "DECISION"} and not proof:
        raise AssertionError("UNKNOWN: requisito ejecutable sin prueba trazada")
    if proof:
        test = files.get(proof)
        if not test or marker not in test:
            raise AssertionError("UNKNOWN: evidencia local ausente: " + identifier)
        if not re.search(r"\bdef test_[a-z_]+\(", test):
            raise AssertionError("UNKNOWN: no existe test ejecutable")
        if not re.search(r"\bassert(?:[A-Z]|\s|\()", test):
            raise AssertionError("UNKNOWN: no existe aserción del test")
    return grade


def report_rows(source: str) -> dict[str, list[str]]:
    found: dict[str, list[str]] = {}
    for line in source.splitlines():
        if not line.startswith("| "):
            continue
        parts = [p.strip() for p in line.strip("|").split("|")]
        if not parts or parts[0] not in EXPECTED:
            continue
        if len(parts) != 4 or parts[0] in found:
            raise AssertionError("UNKNOWN: reporte duplicado/malformado")
        found[parts[0]] = parts
    if set(found) != set(EXPECTED):
        raise AssertionError("UNKNOWN: reporte incompleto")
    return found


def promote(*_claims: object) -> str:
    """No se concede verificación integral con evidencia de repositorio sola."""
    return "NO_ACREDITADO"


class VerificacionGrindflow02Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.report = REPORT.read_text(encoding="utf-8")
        paths = {x[1] for x in ROWS} | {x[3] for x in ROWS if x[3]}
        cls.files = {path: (ROOT / path).read_text(encoding="utf-8")
                     for path in paths}

    def test_25_filas_exactas_con_estado_conservador(self) -> None:
        matrix = matrix_rows(self.matrix)
        self.assertEqual(len(matrix), 25)
        for key, row in EXPECTED.items():
            with self.subTest(id=key):
                self.assertEqual(matrix[key][2],
                                 "SIN_COBERTURA" if key == "REQ:GF-NFR-006" else "NO_VERIFICABLE")
                self.assertEqual(matrix[key][5:8], ("NO_ACREDITADO",) * 3)
                self.assertEqual(source_evidence(row, self.files), row[5])

    def test_reporte_reconcilia_cada_fila_sin_fingir_cobertura(self) -> None:
        report = report_rows(self.report)
        self.assertEqual(len(report), 25)
        for identifier, row in EXPECTED.items():
            with self.subTest(id=identifier):
                entry = report[identifier]
                self.assertEqual(entry[1], row[5])
                self.assertIn(row[1], entry[2])
                if row[3]:
                    self.assertIn(row[3], entry[2])
                self.assertIn(row[6], entry[3])
        self.assertIn("NO_ACREDITADO", self.report)
        self.assertIn("#636", self.report)
        self.assertNotIn("VALIDATED_IN_PRODUCTION", self.report)

    def test_falla_cerrado_ante_mutaciones_fuente_y_regresion(self) -> None:
        rows = matrix_rows(self.matrix)
        item = next(line for line in self.matrix.splitlines()
                    if line.startswith("| REQ:GF-NFR-006 |"))
        for altered in (self.matrix.replace(item + "\n", "", 1),
                        self.matrix + "\n" + item,
                        self.matrix.replace(item, item.replace("SIN_COBERTURA", "CUBIERTO"), 1)):
            if "CUBIERTO" in altered:
                self.assertEqual(matrix_rows(altered)["REQ:GF-NFR-006"][2], "CUBIERTO")
                self.assertNotEqual(matrix_rows(altered)["REQ:GF-NFR-006"][2], rows["REQ:GF-NFR-006"][2])
            else:
                with self.assertRaisesRegex(AssertionError, "UNKNOWN"):
                    matrix_rows(altered)
        for row in ROWS:
            files = dict(self.files)
            files[row[1]] = files[row[1]].replace(row[2], "", 1)
            with self.subTest(source=row[0]), self.assertRaisesRegex(AssertionError, "UNKNOWN"):
                source_evidence(row, files)
            if row[3]:
                altered = dict(self.files)
                altered[row[3]] = altered[row[3]].replace(row[4], "")
                with self.subTest(proof=row[0]), self.assertRaisesRegex(AssertionError, "UNKNOWN"):
                    source_evidence(row, altered)

    def test_gates_operativos_y_cambios_peligrosos_no_se_promueven(self) -> None:
        for fake in (None, {}, {"ci": "success"}, {"browser": True},
                     {"sha": "a" * 40, "status": "VALIDATED_IN_PRODUCTION"},
                     {"owner_approved": True}, {"deployment": "healthy"}):
            self.assertEqual(promote(fake), "NO_ACREDITADO")
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^\s*(?:import|from)\s+(?:requests|urllib|socket|httpx)\b")
        self.assertNotIn("subprocess.run", own)
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")


if __name__ == "__main__":
    unittest.main()
