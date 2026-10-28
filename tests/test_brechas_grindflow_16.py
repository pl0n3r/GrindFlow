#!/usr/bin/env python3
"""Issue #723: cinco contratos STACK offline, nunca aprobación del entorno live.

Aserciones contra fuentes versionadas y casos sintéticos: no ejecuta red,
despliegues, credenciales, navegadores ni escribe en el árbol de producción.
"""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
STACK = "docs/STACK-TRANSITION-SYMFONY.md"
CI = ".github/workflows/grindflow-ci.yml"
LINTER = "scripts/workflow-syntax-check.rb"
SIDEBAR = "resources/views/components/workspace-sidebar.blade.php"
SIDEBAR_TEST = "tests/Feature/WorkspaceSidebarTest.php"
NAV_TEST = "tests/Feature/NavigationLabelsTest.php"
CSS = "public/css/grindflow.css"
HEADER = "symfony/templates/identity/_header.html.twig"
LOGIN = "symfony/templates/identity/login.html.twig"
ORGS = "symfony/templates/identity/organizations.html.twig"
IDENTITY_TEST = "symfony/tests/php/IdentityLoginTest.php"
SELECTOR_TEST = "symfony/tests/php/OrganizationSelectorAccessibilityTest.php"
BROWSER = "symfony/tests/Browser/forms-a11y.spec.ts"
VIEWS = (
    "dashboard.blade.php", "vault/index.blade.php",
    "scheduling/index.blade.php", "distribution/index.blade.php",
    "traffic/index.blade.php", "finance/index.blade.php",
    "admin/system.blade.php", "admin/diagnostics.blade.php",
)
VIEW_PATHS = tuple("resources/views/" + name for name in VIEWS)
FILES = (
    MATRIX, STACK, CI, LINTER, SIDEBAR, SIDEBAR_TEST, NAV_TEST, CSS,
    HEADER, LOGIN, ORGS, IDENTITY_TEST, SELECTOR_TEST, BROWSER,
) + VIEW_PATHS
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
IDS = (
    "STACK:GF-OPS-012", "STACK:GF-OPS-013", "STACK:GF-OPS-014",
    "STACK:GF-UX-001", "STACK:GF-UX-002",
)
CLAUSES = {
    "STACK:GF-OPS-012": (229, "### GF-OPS-012", (
        "max[total]=30", "max[self]=0", "max[direct]=0")),
    "STACK:GF-OPS-013": (237, "### GF-OPS-013", (
        "actions/checkout", "actions/cache", "actions/setup-node",
        "actions/upload-artifact", "Node 24")),
    "STACK:GF-OPS-014": (245, "### GF-OPS-014", (
        "persist-credentials: false", "scripts/workflow-syntax-check.rb")),
    "STACK:GF-UX-001": (484, "### GF-UX-001", (
        "Dashboard", "Biblioteca", "Programación", "Distribución",
        "Tráfico", "Finanzas", "Sistema", "Diagnósticos",
        "680 px", "960 px")),
    "STACK:GF-UX-002": (117, "### GF-UX-002", (
        "misma cabecera", "CSRF", "360/820 px")),
}
OFFICIAL = {
    "checkout": 5, "cache": 5, "setup-node": 5, "upload-artifact": 6,
}


def parse_matrix(text: str) -> dict[str, tuple[str, ...]]:
    """Rechazar filas duplicadas o truncadas, sin acreditar por defecto."""
    rows = {}
    for line in text.splitlines():
        if not any(line.startswith("| " + key + " |") for key in IDS):
            continue
        fields = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if len(fields) != 8 or fields[0] in rows:
            raise ValueError("UNKNOWN: fila STACK duplicada o incompleta")
        rows[fields[0]] = fields
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: falta una fila STACK")
    return rows


