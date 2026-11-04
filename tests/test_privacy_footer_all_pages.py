"""Regresión source-only de enlaces accesibles de privacidad en todas las páginas Blade.

Ningún test hace requests HTTP, usa sesiones ni interpreta datos personales.
El error 500 mantiene un enlace literal que no llama al router durante una excepción.
"""
from __future__ import annotations

from html.parser import HTMLParser
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PAGES = ROOT / "resources/views"
RIGHTS = "/privacidad-y-mis-derechos"
LABEL = "Privacidad y mis derechos"
COMPONENT = '<x-release-footer context="public" />'
LINK = '<a href="/privacidad-y-mis-derechos">Privacidad y mis derechos</a>'
REMAINING = (
    "auth/login.blade.php",
    "errors/500.blade.php",
    "vault/guest-upload.blade.php",
)
INERT = frozenset({
    "head", "title", "textarea", "select", "option", "optgroup",
    "xmp", "noembed", "noframes", "noscript", "datalist", "template", "script",
})


def _without_blade_comments(source: str) -> str:
    return re.sub(r"{{--.*?--}}", "", source, flags=re.DOTALL)


def _php_route_active(source: str) -> bool:
    # Comments cannot satisfy a PHP route declaration; URL and name must be
    # in the *same* statement, not two unrelated routes.
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.DOTALL)
    source = "\n".join(
        line for line in source.splitlines()
        if not re.match(r"^\s*(?://|#)", line)
    )
    pattern = (
        r"Route::view\(\s*['\"]/privacidad-y-mis-derechos['\"]\s*,"
        r"\s*['\"]privacy\.rights['\"]\s*\)"
        r"\s*->name\(\s*['\"]privacy\.rights['\"]\s*\)"
    )
    return bool(re.search(pattern, source))


