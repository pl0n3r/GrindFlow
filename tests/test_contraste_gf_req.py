#!/usr/bin/env python3
"""#664: contrato fail-closed para contraste conservador de 28 REQ:GF-*."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
DOC = ROOT / "docs/contraste-gf_req.md"
DETAIL = "[Detalle](contraste-gf_req.md)"
ALLOWED = {"CUBIERTO", "SIN_COBERTURA", "DESCARTADO", "NO_APLICA", "NO_VERIFICABLE"}

EVIDENCE = (
    ("REQ:GF-FR-001", "app/Support/Tenancy/TenantContext.php", "tests/Feature/OrganizationContextTest.php::test_queue_context_revalidates_membership_before_running", 953),
    ("REQ:GF-FR-002", "app/Services/Media/MediaIngestionCoordinator.php", "tests/Feature/MediaIngestionJobTest.php::test_same_source_is_queued_only_once", 953),
    ("REQ:GF-FR-003", "app/Services/Media/MediaProcessingCoordinator.php", "tests/Feature/MediaProcessingJobTest.php::test_processing_job_completes_deterministically_and_retry_is_idempotent", 953),
    ("REQ:GF-FR-004", "app/Services/Scheduling/ContentScheduler.php", "tests/Feature/SchedulingTest.php::test_editor_can_schedule_currently_processed_media_with_explicit_timezone", 953),
    ("REQ:GF-FR-005", "app/Services/Distribution/PublicationDeliveryManager.php", "tests/Feature/DistributionTest.php::test_authentication_failure_is_terminal_and_distinct", 953),
    ("REQ:GF-FR-006", "app/Services/Traffic/TrafficAttributionRecorder.php", "tests/Feature/TrafficAttributionTest.php::test_public_redirect_is_no_cache_and_queues_only_hash", 953),
    ("REQ:GF-FR-006A", "app/Services/Scheduling/ContentScheduler.php", "tests/Feature/ScheduleTrackedLinkTest.php::test_schedule_can_attach_active_link_from_same_organization", 953),
    ("REQ:GF-FR-007", "app/Services/Finance/FinanceLedgerManager.php", "tests/Feature/FinanceTest.php::test_editor_and_model_cannot_manage_finance", 953),
    ("REQ:GF-NFR-001", ".github/workflows/grindflow-ci.yml", "tests/test_brechas_grindflow_04.py::BrechasGrindflow04Tests.test_contrato_principal", 953),
    ("REQ:GF-NFR-002", "scripts/ci-performance-report.py", "tests/test_brechas_grindflow_04.py::BrechasGrindflow04Tests.test_contrato_principal", 953),
    ("REQ:GF-NFR-003", ".github/workflows/grindflow-ci.yml", "tests/test_brechas_grindflow_05.py::BrechasGrindflow05Tests.test_contrato_principal", 953),
    ("REQ:GF-NFR-004", "scripts/operations-health.py", "tests/test_brechas_grindflow_05.py::BrechasGrindflow05Tests.test_contrato_principal", 953),
    ("REQ:GF-NFR-005", "public/css/grindflow.css", "tests/Feature/VisualShellTest.php::test_home_renders_grindflow_visual_shell", 953),
    ("REQ:GF-SEC-001", "app/Support/Security/SecretCipher.php", "tests/Feature/DropboxOAuthConnectionTest.php::test_callback_exchanges_code_and_creates_encrypted_tenant_connection", 953),
    ("REQ:GF-SEC-002", "app/Support/Tenancy/TenantContext.php", "tests/Feature/OrganizationContextTest.php::test_queue_context_rejects_foreign_organization", 953),
    ("REQ:GF-SEC-003", "routes/web.php", "tests/test_brechas_grindflow_06.py::BrechasGrindflow06Tests.test_contrato_principal", 953),
    ("REQ:GF-SEC-004", "app/Support/Operations/ProductionWritePolicy.php", "tests/test_brechas_grindflow_06.py::BrechasGrindflow06Tests.test_contrato_principal", 953),
    ("REQ:GF-MIG-001", "app/Support/Operations/MigrationReadiness.php", "tests/Feature/AdminMigrationReadinessTest.php::test_admin_sees_exact_pending_migration_inventory", 953),
    ("REQ:GF-MIG-002", "app/Support/Tenancy/TenantContext.php", "tests/Feature/AuthenticationTest.php::test_user_can_authenticate_and_logout", 953),
    ("REQ:GF-FR-006B", "app/Services/Traffic/TrafficAttributionRecorder.php", "tests/Feature/TrafficAttributionTest.php::test_daily_csv_export_applies_filters_and_never_leaks_another_tenant", 953),
    ("REQ:GF-FR-006C", "app/Services/Traffic/TrackedLinkManager.php", "tests/Feature/TrafficAttributionTest.php::test_traffic_manager_can_pause_resume_link_without_losing_history", 953),
    ("REQ:GF-OPS-009", "scripts/production-smoke.sh", "tests/test_brechas_grindflow_05.py::BrechasGrindflow05Tests.test_contrato_principal", 953),
    ("REQ:GF-FR-004C", "app/Services/Scheduling/ContentScheduler.php", "tests/Feature/ScheduleTrackedLinkTest.php::test_manager_can_attach_swap_and_detach_a_link_without_losing_metrics", 953),
    ("REQ:GF-FR-004D", "app/Services/Scheduling/ContentScheduler.php", "tests/Feature/SchedulingTest.php::test_calendar_paginates_all_matching_schedules_without_cross_tenant_leaks", 952),
    ("REQ:GF-FR-007B", "app/Services/Finance/FinanceLedgerManager.php", "tests/Feature/FinanceReconciliationTest.php::test_full_reconciliation_csv_and_ledger_pagination_do_not_leak_foreign_records", 952),
    ("REQ:GF-FR-006D", "app/Services/Traffic/TrackedLinkManager.php", "tests/Feature/TrafficAttributionTest.php::test_tracked_link_index_paginates_deep_history_with_filters_and_global_totals", 952),
    ("REQ:GF-FR-004E", "app/Services/Scheduling/ContentScheduler.php", "tests/Feature/SchedulerPickerSearchTest.php::test_media_and_link_search_reach_entries_past_first_hundred_without_tenant_leak", 952),
    ("REQ:GF-FR-005D", "app/Services/Distribution/PublicationDeliveryManager.php", "tests/Feature/DistributionTest.php::test_distribution_history_pages_all_tenant_deliveries_with_stable_order_and_filters", 952),
)


def parse_table(document: str, columns: int) -> list[list[str]]:
    rows = []
    for line in document.splitlines():
        if not line.startswith("| REQ:"):
            continue
        cells = [cell.strip() for cell in re.split(r"(?<!\\)\|", line.strip("|"))]
        if len(cells) != columns:
            raise AssertionError(f"Columnas inválidas para {cells[0]}")
        rows.append(cells)
    return rows


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.document = DOC.read_text(encoding="utf-8")
        cls.all_rows = parse_table(cls.matrix, 8)
        cls.target_rows = [r for r in cls.all_rows
                           if r[0].startswith("REQ:GF-") and DETAIL in r[3]]
        cls.doc_rows = parse_table(cls.document, 5)
        cls.target_by_id = {r[0]: r for r in cls.target_rows}
        cls.doc_by_id = {r[0]: r for r in cls.doc_rows}

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(len(self.target_rows), 28)
        self.assertEqual(len(self.doc_rows), 28)
        self.assertEqual(len(self.doc_by_id), 28, "IDs duplicados en contraste")
        self.assertEqual(len(self.target_by_id), 28, "IDs duplicados en matriz")
        self.assertEqual(set(self.doc_by_id), set(self.target_by_id))
        for identifier, doc in self.doc_by_id.items():
            with self.subTest(id=identifier):
                self.assertIn(doc[2], ALLOWED)
                self.assertEqual(doc[2], self.target_by_id[identifier][2])
                self.assertIn("github.com/pl0n3r/GrindFlow", doc[1])
                self.assertGreater(len(doc[3]), 20)
                self.assertGreater(len(doc[4]), 40)
                self.assertNotEqual(doc[3], "NO_ACREDITADO")

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        for identifier, row in self.doc_by_id.items():
            if row[2] != "CUBIERTO":
                continue
            with self.subTest(id=identifier):
                refs = re.findall(r"tests/(?:Feature|Unit)/[\w/]+\.php::test_[A-Za-z_0-9]+", row[3])
                self.assertTrue(refs, "CUBIERTO sin método de prueba")
                self.assertRegex(row[4], r"PR\s*#\d+")
                for reference in refs:
                    filename, method = reference.split("::", 1)
                    source = (ROOT / filename)
                    self.assertTrue(source.is_file(), filename)
                    self.assertRegex(source.read_text(encoding="utf-8"),
                                     r"\bfunction\s+" + re.escape(method) + r"\s*\(")

    def test_faltante_tiene_issue(self) -> None:
        for identifier, row in self.doc_by_id.items():
            if row[2] != "SIN_COBERTURA":
                continue
            with self.subTest(id=identifier):
                linked = {int(n) for n in re.findall(r"(?<!\w)#(\d+)", row[4])}
                self.assertTrue(linked - {2, 664}, "Falta hoja GAP propia")

    def test_resumen_global_coincide_con_140_filas(self) -> None:
        rows = [line.split("|")[3].strip()
                for line in self.matrix.splitlines()
                if line.startswith(("| REQ:", "| STACK:", "| SPEC:", "| RM-"))]
        self.assertEqual(len(rows), 140)
        actual = Counter(rows)
        fields = re.search(
            r"Resumen: CUBIERTO=(\\d+); EN_CURSO=(\\d+); "
            r"SIN_COBERTURA=(\\d+); DESCARTADO=(\\d+); "
            r"NO_VERIFICABLE=(\\d+); total=(\\d+);",
            self.matrix,
        )
        self.assertIsNotNone(fields)
        expected = dict(zip(
            ("CUBIERTO", "EN_CURSO", "SIN_COBERTURA",
             "DESCARTADO", "NO_VERIFICABLE"),
            map(int, fields.groups()[:5]),
        ))
        self.assertEqual(dict(actual), {k: v for k, v in expected.items() if v})
        self.assertEqual(sum(actual.values()), int(fields.group(6)))

    def test_matriz_actualizada(self) -> None:
        # El estado de otras filas puede cambiar legítimamente tras #759:
        # NO comparar toda la matriz contra un blob pre-#759.
        self.assertEqual(len(self.target_rows), 28)
        self.assertEqual(sum(DETAIL in row[3] for row in self.all_rows), 28)
        self.assertEqual(Counter(row[2] for row in self.target_rows),
                         {"NO_VERIFICABLE": 28})
        self.assertEqual(self.matrix.count("; " + DETAIL), 28)
        rows = []
        for line in self.matrix.splitlines():
            if not line.startswith("| "):
                continue
            cells = [cell.strip() for cell in line.strip("|").split("|")]
            if len(cells) == 8 and cells[2] in ALLOWED:
                rows.append(cells)
        self.assertEqual(len(rows), 140)
        actual = Counter(row[2] for row in rows)
        match = re.search(
            r"Resumen: CUBIERTO=(\d+); EN_CURSO=(\d+); "
            r"SIN_COBERTURA=(\d+); DESCARTADO=(\d+); "
            r"NO_VERIFICABLE=(\d+); total=(\d+);",
            self.matrix,
        )
        self.assertIsNotNone(match)
        self.assertEqual(
            tuple(int(x) for x in match.groups()),
            (actual["CUBIERTO"], 0, actual["SIN_COBERTURA"],
             actual["DESCARTADO"], actual["NO_VERIFICABLE"], len(rows)),
        )

    def test_28_trazas_y_metodos_reales(self) -> None:
        headings = re.findall(
            r"(?m)^### (REQ:GF-[A-Z0-9-]+) — NO_VERIFICABLE$",
            self.document,
        )
        self.assertEqual(len(headings), 28)
        self.assertEqual(set(headings), set(self.doc_by_id))
        for identifier, impl, witness, pr in EVIDENCE:
            with self.subTest(id=identifier):
                self.assertTrue((ROOT / impl).is_file(), impl)
                test_path, method_ref = witness.split("::", 1)
                source = ROOT / test_path
                self.assertTrue(source.is_file(), test_path)
                method = method_ref.split(".")[-1]
                code = source.read_text(encoding="utf-8")
                declaration = (
                    ("function " if test_path.endswith(".php") else "def ")
                    + method + "("
                )
                self.assertIn(declaration, code, witness)
                section = self.document.split(
                    "### " + identifier + " — NO_VERIFICABLE", 1
                )[1].split("\n### ", 1)[0]
                for value in (impl, witness,
                              "https://github.com/pl0n3r/GrindFlow/pull/" + str(pr),
                              "Pendiente específico: ", "NO_ACREDITADOS"):
                    self.assertIn(value, section, identifier + ": " + value)
                self.assertEqual(self.doc_by_id[identifier][2], "NO_VERIFICABLE")
                self.assertEqual(self.target_by_id[identifier][2], "NO_VERIFICABLE")

    def test_mutaciones_de_mapa_fallan_cerrado(self) -> None:
        ids = [row[0] for row in EVIDENCE]
        self.assertEqual(len(ids), len(set(ids)))
        self.assertEqual(set(ids), set(self.doc_by_id))
        for identifier, path, witness, _ in EVIDENCE:
            with self.subTest(id=identifier):
                self.assertTrue((ROOT / path).is_file())
                file_name, method_ref = witness.split("::", 1)
                source = (ROOT / file_name).read_text(encoding="utf-8")
                method = method_ref.split(".")[-1]
                declaration = (
                    ("function " if file_name.endswith(".php") else "def ")
                    + method + "("
                )
                self.assertIn(declaration, source)
                self.assertNotIn(declaration, source.replace(declaration, "", 1))


if __name__ == "__main__":
    unittest.main()