def section(text: str, heading: str) -> str | None:
    """Extraer exactamente una sección STACK con encabezado completo."""
    # Cada encabezado del documento tiene título después del ID.
    positions = list(re.finditer(
        r"(?m)^" + re.escape(heading) + r"(?:[ \t]+[^\n]*)?$", text
    ))
    if len(positions) != 1:
        return None
    remainder = text[positions[0].end():]
    end = re.search(r"(?m)^### ", remainder)
    return remainder[:end.start()] if end else remainder


def deprecations(result: object) -> str:
    """Cinco avisos indirectos no permiten ni uno propio o directo."""
    if (type(result) is not dict or set(result) != {"total", "self", "direct"}
            or any(type(v) is not int or v < 0 for v in result.values())
            or result["total"] > 30 or result["self"] != 0
            or result["direct"] != 0):
        return UNKNOWN
    return LOCAL


def actions_compatible(workflows: dict[str, str]) -> str:
    """Verifica majors declaradas; un pin sin marca de release es UNKNOWN.

    Solo prueba el contrato estático de workflows, NO el runtime Node real.
    """
    if not workflows:
        return UNKNOWN
    seen = set()
    pattern = re.compile(
        r"(?m)^[ \t]*(?:-[ \t]+)?uses:[ \t]*actions/"
        r"(checkout|cache|setup-node|upload-artifact)(?:/[\w.-]+)*@([^\s#]+)([^\n]*)"
    )
    for source in workflows.values():
        if type(source) is not str:
            return UNKNOWN
        for name, ref, suffix in pattern.findall(source):
            seen.add(name)
            major = re.fullmatch(r"v(\d+)(?:\.\d+)*", ref)
            if major is not None:
                version = int(major.group(1))
            elif re.fullmatch(r"[0-9a-f]{40}", ref):
                label = re.search(r"#\s*v(\d+)(?:\.\d+)*\b", suffix)
                if label is None:
                    # Pin exacto checkout v5 usado también por Factory Kit.
                    # Ningún otro SHA sin identidad de release se acepta.
                    if not (name == "checkout" and
                            ref == "3d3c42e5aac5ba805825da76410c181273ba90b1"):
                        return UNKNOWN
                    version = 5
                else:
                    version = int(label.group(1))
            else:
                return UNKNOWN
            if version < OFFICIAL[name]:
                return UNKNOWN
    return LOCAL if seen == set(OFFICIAL) else UNKNOWN


def checkouts_safe(workflows: dict[str, str]) -> str:
    """Inspeccionar cada checkout activo, aun si el step comienza con name."""
    if not workflows:
        return UNKNOWN
    count = 0
    checkout = re.compile(
        r"(?m)^[ \t]*(?:-[ \t]+)?uses:[ \t]*actions/checkout@[^\s#]+"
    )
    step_start = re.compile(r"(?m)^(?=[ \t]*-[ \t]+(?:name|uses|id|if):)")
    for source in workflows.values():
        if type(source) is not str:
            return UNKNOWN
        for step in step_start.split(source):
            if not checkout.search(step):
                continue
            count += 1
            opts = re.findall(
                r"(?m)^[ \t]+persist-credentials:[ \t]*(\S+)[ \t]*(?:#.*)?$",
                step,
            )
            if opts != ["false"]:
                return UNKNOWN
    return LOCAL if count else UNKNOWN