class VisibleRightsLinkParser(HTMLParser):
    """Fail closed for inert/hidden links, ambiguous attrs and altered names."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.stack: list[tuple[str, bool]] = []
        self.component = False
        self.link = False
        self._candidate = False
        self._label: list[str] = []

    def _allowed(self) -> bool:
        return not any(blocked for _name, blocked in self.stack)

    @staticmethod
    def _valid_attributes(attrs: list[tuple[str, str | None]]) -> dict[str, str | None] | None:
        names = [name for name, _value in attrs]
        if len(names) != len(set(names)):
            return None
        return dict(attrs)

    @staticmethod
    def _hidden(tag: str, attrs: dict[str, str | None] | None) -> bool:
        if attrs is None or tag in INERT:
            return True
        style = (attrs.get("style") or "").lower().replace(" ", "")
        if "display:none" in style or "visibility:hidden" in style:
            return True
        if "hidden" in attrs or "inert" in attrs or "disabled" in attrs:
            return True
        if (attrs.get("aria-hidden") or "").lower() == "true":
            return True
        if (attrs.get("aria-disabled") or "").lower() == "true":
            return True
        if (attrs.get("role") or "").lower() in {"none", "presentation"}:
            return True
        if "tabindex" in attrs:
            try:
                if int(attrs["tabindex"] or "") < 0:
                    return True
            except ValueError:
                return True
        return False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        data = self._valid_attributes(attrs)
        blocked = not self._allowed() or self._hidden(tag, data)
        self.stack.append((tag, blocked))
        if blocked or data is None:
            return
        if tag == "x-release-footer" and data.get("context") in (None, "public", "workspace"):
            self.component = True
        if tag == "a" and not self._candidate:
            href = data.get("href")
            if href not in {RIGHTS, "{{ route('privacy.rights') }}"}:
                return
            if "aria-labelledby" in data:
                return  # no DOM ID resolution: fail closed
            name = data.get("aria-label")
            if name is not None and name.strip() != LABEL:
                return
            self._candidate = True
            self._label = [name] if name is not None else []

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)
        self.handle_endtag(tag)

    def handle_data(self, data: str) -> None:
        if self._candidate:
            self._label.append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag == "a" and self._candidate:
            if " ".join(" ".join(self._label).split()) == LABEL:
                self.link = True
            self._candidate = False
            self._label = []
        for i in range(len(self.stack) - 1, -1, -1):
            if self.stack[i][0] == tag:
                del self.stack[i:]
                break


def _parse(source: str) -> VisibleRightsLinkParser:
    parser = VisibleRightsLinkParser()
    parser.feed(_without_blade_comments(source))
    parser.close()
    return parser


def _page_has_rights_link(source: str, *, footer_valid: bool) -> bool:
    parser = _parse(source)
    return parser.link or (parser.component and footer_valid)


class PrivacyFooterAllPagesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.footer = (PAGES / "components/release-footer.blade.php").read_text(encoding="utf-8")
        cls.routes = (ROOT / "routes/web.php").read_text(encoding="utf-8")
        cls.pages = {
            path.relative_to(PAGES).as_posix(): path.read_text(encoding="utf-8")
            for path in PAGES.rglob("*.blade.php")
            if "components" not in path.relative_to(PAGES).parts
        }

    def _footer_valid(self) -> bool:
        p = _parse(self.footer)
        return p.link and _php_route_active(self.routes)

    def test_criterio_principal(self) -> None:
        """AC-01/02: todas las páginas, incluidas nuevas, tienen derechos accesibles."""
        self.assertTrue(self._footer_valid(), "El footer y la ruta global deben ser reales")
        self.assertGreaterEqual(len(self.pages), 13, "Incluir la página añadida por #785")
        self.assertTrue(set(REMAINING) <= self.pages.keys())
        for name, source in sorted(self.pages.items()):
            with self.subTest(page=name):
                self.assertTrue(
                    _page_has_rights_link(source, footer_valid=self._footer_valid()),
                    f"Falta un enlace de derechos accesible: {name}",
                )

    def test_mutaciones_en_tres_vistas_fallan(self) -> None:
        """Retirar individualmente la integración de cualquiera de las tres falla."""
        for name in REMAINING:
            with self.subTest(page=name):
                source = self.pages[name]
                self.assertIn(COMPONENT if name != "errors/500.blade.php" else LINK, source)
                altered = source.replace(
                    COMPONENT if name != "errors/500.blade.php" else LINK, "", 1
                )
                self.assertFalse(_page_has_rights_link(altered, footer_valid=True))

    def test_componente_y_ruta_globales(self) -> None:
        self.assertTrue(self._footer_valid())
        self.assertFalse(_php_route_active("// " + "Route::view('/privacidad-y-mis-derechos', 'privacy.rights')->name('privacy.rights');"))
        self.assertFalse(_php_route_active("/* Route::view('/privacidad-y-mis-derechos', 'privacy.rights')->name('privacy.rights'); */"))
        self.assertFalse(_php_route_active(
            "Route::view('/privacidad-y-mis-derechos', 'privacy.rights');"
            "Route::view('/diferente', 'privacy.rights')->name('privacy.rights');"
        ))
        self.assertFalse(_parse(self.footer.replace("{{ route('privacy.rights') }}", "#")).link)

    def test_enlaces_ocultos_o_inertes_no_cuentan(self) -> None:
        for tag in sorted(INERT - {"script"}):
            with self.subTest(tag=tag):
                self.assertFalse(_parse(f"<{tag}>{LINK}</{tag}>").link)
        for attributes in (
            "hidden", "inert", "style='display:none'", "aria-hidden='true'",
            "aria-disabled='true'", "role='presentation'", "role='none'",
            "tabindex='-1'", "disabled", "aria-label='Otro nombre'",
            "aria-labelledby='externo'",
        ):
            with self.subTest(attr=attributes):
                source = LINK.replace("<a ", f"<a {attributes} ", 1)
                self.assertFalse(_parse(source).link)

    def test_atributos_duplicados_no_cuentan(self) -> None:
        for key, first, second in (
            ("href", "/otro", RIGHTS), ("aria-hidden", "true", "false"),
            ("style", "display:none", "display:block"), ("tabindex", "-1", "0"),
        ):
            with self.subTest(key=key):
                attrs = f'{key}="{first}" {key}="{second}"'
                self.assertFalse(_parse(f'<a {attrs}>{LABEL}</a>').link)
        self.assertFalse(_parse('<x-release-footer context="workspace" context="public" />').component)

    def test_footer_al_final_fuera_de_formulario(self) -> None:
        for name in REMAINING:
            with self.subTest(page=name):
                source = self.pages[name]
                closing = source.rfind("</main>")
                self.assertGreater(closing, 0)
                target = COMPONENT if name != "errors/500.blade.php" else LINK
                self.assertGreater(source.find(target), closing)
                self.assertNotIn("grant", target.lower())
                self.assertNotIn("token", target.lower())


if __name__ == "__main__":
    unittest.main()
