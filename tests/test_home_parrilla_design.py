"""Contrato del home público «la parrilla de programación» (docs/DISENO-PARRILLA.md).

Solo inspección estática de la plantilla Blade, la hoja de estilos y la tipografía local.
"""
import hashlib
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BLADE = ROOT / "resources" / "views" / "welcome.blade.php"
CSS = ROOT / "public" / "css" / "grindflow-parrilla.css"
FONT = ROOT / "public" / "fonts" / "archivo-latin-standard-normal.woff2"
LICENSE = ROOT / "public" / "fonts" / "LICENSE-OFL-archivo.txt"
DOC = ROOT / "docs" / "DISENO-PARRILLA.md"

# Archivo 5.3.0 tal cual del paquete npm @fontsource-variable/archivo.
FONT_SHA256 = "e3a28eade21a900c7155a247757f4b2834c07bb7ef07ad7efa55cebaac1e8f5e"
STATES = ("Cargado", "Regla activa", "Programado", "En revisión", "Midiendo")


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def luminance(hex_color: str) -> float:
    value = hex_color.lstrip("#")
    channels = [int(value[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    linear = [c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4 for c in channels]
    return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]


def contrast(foreground: str, background: str) -> float:
    a, b = luminance(foreground), luminance(background)
    return (max(a, b) + 0.05) / (min(a, b) + 0.05)


def tokens() -> dict[str, str]:
    root = re.search(r":root\s*\{(.*?)\}", text(CSS), flags=re.DOTALL).group(1)
    return dict(re.findall(r"--(gp-[a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\s*;", root))


class HomeParrillaDesignTests(unittest.TestCase):
    def test_text_on_every_color_meets_wcag_aa(self) -> None:
        t = tokens()
        pairs = [
            ("gp-ink", "gp-ground"), ("gp-ink-2", "gp-ground"), ("gp-ink-2", "gp-paper"),
            ("gp-paper", "gp-lib"), ("gp-paper", "gp-rules"), ("gp-ink", "gp-dist"),
            ("gp-paper", "gp-traf"), ("gp-paper", "gp-ink"), ("gp-ink", "gp-paper"),
        ]
        for foreground, background in pairs:
            with self.subTest(pair=f"{foreground} sobre {background}"):
                self.assertGreaterEqual(contrast(t[foreground], t[background]), 4.5)

    def test_every_grid_row_spans_the_seven_days_and_every_block_states_its_status(self) -> None:
        html = text(BLADE)
        rows = re.findall(r"<tr class=\"gp-row--[a-z]+\">(.*?)</tr>", html, flags=re.DOTALL)
        self.assertEqual(len(rows), 4)
        for row in rows:
            span = sum(int(m or 1) for m in re.findall(r"<td(?: colspan=\"(\d)\")?>", row))
            with self.subTest(row=re.search(r"<th scope=\"row\">(.*?)</th>", row).group(1)):
                self.assertEqual(span, 7)
        blocks = re.findall(r"<div class=\"gp-block[^\"]*\">(.*?)</div>", html, flags=re.DOTALL)
        self.assertGreaterEqual(len(blocks), 10)
        for block in blocks:
            state = re.search(r"<small>(.*?)</small>", block).group(1)
            self.assertTrue(state.startswith(STATES), state)

    def test_the_example_is_labeled_and_the_home_makes_no_external_promises(self) -> None:
        html = text(BLADE)
        self.assertIn("Son datos ilustrativos, no de una cuenta real.", html)
        self.assertIn("Plataforma en desarrollo · Piloto inicial en preparación", html)
        lowered = html.lower()
        for forbidden in ("lanzamiento", "únete", "lista de espera", "garantiz", "100 %", "gratis"):
            with self.subTest(phrase=forbidden):
                self.assertNotIn(forbidden, lowered)
        self.assertEqual(html.count("<h1"), 1)
        self.assertNotIn("gf-landing__eyebrow", html)

    def test_motion_is_css_only_starts_visible_and_respects_reduced_motion(self) -> None:
        css = text(CSS)
        self.assertNotIn("<script", text(BLADE).lower())
        self.assertIn("@media (prefers-reduced-motion: no-preference)", css)
        animation = css[css.index("@keyframes gp-fill"):]
        self.assertIn("backwards", css)
        self.assertNotIn("forwards", css)
        self.assertEqual(css.count("opacity: 0;"), 1, "Solo el fotograma inicial de la animación parte de opacidad 0")
        self.assertIn("from { opacity: 0; transform: scaleX(.2); }", animation)
        self.assertNotRegex(css, r"transition:\s*all\b")

    def test_css_has_no_hard_shadows_important_or_third_party_resources(self) -> None:
        css = text(CSS)
        self.assertNotIn("!important", css)
        self.assertIsNone(re.search(r"box-shadow:\s*\d+px\s+\d+px\s+0\b", css))
        self.assertNotIn("http://", css)
        self.assertEqual(set(re.findall(r"url\(\"([^\"]+)\"\)", css)), {"../fonts/archivo-latin-standard-normal.woff2"})
        for forbidden in ("googleapis", "gstatic", "@import"):
            self.assertNotIn(forbidden, css)

    def test_local_font_is_pinned_and_carries_its_license(self) -> None:
        self.assertEqual(hashlib.sha256(FONT.read_bytes()).hexdigest(), FONT_SHA256)
        self.assertIn("SIL Open Font License", text(LICENSE))
        self.assertIn("@fontsource-variable/archivo", text(DOC))

    def test_version_comes_from_one_source_and_the_docs_describe_the_system(self) -> None:
        html = text(BLADE)
        self.assertIn("{{ config('version.number') }}", html)
        self.assertIsNone(re.search(r"0\.1\.\d+", html))
        doc = text(DOC)
        for token in tokens():
            with self.subTest(token=token):
                self.assertIn(f"`--{token}`", doc)


if __name__ == "__main__":
    unittest.main()
