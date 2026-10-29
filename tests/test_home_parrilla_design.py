"""Contrato del home público «la parrilla de programación» (docs/DISENO-PARRILLA.md).

Solo inspección estática de la plantilla Blade, la hoja de estilos y la tipografía local.
"""
import hashlib
import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BLADE = ROOT / "resources" / "views" / "welcome.blade.php"
CSS = ROOT / "public" / "css" / "grindflow-parrilla.css"
FONT = ROOT / "public" / "fonts" / "archivo-latin-standard-normal.woff2"
LICENSE = ROOT / "public" / "fonts" / "LICENSE-OFL-archivo.txt"
DOC = ROOT / "docs" / "DISENO-PARRILLA.md"
LOGIN = ROOT / "resources" / "views" / "auth" / "login.blade.php"
WORKSPACE_CSS = ROOT / "public" / "css" / "grindflow-workspace.css"
VIEWS = ROOT / "resources" / "views"

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


def inside_no_preference_block(css: str, offset: int) -> bool:
    """True si el desplazamiento está dentro de un @media (prefers-reduced-motion: no-preference) abierto."""
    stack: list[bool] = []
    index = 0
    while index < offset:
        char = css[index]
        if char == "{":
            header = css[css.rfind("}", 0, index) + 1:index].rsplit(";", 1)[-1]
            stack.append("prefers-reduced-motion: no-preference" in header)
        elif char == "}" and stack:
            stack.pop()
        index += 1
    return any(stack)


