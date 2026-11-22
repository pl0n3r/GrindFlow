#!/usr/bin/env python3
"""GF-MIG-003: offline HTTP route registry and no-retirement parity gate."""
from __future__ import annotations
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT = ROOT / "docs/migracion/gf-mig-003-paridad.md"
LARAVEL = ROOT / "routes/web.php"
FRAMEWORK = ROOT / "bootstrap/app.php"
REVIEWED_PARITY_KEYS: frozenset[str] = frozenset()
EXPECTED = frozenset(["GET /","GET /privacidad-y-mis-derechos","GET /health","GET /up","POST /internal/production-smoke/bootstrap","GET /_deployment","GET /l/{token}","GET /guest-upload/{token}","POST /guest-upload","GET /login","POST /login","GET /dashboard","GET /organizations/{organizationId}/vault","POST /organizations/{organizationId}/vault","GET /organizations/{organizationId}/scheduler","POST /organizations/{organizationId}/scheduler","PATCH /organizations/{organizationId}/scheduler","PATCH /organizations/{organizationId}/scheduler/tracked-link","POST /organizations/{organizationId}/scheduler/cancel","GET /organizations/{organizationId}/distribution","POST /organizations/{organizationId}/distribution/destinations","PATCH /organizations/{organizationId}/distribution/destinations/{destinationId}","POST /organizations/{organizationId}/distribution/deliveries/{deliveryId}/retry","GET /organizations/{organizationId}/traffic","GET /organizations/{organizationId}/traffic/export","POST /organizations/{organizationId}/traffic","PATCH /organizations/{organizationId}/traffic/links/{linkId}","PATCH /organizations/{organizationId}/traffic/links/{linkId}/status","GET /organizations/{organizationId}/finance","GET /organizations/{organizationId}/finance/reconciliation.csv","POST /organizations/{organizationId}/finance","POST /organizations/{organizationId}/finance/{allocationId}/reverse","POST /organizations/{organizationId}/vault/direct-upload","POST /organizations/{organizationId}/vault/direct-upload/complete","GET /organizations/{organizationId}/connections/dropbox/authorize","GET /organizations/{organizationId}/connections/google-drive/authorize","GET /connections/dropbox/callback","GET /connections/google-drive/callback","GET /admin/system","POST /admin/system/migrations","GET /admin/diagnostics","GET /admin/diagnostics.json","POST /logout"])
PREFIX = "Route::prefix('/organizations/{organizationId}')"
CALLS = re.compile(r"Route::(get|post|patch|put|delete|view)\s*\(\s*['\"]([^'\"]+)['\"]", re.I)



FACADE_CALLS = re.compile(r"\bRoute::([A-Za-z_]\w*)\s*\(")
DECLARED = frozenset(("get", "post", "patch", "put", "delete", "view"))
STRUCTURAL = frozenset(("prefix", "middleware", "group"))


def remove_php_comments(source: str) -> str:
    """Replace comments with spaces, preserving positions, strings and newlines."""
    out: list[str] = []
    i = 0
    quote: str | None = None
    while i < len(source):
        ch = source[i]
        if quote is not None:
            out.append(ch)
            if ch == "\\" and i + 1 < len(source):
                out.append(source[i + 1])
                i += 2
                continue
            if ch == quote:
                quote = None
            i += 1
            continue
        if ch in ("'", '"'):
            quote = ch
            out.append(ch)
            i += 1
            continue
        if source.startswith("//", i) or ch == "#":
            end = source.find("\n", i)
            if end < 0:
                end = len(source)
            out.extend(" " * (end - i))
            i = end
            continue
        if source.startswith("/*", i):
            end = source.find("*/", i + 2)
            if end < 0:
                raise ValueError("Unterminated PHP comment")
            end += 2
            out.extend("\n" if value == "\n" else " " for value in source[i:end])
            i = end
            continue
        out.append(ch)
        i += 1
    if quote is not None:
        raise ValueError("Unterminated PHP string")
    return "".join(out)


