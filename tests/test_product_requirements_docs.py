"""Executable contracts for GrindFlow's unified product requirements docs."""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"


def read_doc(name: str) -> str:
    """Return one UTF-8 documentation file from the repository docs folder."""
    return (DOCS / name).read_text(encoding="utf-8")


class ProductRequirementsDocsTests(unittest.TestCase):
    def test_product_requirements_keep_unique_contiguous_ids(self):
        """Keep the current product IDs unique and append-only without gaps."""
        source = read_doc("PRODUCT-REQUIREMENTS.md")
        ids = [
            int(number)
            for number in re.findall(r"^### GF-PROD-(\d{3}) · ", source, re.MULTILINE)
        ]

        self.assertGreaterEqual(len(ids), 42)
        self.assertEqual(len(ids), len(set(ids)))
        self.assertEqual(list(range(1, max(ids) + 1)), ids)

    def test_product_decisions_do_not_turn_open_questions_into_approvals(self):
        """Preserve the explicit boundary between confirmed and pending decisions."""
        source = read_doc("PRODUCT-REQUIREMENTS.md")

        for state in (
            "CONFIRMADO",
            "HIPÓTESIS COMERCIAL",
            "PENDIENTE",
            "BASE EXISTENTE",
        ):
            self.assertIn(state, source)

        self.assertIn("Vigencia/fecha de expiración de contenido", source)
        self.assertIn("Estado: **PENDIENTE**, no confirmado", source)
        self.assertIn("no como tarifas/límites comerciales finales", source)

    def test_traceability_preserves_historical_corpus_without_reexpanding_it(self):
        """Keep historical provenance mapped instead of regenerating 10k rows."""
        trace = read_doc("PRODUCT-CORPUS-TRACEABILITY.md")

        for marker in (
            "#1–#8040",
            "#8041–#8340",
            "#8341–#10000",
            "1.660 definiciones compactadas en 332 capacidades y 17 dominios",
            "#66–#1000",
        ):
            self.assertIn(marker, trace)

        self.assertIn("Confirmado no significa MVP", trace)
        self.assertIn("el corpus `#1–#10000` no se relee en masa otra vez", trace)

    def test_traceability_keeps_the_mvp_critical_path(self):
        """Protect the reconciled MVP sequence while product scope grows."""
        trace = read_doc("PRODUCT-CORPUS-TRACEABILITY.md")

        ordered_markers = (
            "Vault/storage recuperable",
            "Web móvil usable",
            "Composer mínimo",
            "Scheduling",
            "Distribution + primer conector social real",
            "Automatización básica",
            "Traffic/métricas básicas",
            "Onboarding/operación",
            "Piloto",
            "Go/no-go",
        )
        positions = [trace.index(marker) for marker in ordered_markers]
        self.assertEqual(positions, sorted(positions))

    def test_docs_index_declares_product_precedence_and_implementation_boundary(self):
        """Make the canonical reading order discoverable to future contributors."""
        index = read_doc("README.md")

        self.assertIn("PRODUCT-REQUIREMENTS.md", index)
        self.assertIn("PRODUCT-CORPUS-TRACEABILITY.md", index)
        self.assertIn("REQUIREMENTS.md", index)
        self.assertIn("GRINDFLOW-SPEC.md", index)
        self.assertIn("Una decisión de producto no demuestra implementación", index)
        self.assertIn("ampliar la capacidad canónica en lugar de duplicarla", index)


if __name__ == "__main__":
    unittest.main()
