"""Contrato S5: proyección segura de evidencia real del piloto Symfony."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class PilotMvpDashboardContractTests(unittest.TestCase):
    def source(self, relative: str) -> str:
        return (ROOT / relative).read_text(encoding="utf-8")

    def controller(self) -> str:
        return self.source("symfony/src/Http/Controller/PilotWeeklySummaryController.php")

    def panel(self) -> str:
        return self.source("symfony/frontend/admin/PilotWeeklySummaryPanel.tsx")

    def php(self) -> str:
        return self.source("symfony/tests/php/PilotWeeklySummaryTest.php")

    def test_dashboard_reads_tenant_scoped_s4_and_vault(self):
        src = self.controller()
        for token in ("mvpReadModel", "gf_vault_assets", "gf_schedule_drafts",
                      "gf_external_publication_attempts", "available_assets",
                      "upcoming", "deliveries"):
            with self.subTest(token=token):
                self.assertIn(token, src)
        self.assertIn("Métricas del piloto", self.panel())

    def test_cross_tenant_rejected_at_api_and_queries(self):
        src = self.controller()
        for token in ("$memberships->find", "'organization' => $organizationId",
                      "WHERE draft.organization_id = :organization",
                      "WHERE attempt.organization_id = :organization",
                      "asset.organization_id = draft.organization_id"):
            with self.subTest(token=token):
                self.assertIn(token, src)
        php = self.php()
        self.assertIn("[$other, $otherDraft, 'rejected']", php)
        self.assertIn("self::assertStringNotContainsString($other,", php)
        self.assertIn("self::assertSame(0, $data['mvp']['deliveries']['counts']['rejected'])", php)

    def test_external_delivery_safe_projection(self):
        src = self.controller()
        for token in ("CONCAT('schedule-draft:', draft.id, ':facebook_page:v1')",
                      "attempt.status", "published", "ambiguous",
                      "rate_limited", "authentication_failed", "rejected"):
            with self.subTest(token=token):
                self.assertIn(token, src)
        self.assertNotIn("access_token", src.lower())
        self.assertNotIn("request_fingerprint AS", src)
        self.assertIn("deliveryLabels[item.status]", self.panel())

    def test_storage_unique_blobs_and_real_bytes(self):
        src = self.controller()
        for token in ("GROUP BY storage_key", "MAX(size_bytes)",
                      "SUM(original.size_bytes)", "original_blobs"):
            self.assertIn(token, src)
        self.assertIn("self::assertSame(10, $data['mvp']['content']['used_bytes'])", self.php())
        self.assertIn("formatBytes", self.panel())

    def test_partial_schema_keeps_unknown(self):
        src = self.controller()
        for token in ("'ready' => false", "'reason' => 'schema_unavailable'",
                      "'used_bytes' => null", "'counts' => null", "tablesExist("):
            self.assertIn(token, src)
        self.assertIn("No hay evidencia de entregas externas disponible", self.panel())
        self.assertIn("La agenda no está disponible.", self.panel())

    def test_published_requires_canonical_attempt_not_manual_handoff(self):
        src = self.controller()
        self.assertIn("gf_external_publication_attempts attempt", src)
        self.assertIn("draft.delivery_provider = 'facebook_page'", src)
        self.assertIn("attempt.idempotency_key = CONCAT", src)
        self.assertIn("self::assertSame(1, $data['mvp']['deliveries']['counts']['published'])",
                      self.php())
        self.assertIn("'external_publication_id' => $status === 'published'", self.php())

    def test_traffic_not_invented_without_source(self):
        src = self.controller()
        self.assertIn("'traffic' => ['ready' => false, 'clicks' => null]", src)
        self.assertIn("Tráfico atribuible: no disponible", self.panel())
        self.assertIn("self::assertNull($data['mvp']['traffic']['clicks'])", self.php())

    def test_responsive_and_accessible_states(self):
        panel = self.panel()
        css = self.source("symfony/frontend/admin/admin.css")
        for token in ('role="status"', 'role="alert"', '<caption>',
                      '<th scope="col">', 'aria-label', "pilot-day-scroll"):
            self.assertIn(token, panel)
        self.assertIn("@media(max-width:520px){.pilot-totals", css)
        self.assertIn("overscroll-behavior-x:contain", css)