def routes(source: str, framework: str | None = None) -> dict[str, str]:
    source = remove_php_comments(source)
    for call in FACADE_CALLS.finditer(source):
        if call[1].lower() not in DECLARED | STRUCTURAL:
            raise ValueError("Unsupported Laravel Route declaration: " + call[1])
    if source.count(PREFIX) != 1:
        raise ValueError("Missing or duplicated tenant prefix")
    lo = source.index(PREFIX)
    hi = source.find("\n        });", lo)
    if hi <= lo:
        raise ValueError("Tenant route group invalid")
    found = list(CALLS.finditer(source))
    result = {}
    for n, match in enumerate(found):
        method = "GET" if match[1].lower() == "view" else match[1].upper()
        path = ("/organizations/{organizationId}" if lo < match.start() < hi else "") + match[2]
        key = method + " " + path
        if key in result:
            raise ValueError("Duplicate Laravel endpoint")
        stop = found[n + 1].start() if n + 1 < len(found) else len(source)
        name = re.search(r"->name\('([^']+)'\)", source[match.start():stop])
        result[key] = name[1] if name else "-"
    boot = FRAMEWORK.read_text(encoding="utf-8") if framework is None else framework
    boot = remove_php_comments(boot)
    health = re.findall(r"\bhealth:\s*['\"](/[^'\"]+)['\"]", boot)
    if len(health) != 1:
        raise ValueError("Missing or duplicated Laravel framework health route")
    key = "GET " + health[0]
    if key in result:
        raise ValueError("Duplicate Laravel framework health route")
    result[key] = "-"
    return result


def entries(document: str) -> list[list[str]]:
    a = "## Matriz HTTP por ruta\n"
    b = "\n## Límites, comprobación y reversión"
    if document.count(a) != 1 or document.count(b) != 1:
        raise ValueError("Missing matrix boundaries")
    block = document.split(a, 1)[1].split(b, 1)[0]
    lines = [s for s in block.splitlines() if s.startswith("|")]
    heading = ["Ruta Laravel", "Nombre Laravel", "Ruta Symfony candidata", "Código Symfony candidato",
               "Prueba candidata", "Dimensiones", "Paridad", "Retirar"]
    parse = lambda s: [v.strip().strip(chr(96)) for v in s.strip().strip("|").split("|")]
    if len(lines) < 3 or parse(lines[0]) != heading:
        raise ValueError("Wrong matrix schema")
    records = [parse(s) for s in lines[2:]]
    if any(len(r) != 8 for r in records):
        raise ValueError("Malformed matrix row")
    if len(records) != len(set(r[0] for r in records)):
        raise ValueError("Duplicated matrix row")
    return records


def check(document: str | None = None, source: str | None = None, framework: str | None = None) -> list[list[str]]:
    md = DOCUMENT.read_text(encoding="utf-8") if document is None else document
    src = LARAVEL.read_text(encoding="utf-8") if source is None else source
    real = routes(src, framework)
    rows = entries(md)
    if set(real) != EXPECTED:
        raise ValueError("Frozen legacy HTTP route inventory changed")
    if {r[0] for r in rows} != set(real):
        raise ValueError("Missing, extra or obsolete parity row")
    for key, name, candidate, php, test, dimensions, parity, retire in rows:
        if real[key] != name:
            raise ValueError("Laravel route name mismatch: " + key)
        if dimensions != "PENDIENTE_8_8":
            raise ValueError("Eight-dimensional parity not proven")
        if parity != "UNKNOWN":
            if parity != "PARITY" or key not in REVIEWED_PARITY_KEYS:
                raise ValueError("Unreviewed claim of PARITY")
        if retire != "NO":
            raise ValueError("Retirement without PARITY is forbidden")
        if candidate == "-":
            if php != "-" or test != "-":
                raise ValueError("Partial undocumented candidate")
            continue
        if php == "-" or test == "-" or ".." in Path(php).parts or ".." in Path(test).parts:
            raise ValueError("Invalid Symfony evidence")
        if not php.startswith("symfony/src/Http/Controller/") or not php.endswith(".php"):
            raise ValueError("Invalid Symfony controller path")
        if not test.startswith("symfony/tests/php/") or not test.endswith("Test.php") or test == php:
            raise ValueError("Invalid Symfony candidate test path")
        php_path = ROOT / php
        test_path = ROOT / test
        if not php_path.is_file() or php_path.is_symlink() or not test_path.is_file() or test_path.is_symlink():
            raise ValueError("Missing Symfony source or candidate test")
        try:
            method, path = candidate.split(" ", 1)
        except ValueError:
            raise ValueError("Invalid candidate method or path") from None
        attrs = re.findall(r"#\[Route\((.*?)\)\]", php_path.read_text(encoding="utf-8"), re.S)
        if not any(
            re.search(r"^\s*['\"]" + re.escape(path) + r"['\"]", attr)
            and method in re.findall(r"['\"]([A-Z]+)['\"]", (re.search(r"methods:\s*\[([^\]]+)\]", attr) or [None, ""])[1])
            for attr in attrs
        ):
            raise ValueError("Symfony route attribute or method absent: " + key)
    return rows