def shell_consistent(sources: dict[str, str]) -> str:
    """Exigir shell Laravel único y reglas responsive verificables."""
    sidebar = sources.get(SIDEBAR, "")
    tests = sources.get(SIDEBAR_TEST, "")
    labels = sources.get(NAV_TEST, "")
    css = sources.get(CSS, "")
    if not all(isinstance(v, str) and v for v in (sidebar, tests, labels, css)):
        return UNKNOWN
    keys = ("summary", "vault", "scheduler", "distribution",
            "traffic", "finance", "system", "diagnostics")
    if (not all("'key' => '" + key + "'" in sidebar for key in keys)
            or 'aria-current="page"' not in sidebar
            or 'aria-disabled="true"' not in sidebar
            or "data-workspace-sidebar" not in sidebar
            or "test_shared_sidebar_exposes_one_consistent_admin_navigation" not in tests
            or "test_workspace_pages_do_not_reintroduce_private_sidebar_copies" not in tests
            or "test_responsive_sidebar_keeps_visible_icons_at_medium_width_and_text_on_mobile" not in tests
            or "test_workspace_navigation_uses_readable_spanish_labels" not in labels
            or "@media (max-width: 960px)" not in css
            or "@media (max-width: 680px)" not in css
            or "overflow-x: auto;" not in css):
        return UNKNOWN
    for path in VIEW_PATHS:
        view = sources.get(path, "")
        if (view.count("<x-workspace-sidebar") != 1
                or '<aside class="gf-sidebar">' in view):
            return UNKNOWN
    return LOCAL


def private_access_consistent(sources: dict[str, str]) -> str:
    """Contrastar cabecera Symfony, CSRF y selección explícita de tenant."""
    header = sources.get(HEADER, "")
    login = sources.get(LOGIN, "")
    org = sources.get(ORGS, "")
    identity = sources.get(IDENTITY_TEST, "")
    selector = sources.get(SELECTOR_TEST, "")
    browser = sources.get(BROWSER, "")
    include = '{% include "identity/_header.html.twig" with { current_label: '
    if (not all(isinstance(v, str) and v for v in
                (header, login, org, identity, selector, browser))
            or include + '"Ingresar" } only %}' not in login
            or include + '"Organizaciones" } only %}' not in org
            or header.count('<a class="brand"') != 1
            or 'aria-label="Navegación de acceso"' not in header
            or 'aria-current="page"' not in header
            or "grindflow_home" not in header
            or "grindflow_preview" not in header
            or "csrf_token('authenticate')" not in login
            or "csrf_token('select_organization')" not in org
            or "organization.id == selected_id" not in org
            or "testLoginSelectTenantReauthorizeAndLogout" not in identity
            or "testTwoMembershipsExposeDistinguishableActionsAndCurrentSelection" not in selector
            or "360, 390, 820" not in browser
            or "scrollWidth" not in browser):
        return UNKNOWN
    return LOCAL


def synthetic_journey(route: object, csrf: object, membership: object) -> str:
    """No simula credenciales reales ni permite un acceso privado anónimo."""
    if (route != ("/", "/login", "/organizations", "/admin")
            or csrf is not True or membership is not True):
        return UNKNOWN
    return LOCAL


def inspect(identifier: str, rows: dict[str, tuple[str, ...]],
            sources: dict[str, str], workflows: dict[str, str]) -> dict[str, str]:
    """Emitir evidencia local por contrato sin inferir CI, browser ni live."""
    status = {"code": UNKNOWN, "ci": UNKNOWN, "browser": UNKNOWN,
              "production": UNKNOWN}
    if identifier not in CLAUSES:
        return status
    row = rows.get(identifier)
    if (not isinstance(row, tuple) or len(row) != 8
            or row[2] != "NO_VERIFICABLE"
            or row[5:8] != ("NO_ACREDITADO",) * 3):
        return status
    anchor, heading, tokens = CLAUSES[identifier]
    if (f"STACK-TRANSITION-SYMFONY.md:L{anchor}" not in row[3]
            or f"#L{anchor})" not in row[3]):
        return status
    clause = section(sources.get(STACK, ""), heading)
    if not clause or not all(token.casefold() in clause.casefold()
                             for token in tokens):
        return status
    if identifier == "STACK:GF-OPS-012":
        ci = sources.get(CI, "")
        validate = re.search(
            r"(?ms)^  validate:[ \t]*\n(?P<body>.*?)"
            r"(?=^  [A-Za-z0-9_.-]+:[ \t]*(?:#.*)?$|\Z)", ci,
        )
        if (re.search(r"(?m)^  symfony-preview:[ \t]*$", ci)
                and "SYMFONY_DEPRECATIONS_HELPER: 'max[total]=30&max[self]=0&max[direct]=0'" in ci
                and validate is not None
                and re.search(
                    r"(?m)^    needs:[ \t]*\[[^\]\n]*"
                    r"\bsymfony-preview\b[^\]\n]*\][ \t]*(?:#.*)?$",
                    validate.group("body"),
                )):
            status["code"] = LOCAL
    elif identifier == "STACK:GF-OPS-013":
        lint = sources.get(LINTER, "")
        if ("obsolete_node20_actions" in lint
                and "Node 24-capable majors" in lint
                and actions_compatible(workflows) == LOCAL):
            status["code"] = LOCAL
    elif identifier == "STACK:GF-OPS-014":
        lint = sources.get(LINTER, "")
        if ("persist-credentials" in lint
                and "YAML.safe_load_file" in lint
                and checkouts_safe(workflows) == LOCAL):
            status["code"] = LOCAL
    elif identifier == "STACK:GF-UX-001":
        status["code"] = shell_consistent(sources)
    elif identifier == "STACK:GF-UX-002":
        status["code"] = private_access_consistent(sources)
    return status


