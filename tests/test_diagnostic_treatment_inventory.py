"""Regresión offline del inventario JSONL de diagnósticos y privacidad."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT_SHA256 = {
    "politica-tratamiento.md": "079d996b4990e05dfac11d4ccca71d8dd48825b7833682c3850f8451ed0a5460",
    "aviso-privacidad.md": "0ddc1aa96857a917eca201f98be996d21e2b7533996a8a87fa9bbfb8bdd9b283",
    "registro-tratamientos.md": "55205ee6ee8912aeb4e06a7d809ec9aad13b61202ed9eecbc3f67e861fbb501c",
    "retencion.md": "07fe532dd8fb2cd0f80aef7579b09dae739b161a2f2434e6fa86b3037caccfeb",
    "terminos-condiciones.md": "a8bab030d4056482b7153d17695ec119f0715805d430130e27dc4a3fa7b64840",
    "canal-derechos.md": "d8568464c8cd9bc04140846ee9dd30d65b3f5cb1564a387f10f268bede03e297"
}
DIAGNOSTIC_FIELDS = [
    "timestamp",
    "incident_id",
    "status",
    "exception",
    "message",
    "location",
    "request.source",
    "request.method",
    "request.path",
    "request.route",
    "request.user_id",
    "request.organization_id",
    "trace.file",
    "trace.line",
    "trace.call"
]
LEGACY_CLICKS = {
    "id": "legacy_supabase_link_clicks",
    "category": "usage",
    "fields": ["tracking_link_id", "occurred_at", "country", "referrer", "ua_family", "network"],
    "purpose": "traffic_click_analytics",
    "basis": "review_required",
    "retention": "review_required",
    "consent": "review_required",
    "providers": ["supabase"],
}


class DiagnosticTreatmentInventoryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        cls.source = (ROOT / "app/Support/Diagnostics/DiagnosticLog.php").read_text(encoding="utf-8")
        cls.row = next(row for row in cls.data["treatments"] if row["id"] == "diagnostic_incidents")

    def test_diagnostic_fields_match_actual_producer(self):
        self.assertEqual(self.row["category"], "usage")
        self.assertEqual(self.row["purpose"], "application_error_diagnostics")
        self.assertEqual(self.row["providers"], [])
        self.assertEqual(self.row["fields"], DIAGNOSTIC_FIELDS)
        for persisted in (
            "'timestamp' =>", "'incident_id' =>", "'status' =>",
            "'exception' =>", "'message' =>", "'location' =>",
            "'request' =>", "'trace' =>", "'source' => 'console'",
            "'source' => 'http'", "'method' =>", "'path' =>",
            "'route' =>", "'user_id' =>", "'organization_id' =>",
            "'file' =>", "'line' =>", "'call' =>",
        ):
            with self.subTest(persisted=persisted):
                self.assertIn(persisted, self.source)
        self.assertIn("private const int MAX_FILE_BYTES = 2_000_000;", self.source)
        self.assertIn("diagnostics-", self.source)

    def test_generated_documents_and_existing_link_clicks_remain_exact(self):
        rows = [x for x in self.data["treatments"] if x["id"] == "legacy_supabase_link_clicks"]
        self.assertEqual(rows, [LEGACY_CLICKS])
        self.assertEqual(len([x for x in self.data["treatments"] if x["id"] == "diagnostic_incidents"]), 1)
        for name, expected_sha256 in DOCUMENT_SHA256.items():
            with self.subTest(name=name):
                content = (ROOT / "docs" / "privacidad" / name).read_bytes()
                self.assertEqual(hashlib.sha256(content).hexdigest(), expected_sha256)
                if name in ("canal-derechos.md", "terminos-condiciones.md"):
                    self.assertNotIn(b"diagnostic_incidents", content)
                else:
                    self.assertIn(b"diagnostic_incidents", content)

    def test_legal_unknown_and_retention_are_not_misrepresented(self):
        self.assertEqual(self.data["phase"], "construccion")
        self.assertEqual(set(self.row), {
            "id", "category", "fields", "purpose", "basis", "retention", "consent", "providers"
        })
        for key in ("basis", "consent", "retention"):
            self.assertEqual(self.row[key], "review_required")
        self.assertEqual(set(self.data["controller"].values()), {"[COMPLETAR POR EL DUEÑO]"})
        runbook = (ROOT / "docs/DIAGNOSTICS.md").read_text(encoding="utf-8")
        for term in ("UNKNOWN", "2_000_000", "3 días", "platform_role=admin",
                     "no aplica", "review_required", "sin argumentos"):
            with self.subTest(term=term):
                self.assertIn(term, runbook)


if __name__ == "__main__":
    unittest.main()