class HomeParrillaDesignTests(unittest.TestCase):
    def test_text_on_every_color_meets_wcag_aa(self) -> None:
        t = tokens()
        pairs = [
            ("gp-ink", "gp-ground"), ("gp-ink-2", "gp-ground"), ("gp-ink-2", "gp-paper"),
            ("gp-paper", "gp-lib"), ("gp-paper", "gp-rules"), ("gp-ink", "gp-dist"),
            ("gp-paper", "gp-traf"), ("gp-paper", "gp-ink"), ("gp-ink", "gp-paper"),
            ("gp-line", "gp-ink"),
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
            state = re.search(r"<small>(.*?)</small>", block, flags=re.DOTALL).group(1).strip()
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
        self.assertLess(html.index('<main id="contenido">'), html.index('<section class="gp-hero"'))
        self.assertNotIn("gf-landing__eyebrow", html)

    def test_motion_is_css_only_starts_visible_and_respects_reduced_motion(self) -> None:
        css = text(CSS)
        self.assertNotIn("<script", text(BLADE).lower())
        self.assertIn("@media (prefers-reduced-motion: no-preference)", css)
        animation = css[css.index("@keyframes gp-fill"):]
        self.assertIn("backwards", css)
        self.assertNotIn("forwards", css)
        self.assertNotIn("opacity: 0;", css, "Los bloques deben ser visibles durante el retardo")
        self.assertIn("from { opacity: 1; transform: scaleX(.96); }", animation)
        animation_rules = list(re.finditer(r"\banimation\s*:", css))
        self.assertGreaterEqual(len(animation_rules), 1)
        for rule in animation_rules:
            with self.subTest(offset=rule.start()):
                self.assertTrue(inside_no_preference_block(css, rule.start()), "Toda animación va dentro de no-preference")
        self.assertNotRegex(css, r"transition:\s*all\b")
        self.assertIn("overflow-wrap: anywhere;", css)

    def test_css_has_no_hard_shadows_important_or_third_party_resources(self) -> None:
        css = text(CSS)
        self.assertNotIn("!important", css)
        self.assertIsNone(re.search(r"box-shadow:\s*\d+px\s+\d+px\s+0\b", css))
        self.assertNotRegex(css, r"https?://")
        self.assertEqual(re.findall(r"url\(\s*[\"']?([^\"')\s]+)", css), ["../fonts/archivo-latin-standard-normal.woff2"])
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


class LoginParrillaDesignTests(unittest.TestCase):
    def test_login_uses_the_parrilla_system_and_keeps_its_form_contract(self) -> None:
        html = text(LOGIN)
        self.assertIn("css/grindflow-parrilla.css", html)
        self.assertEqual(html.count("<h1"), 2)
        for stage in ("Biblioteca", "Reglas", "Distribución", "Tráfico"):
            with self.subTest(stage=stage):
                self.assertIn(f">{stage}</li>", html)
        login_form = re.search(
            r'<form[^>]*action="{{ route\(\'login\'\) }}"[^>]*>(.*?)</form>',
            html, flags=re.DOTALL,
        )
        self.assertIsNotNone(login_form, "Se requiere el formulario de login real")
        form = login_form.group(1)
        for field in ('name="email"', 'name="password"', 'name="remember"', "@csrf"):
            with self.subTest(field=field):
                self.assertIn(field, form)
        self.assertNotIn("style=", html)
        self.assertNotIn("gf-kicker", html)

    def test_login_stages_render_without_overflow_at_320_and_360(self) -> None:
        """Chromium real: CSS del Blade, viewport interno exacto, sin red ni sesiones."""
        browser = os.environ.get("GRINDFLOW_BROWSER_CHROME")
        if not browser:
            browser = next(
                (path for name in ("google-chrome", "chromium", "chromium-browser")
                 if (path := shutil.which(name))), None,
            )
        if not browser:
            self.skipTest("Chromium no disponible; el gate browser debe cubrirlo")
        html = text(LOGIN)
        stages = re.search(r'<ol class="gp-login__stages"[^>]*>.*?</ol>', html,
                           flags=re.DOTALL)
        self.assertIsNotNone(stages, "El viewport prueba la lista real del Blade")
        css = text(CSS)
        inner = (
            '<!doctype html><html lang="es"><head>'
            '<meta name="viewport" content="width=device-width, initial-scale=1">'
            '<style>' + css + '</style></head><body class="gp-page gp-login-page">'
            '<main class="gp-login"><section class="gp-login__band">'
            '<div class="gp-login__statement">' + stages.group(0) + '</div>'
            '</section></main></body></html>'
        )
        probe = (
            '<!doctype html><html><body>'
            '<iframe id="mobile320" width="320" height="800"></iframe>'
            '<iframe id="mobile360" width="360" height="800"></iframe>'
            '<output id="mobile-probe" data-result="PENDING"></output>'
            '<script>'
            'const frames=[...document.querySelectorAll("iframe")];'
            'let finished=0;'
            'frames.forEach(frame=>{'
            'frame.addEventListener("load",()=>{'
            'const doc=frame.contentDocument;'
            'const grid=doc.querySelector(".gp-login__stages");'
            'const columns=frame.contentWindow.getComputedStyle(grid).gridTemplateColumns'
            '.trim().split(/\\s+/);'
            'const ok=doc.documentElement.scrollWidth<=frame.contentWindow.innerWidth+1'
            ' && grid.scrollWidth<=grid.clientWidth+1'
            ' && [...grid.children].every(li=>li.scrollWidth<=li.clientWidth+1)'
            ' && columns.length===2;'
            'frame.dataset.result=ok?"PASS":"FAIL";'
            'finished++;'
            'if(finished===frames.length){'
            'document.getElementById("mobile-probe").dataset.result='
            'frames.every(frame=>frame.dataset.result==="PASS")?"PASS":"FAIL";'
            '}'
            '});'
            'frame.srcdoc=' + json.dumps(inner) + ';'
            '});'
            '</script></body></html>'
        )
        with tempfile.TemporaryDirectory(prefix="grindflow-login-mobile-") as directory:
            page = Path(directory) / "probe.html"
            page.write_text(probe, encoding="utf-8")
            result = subprocess.run(
                [browser, "--headless=new", "--no-sandbox",
                 "--disable-dev-shm-usage", "--disable-gpu",
                 "--window-size=900,900", "--virtual-time-budget=4000",
                 "--dump-dom", page.as_uri()],
                capture_output=True, text=True, timeout=25, check=False,
            )
        self.assertEqual(result.returncode, 0, result.stderr[-1200:])
        check = re.search(r'<output id="mobile-probe" data-result="([^"]+)"',
                          result.stdout)
        self.assertIsNotNone(check, "Chromium no ejecutó el probe")
        self.assertEqual(check.group(1), "PASS",
                         "A 320/360 px la lista de etapas debe encajar en dos columnas")

    def test_login_card_text_and_fields_meet_wcag_aa_on_the_parrilla_colors(self) -> None:
        t = tokens()
        for foreground, background in (("gp-ink", "gp-ground"), ("gp-ink-2", "gp-paper"), ("gp-paper", "gp-lib")):
            with self.subTest(pair=f"{foreground} sobre {background}"):
                self.assertGreaterEqual(contrast(t[foreground], t[background]), 4.5)
        css = text(CSS)
        declarations = re.findall(
            r"\.gp-login__card\s+\.gf-input\s*\{([^}]*)\}", css,
            flags=re.DOTALL,
        )
        self.assertTrue(declarations, "No existe CSS para el input del login")
        # Ancla semántica de propiedad: background-color y border-color no son texto.
        colors = [
            color for rule in declarations
            for color in re.findall(r"(?:^|;)\s*color\s*:\s*var\(--(gp-[\w-]+)\)\s*;", rule)
        ]
        backgrounds = [
            color for rule in declarations
            for color in re.findall(r"(?:^|;)\s*background\s*:\s*var\(--(gp-[\w-]+)\)\s*;", rule)
        ]
        self.assertEqual(colors, ["gp-ink"])
        self.assertEqual(backgrounds, ["gp-ground"])
        self.assertGreaterEqual(contrast(t[colors[0]], t[backgrounds[0]]), 4.5,
                                "Texto de input, WCAG 1.4.3")
        border = re.search(
            r"\.gp-login__card \.gf-input \{[^}]*border: 1px solid (#[0-9a-f]{6})",
            css,
        )
        self.assertIsNotNone(border)
        self.assertGreaterEqual(contrast(border.group(1), t[backgrounds[0]]), 3.0,
                                "Borde de campo, WCAG 1.4.11")
        # Mutación deliberada: un background-color no puede suplantar color.
        # Mutar la regla del input, no el body.gp-page que usa los mismos tokens.
        mutated = css.replace(
            ".gp-login__card .gf-input { color: var(--gp-ink); background: var(--gp-ground);",
            ".gp-login__card .gf-input { background-color: var(--gp-ink); background: var(--gp-ground);",
            1,
        )
        self.assertNotEqual(mutated, css, "La mutación debe alcanzar el input real")
        mutated_rules = re.findall(
            r"\.gp-login__card\s+\.gf-input\s*\{([^}]*)\}", mutated,
            flags=re.DOTALL,
        )
        self.assertFalse([
            color for rule in mutated_rules
            for color in re.findall(r"(?:^|;)\s*color\s*:\s*var\(--(gp-[\w-]+)\)\s*;", rule)
        ], "La prueba debe detectar la ausencia del color de texto")


class WorkspaceShellParrillaTests(unittest.TestCase):
    def test_every_workspace_view_loads_the_parrilla_shell_after_the_base_css(self) -> None:
        names = (
            "admin/diagnostics.blade.php", "admin/system.blade.php",
            "dashboard.blade.php", "distribution/index.blade.php",
            "errors/500.blade.php", "finance/index.blade.php",
            "scheduling/index.blade.php", "traffic/index.blade.php",
            "vault/guest-upload.blade.php", "vault/index.blade.php",
        )
        views = [VIEWS / name for name in names]
        for view in views:
            with self.subTest(view=str(view.relative_to(ROOT))):
                html = text(view)
                self.assertLess(html.index("css/grindflow.css"), html.index("css/grindflow-parrilla.css"))
                self.assertLess(html.index("css/grindflow-parrilla.css"), html.index("css/grindflow-workspace.css"))

    def test_shell_css_is_light_flat_and_free_of_third_party_resources(self) -> None:
        css = text(WORKSPACE_CSS)
        self.assertIn("color-scheme: light;", css)
        self.assertNotIn("!important", css)
        self.assertNotIn("http", css)
        self.assertNotIn("@import", css)
        self.assertNotRegex(css, r"box-shadow:\s*\d")
        self.assertNotRegex(css, r"transition:\s*all\b")
        self.assertIn("--gf-shadow: none;", css)

    def test_guest_upload_card_is_light_so_its_ink_text_keeps_contrast(self) -> None:
        css = text(WORKSPACE_CSS)
        self.assertIn(".gf-guest-upload__card { background: #ffffff;", css)
        self.assertIn(".gf-upload-picker__label { background: var(--gf-bg);", css)

    def test_shell_text_colors_meet_wcag_aa_on_their_surfaces(self) -> None:
        css = text(WORKSPACE_CSS)
        tokens = dict(re.findall(r"(--gf-[\w-]+):\s*(#[0-9a-fA-F]{6})\s*;", css))
        rules = re.findall(r"(?m)^\s*([^{\n]+)\{([^{}]*)\}", css)
        selectors = (
            ".gf-navitem:hover", ".gf-navitem--active", ".gf-diagnostic__code",
            ".gf-button--primary", ".gf-button--ghost", ".gf-kicker",
            ".gf-badge", ".gf-avatar", ".gf-state--ok", ".gf-state--error",
            ".gf-state--neutral", ".gf-notice--success",
            ".gf-upload-alert--danger", ".gf-sidebar .gf-brand__mark",
        )
        for selector in selectors:
            with self.subTest(selector=selector):
                candidates = [
                    body for names, body in rules
                    if selector in [name.strip() for name in names.split(",")]
                ]
                self.assertTrue(candidates, "Regla CSS ausente")
                properties = {}
                for body in candidates:
                    properties.update(re.findall(
                        r"(?:^|;)\s*(color|background)\s*:\s*(var\(--gf-[\w-]+\)|#[0-9a-fA-F]{6})\s*(?=;)",
                        body,
                    ))
                self.assertIn("color", properties)
                self.assertIn("background", properties)
                def actual_color(name: str) -> str:
                    value = properties[name]
                    if value.startswith("var("):
                        token = value[4:-1]
                        self.assertIn(token, tokens)
                        return tokens[token]
                    return value
                foreground = actual_color("color")
                background = actual_color("background")
                self.assertGreaterEqual(
                    contrast(foreground, background), 4.5,
                    f"{selector}: {foreground} sobre {background}, WCAG AA",
                )

class HomeImageSlotsTests(unittest.TestCase):
    def test_home_reserves_labeled_image_slots_that_are_decorative_for_screen_readers(self) -> None:
        html = text(BLADE)
        slots = re.findall(r'<figure class="gp-img [^"]*">(.*?)</figure>', html, flags=re.DOTALL)
        self.assertEqual(len(slots), 3)
        for slot in slots:
            with self.subTest(slot=slot.strip()[:40]):
                self.assertIn('aria-hidden="true"', slot)
                self.assertRegex(slot, r"Espacio para imagen · \d:\d · ")
        self.assertNotIn("<img", html)

    def test_slots_keep_a_fixed_ratio_so_real_images_cause_no_layout_shift(self) -> None:
        css = text(CSS)
        self.assertIn("aspect-ratio: var(--gp-img-ratio", css)
        for ratio in ("4 / 5", "4 / 3"):
            with self.subTest(ratio=ratio):
                self.assertIn(f"--gp-img-ratio: {ratio};", css)


if __name__ == "__main__":
    unittest.main()