class GfMig003ParityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.md = DOCUMENT.read_text(encoding="utf-8")
        cls.src = LARAVEL.read_text(encoding="utf-8")
        cls.rows = check()

    def altered(self, idx: int, col: int, value: str) -> str:
        old = self.rows[idx]
        needle = "| " + chr(96) + old[0] + chr(96) + " |"
        line = next(s for s in self.md.splitlines() if s.startswith(needle))
        modified = old[:]
        modified[col] = value
        replacement = "| " + " | ".join(chr(96) + cell + chr(96) for cell in modified) + " |"
        return self.md.replace(line, replacement, 1)

    def test_all_routes_accounted_for_and_not_retirable(self):
        self.assertEqual(43, len(self.rows))
        self.assertEqual(EXPECTED, set(routes(self.src)))
        self.assertEqual({"UNKNOWN"}, {r[6] for r in self.rows})
        self.assertEqual({"NO"}, {r[7] for r in self.rows})

    def test_source_added_or_removed_fails(self):
        with self.assertRaises(ValueError):
            check(source=self.src + "\nRoute::get('/unlisted', []);\n")
        with self.assertRaises(ValueError):
            check(source=self.src.replace("Route::get('/login'", "Route::get('/renamed-login'", 1))

    def test_framework_health_route_is_required(self):
        boot = FRAMEWORK.read_text(encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "Frozen legacy"):
            check(framework=boot.replace("health: '/up'", "health: '/different'", 1))

    def test_unsupported_route_methods_are_rejected(self):
        for declaration in (
            "Route::match(['GET'], '/other', fn () => 'ok');",
            "Route::any('/other', fn () => 'ok');",
            "Route::redirect('/old', '/new');",
            "Route::resource('others', OthersController::class);",
        ):
            with self.subTest(declaration=declaration), self.assertRaisesRegex(ValueError, "Unsupported"):
                check(source=self.src + "\n" + declaration)

    def test_commented_routes_do_not_pass_and_strings_survive(self):
        view = "Route::view('/', 'welcome')->name('home');"
        with self.assertRaises(ValueError):
            check(source=self.src.replace(view, "// " + view, 1))
        with self.assertRaises(ValueError):
            check(source=self.src.replace(view, "/* " + view + " */", 1))
        added = self.src + "\n// Route::match(['GET'], '/hidden', []);\n"
        added += "/* Route::get('/hidden2', []); */\n"
        added += "$url = 'https://example.test/a/*preserved*/';\n"
        self.assertEqual(EXPECTED, set(routes(added)))

    def test_retirement_fake_parity_or_malformed_proof_fails(self):
        for idx in (0, 8, len(self.rows) - 1):
            for column, value in ((7, "SI"), (6, "PARITY"), (5, "8_8")):
                with self.subTest(route=idx, column=column), self.assertRaises(ValueError):
                    check(document=self.altered(idx, column, value))

    def test_missing_and_duplicate_route_rows_fail(self):
        line = next(s for s in self.md.splitlines() if s.startswith("| " + chr(96) + "GET /" + chr(96)))
        for wrong in (self.md.replace(line + "\n", "", 1),
                      self.md.replace(line, line + "\n" + line, 1)):
            with self.assertRaises(ValueError):
                check(document=wrong)

    def test_fake_candidate_source_method_and_name_fail(self):
        idx = next(i for i, row in enumerate(self.rows) if row[2] != "-")
        for col, value in ((2, "PATCH /invented"), (3, "symfony/src/Http/Controller/Missing.php"),
                           (4, "symfony/tests/php/MissingTest.php"),
                           (4, self.rows[idx][3]), (1, "fake.name")):
            with self.subTest(col=col), self.assertRaises(ValueError):
                check(document=self.altered(idx, col, value))

    def test_previous_retirement_gates_preserved(self):
        for name in ("tests/test_migration_parity_exit_gate.py",
                     "tests/test_gf350_retirement_eligibility.py",
                     "tests/test_legacy_retirement_guard.py"):
            self.assertTrue((ROOT / name).is_file())


if __name__ == "__main__":
    unittest.main()