class BrechasGrindflow16Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        """Leer únicamente fixtures versionados; sin servicios externos."""
        cls.sources = {path: (ROOT / path).read_text(encoding="utf-8")
                       for path in FILES}
        cls.workflows = {
            p.relative_to(ROOT).as_posix(): p.read_text(encoding="utf-8")
            for p in sorted((ROOT / ".github/workflows").glob("*.y*ml"))
        }
        cls.rows = parse_matrix(cls.sources[MATRIX])

    def test_contrato_principal(self) -> None:
        """AC-01: cada fila tiene un predicado específico ejecutable."""
        self.assertEqual(set(IDS), set(CLAUSES))
        self.assertEqual(set(IDS), set(self.rows))
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                result = inspect(identifier, self.rows, self.sources, self.workflows)
                self.assertEqual(LOCAL, result["code"], identifier)
        self.assertEqual(LOCAL, deprecations({"total": 30, "self": 0, "direct": 0}))
        self.assertEqual(LOCAL, actions_compatible(self.workflows))
        self.assertEqual(LOCAL, checkouts_safe(self.workflows))
        self.assertEqual(LOCAL, synthetic_journey(
            ("/", "/login", "/organizations", "/admin"), True, True))

    def test_falla_cerrado(self) -> None:
        """AC-02: contrato mutado, evidencia ausente o ambigua => UNKNOWN."""
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                no_row = dict(self.rows)
                del no_row[identifier]
                self.assertEqual(UNKNOWN, inspect(
                    identifier, no_row, self.sources, self.workflows)["code"])
                promoted = dict(self.rows)
                record = list(promoted[identifier])
                record[2] = "CUBIERTO"
                promoted[identifier] = tuple(record)
                self.assertEqual(UNKNOWN, inspect(
                    identifier, promoted, self.sources, self.workflows)["code"])
                self.assertEqual(UNKNOWN, inspect(
                    identifier, self.rows, {**self.sources, STACK: ""},
                    self.workflows)["code"])
        first = next(line for line in self.sources[MATRIX].splitlines()
                     if line.startswith("| STACK:GF-OPS-012 |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_matrix(self.sources[MATRIX] + "\n" + first)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_matrix("| STACK:GF-OPS-012 | truncada |")
        for payload in ({"total": 31, "self": 0, "direct": 0},
                        {"total": 1, "self": 1, "direct": 0},
                        {"total": 1, "self": 0, "direct": 1},
                        {"total": 0, "self": None, "direct": 0},
                        {"total": 0, "self": 0}):
            self.assertEqual(UNKNOWN, deprecations(payload))
        broken_ci = self.sources[CI].replace("max[self]=0", "max[self]=1")
        self.assertEqual(UNKNOWN, inspect(
            "STACK:GF-OPS-012", self.rows,
            {**self.sources, CI: broken_ci}, self.workflows)["code"])
        broken_needs = self.sources[CI].replace(
            "legacy, symfony-preview, privacy]", "legacy, privacy]", 1,
        )
        self.assertNotEqual(self.sources[CI], broken_needs)
        self.assertEqual(UNKNOWN, inspect(
            "STACK:GF-OPS-012", self.rows,
            {**self.sources, CI: broken_needs}, self.workflows)["code"])
        first_wf, first_body = next(
            (name, body) for name, body in self.workflows.items()
            if "actions/checkout@v5" in body)
        old = self.workflows.copy()
        old[first_wf] = first_body.replace("actions/checkout@v5",
                                          "actions/checkout@v4", 1)
        self.assertEqual(UNKNOWN, actions_compatible(old))
        self.assertEqual(UNKNOWN, actions_compatible({
            "comments.yml": (
                "# uses: actions/checkout@v5\n"
                "# uses: actions/cache@v5\n"
                "# uses: actions/setup-node@v5\n"
                "# uses: actions/upload-artifact@v6\n"
            ),
        }))
        self.assertEqual(UNKNOWN, actions_compatible({
            **self.workflows,
            "obsolete-subaction.yml": (
                "jobs:\n  check:\n    steps:\n"
                "      - uses: actions/cache/restore@v4\n"
            ),
        }))
        weakened = self.workflows.copy()
        weakened[first_wf] = first_body.replace(
            "persist-credentials: false", "persist-credentials: true", 1)
        self.assertEqual(UNKNOWN, checkouts_safe(weakened))
        self.assertEqual(UNKNOWN, checkouts_safe({
            **self.workflows,
            "unsafe-named-step.yml": (
                "jobs:\n  test:\n    steps:\n"
                "      - name: Unsafe checkout\n"
                "        uses: actions/checkout@v5\n"
                "        with:\n          persist-credentials: true\n"
            ),
        }))
        view = VIEW_PATHS[0]
        duplicated = self.sources[view] + "\n<x-workspace-sidebar />"
        self.assertEqual(UNKNOWN, shell_consistent(
            {**self.sources, view: duplicated}))
        self.assertEqual(UNKNOWN, private_access_consistent(
            {**self.sources, ORGS: self.sources[ORGS].replace(
                "identity/_header.html.twig", "private-header.html.twig")}))
        for route, csrf, member in (
            (("/", "/admin"), True, True),
            (("/", "/login", "/organizations", "/admin"), False, True),
            (("/", "/login", "/organizations", "/admin"), True, False),
        ):
            self.assertEqual(UNKNOWN, synthetic_journey(route, csrf, member))

    def test_sin_datos_reales(self) -> None:
        """AC-03: sólo lectura local y fixtures sintéticos sin secretos ni red."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib|subprocess)\b")
        self.assertNotRegex(source, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(source, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(set(FILES), set(self.sources))
        self.assertTrue(self.workflows)
        for path in FILES:
            self.assertFalse(Path(path).is_absolute())
            self.assertTrue((ROOT / path).is_file())

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI exact-SHA, E2E navegador y producción NO se inventan."""
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                row = self.rows[identifier]
                self.assertEqual("NO_VERIFICABLE", row[2])
                self.assertEqual(("NO_ACREDITADO",) * 3, row[5:8])
                record = inspect(identifier, self.rows, self.sources, self.workflows)
                self.assertEqual(UNKNOWN, record["ci"])
                self.assertEqual(UNKNOWN, record["browser"])
                self.assertEqual(UNKNOWN, record["production"])
        self.assertEqual(UNKNOWN, inspect(
            "STACK:GF-OPS-013", self.rows, self.sources, {})["code"])
        self.assertEqual(UNKNOWN, synthetic_journey(
            ("/", "/login", "/organizations", "/admin"), None, True))


if __name__ == "__main__":
    unittest.main()
