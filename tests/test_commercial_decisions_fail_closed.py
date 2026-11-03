#!/usr/bin/env python3
"""#643 / GF352-GF353: negativas comerciales en construcción, sin tocar producción.

El test importa el gate OWNER real y examina las rutas versionadas de Laravel
y Symfony. Los comentarios y políticas de prueba son totalmente sintéticos:
un resultado PASS no equivale a aprobar trial, tarifas ni go-live.
"""
from __future__ import annotations

import importlib.util
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GATE_FILE = ROOT / "scripts" / "decision-owner-gate.py"
OWNER = "pl0n3r"
HEAD = "a" * 40
WRONG_SHA = "b" * 40

spec = importlib.util.spec_from_file_location("gf643_owner_gate", GATE_FILE)
if spec is None or spec.loader is None:
    raise RuntimeError("Gate OWNER real no disponible")
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

# GF-FR-009, GrindFlow #352 (trial propuesto) y #353 (pricing y límites).
# No son endpoints autorizados: estas rutas sintéticas servirían de alarma.
COMMERCIAL_SEGMENT = re.compile(
    r"(?:^|/)(?:trial(?:s)?|pricing|checkout|billing|payments?|"
    r"subscriptions?|subscribe|purchase|buy|paywall)(?:/|$|\?|[{}])",
    re.IGNORECASE,
)
LARAVEL_ROUTE = re.compile(
    r"\bRoute::(?:get|post|put|patch|delete|any|options|view|redirect|"
    r"resource|apiResource)\s*\(\s*['\"](?P<path>[^'\"]+)['\"]"
)
LARAVEL_MATCH = re.compile(
    r"\bRoute::match\s*\(\s*\[[^\]]{1,150}\]\s*,\s*['\"]"
    r"(?P<path>[^'\"]+)['\"]",
    re.DOTALL,
)
LARAVEL_REDIRECT_TARGET = re.compile(
    r"\bRoute::redirect\s*\(\s*['\"][^'\"]+['\"]\s*,\s*['\"]"
    r"(?P<path>[^'\"]+)['\"]"
)
LARAVEL_PREFIX = re.compile(
    r"\bRoute::prefix\s*\(\s*['\"](?P<path>[^'\"]+)['\"]"
)
LARAVEL_GROUP = re.compile(
    r"\bRoute::group\s*\(\s*\[(?P<options>[^\]]{1,900})\]\s*,",
    re.DOTALL,
)
GROUP_PREFIX = re.compile(r"['\"]prefix['\"]\s*=>\s*['\"]([^'\"]+)['\"]")
SYMFONY_ROUTE = re.compile(
    r"#\[\s*Route\s*\(\s*(?:path\s*:\s*)?['\"](?P<path>[^'\"]+)['\"]",
    re.DOTALL,
)


def approval(*, login: str = OWNER, association: str = "OWNER",
             sha: str = HEAD, marker: bool = True) -> str:
    """Evento NDJSON descartable con la misma estructura del gate real."""
    payload = json.dumps({"sha": sha}, separators=(",", ":"))
    body = "<!-- grindflow-decision-approval " + payload + " -->" if marker else "OK"
    return json.dumps({
        "author_association": association,
        "user": {"login": login},
        "body": body,
    })


def policy(issue: int, value: str) -> dict:
    return gate.validate_policy({
        "version": 1,
        "review_round_limit": 3,
        "decisions": [{
            "id": "D-" + str(issue),
            "status": "active",
            "text": value,
        }],
    })


def remove_php_comments(source: str) -> str:
    """No confundir ejemplos en comentarios con rutas ejecutables."""
    without_blocks = re.sub(r"/\*.*?\*/", "", source, flags=re.DOTALL)
    without_slashes = re.sub(r"(?m)^[ \t]*//[^\n]*", "", without_blocks)
    return re.sub(r"(?m)^[ \t]*#(?!\[)[^\n]*", "", without_slashes)


def commercial_route_paths(source: str) -> list[str]:
    """Inspección estática limitada: no reemplaza el router HTTP ejecutado."""
    cleaned = remove_php_comments(source)
    candidates = [
        match.group("path")
        for expression in (
            LARAVEL_ROUTE, LARAVEL_MATCH, LARAVEL_REDIRECT_TARGET,
            LARAVEL_PREFIX, SYMFONY_ROUTE
        )
        for match in expression.finditer(cleaned)
    ]
    for group in LARAVEL_GROUP.finditer(cleaned):
        prefix = GROUP_PREFIX.search(group.group("options"))
        if prefix is not None:
            candidates.append(prefix.group(1))
    return [path for path in candidates if COMMERCIAL_SEGMENT.search(path)]


def route_sources() -> list[Path]:
    """Cubre las rutas Laravel públicas y los atributos PHP de Symfony."""
    files = [ROOT / "routes" / "web.php"]
    api = ROOT / "routes" / "api.php"
    if api.exists():
        files.append(api)
    controllers = ROOT / "symfony" / "src" / "Http" / "Controller"
    if not controllers.is_dir():
        raise AssertionError("Controladores Symfony ausentes: no fingir cobertura")
    files.extend(sorted(controllers.rglob("*.php")))
    return files


class CommercialDecisionsFailClosedTests(unittest.TestCase):
    def require_owner_approval(self, issue: int, evidence: list[str]) -> None:
        base = policy(issue, "Condición comercial diferida (sin aprobación)")
        candidate = policy(issue, "Activar nueva oferta comercial")
        with self.assertRaises(gate.DecisionGateError):
            gate.evaluate(
                base=base, current=candidate, comments=evidence,
                owner=OWNER, head_sha=HEAD,
            )

    def test_no_trial_or_price_without_owner_gate(self) -> None:
        """AC-01: GF-FR-009 / #352 / #353; sin OWNER ni endpoints comerciales."""
        for issue in (352, 353):
            with self.subTest(issue=issue):
                self.require_owner_approval(issue, [])
                self.require_owner_approval(issue, [approval(login="otro")])
                self.require_owner_approval(issue, [approval(sha=WRONG_SHA)])
        sources = route_sources()
        self.assertGreaterEqual(len(sources), 2)
        exposed = {
            str(path.relative_to(ROOT)): commercial_route_paths(
                path.read_text(encoding="utf-8")
            )
            for path in sources
        }
        self.assertEqual(
            {}, {path: matches for path, matches in exposed.items() if matches},
            "Ruta trial/precio/cobro sin contrato comercial OWNER autorizado",
        )

    def test_owner_identity_and_association_are_both_required(self) -> None:
        """#352: no se puede aprobar una oferta como contributor o no-owner."""
        for evidence in (
            approval(login="not_owner"),
            approval(association="COLLABORATOR"),
            approval(association="MEMBER"),
            approval(marker=False),
        ):
            with self.subTest(evidence=evidence):
                self.require_owner_approval(352, [evidence])

    def test_pr_head_and_latest_approval_cannot_be_reused(self) -> None:
        """#353: SHA de aprobación ajeno o suplantado falla cerrado."""
        for evidence in (
            [approval(sha=WRONG_SHA)],
            [approval(), approval(sha=WRONG_SHA)],
            [approval(), approval(login="otro"), approval(sha=WRONG_SHA)],
        ):
            with self.subTest(evidence=evidence):
                self.require_owner_approval(353, evidence)

    def test_exact_owner_marker_only_authorizes_synthetic_policy(self) -> None:
        """Control positivo del gate real: la suite no pasa por rechazar todo."""
        base = policy(353, "Sin precio aprobado")
        candidate = policy(353, "Propuesta sintética para prueba")
        gate.evaluate(
            base=base, current=candidate,
            comments=[approval()], owner=OWNER, head_sha=HEAD,
        )
        self.assertNotEqual(base, candidate)

    def test_laravel_commercial_routes_and_group_prefixes_are_detected(self) -> None:
        """#352: variantes get/post/match/group/prefix no eluden la alarma."""
        examples = (
            "Route::get('/trial', Dummy::class);",
            "Route::post('/buy', Dummy::class);",
            "Route::post('/pricing/activate', Dummy::class);",
            "Route::match(['GET', 'POST'], '/checkout', Dummy::class);",
            "Route::redirect('/buy', '/billing');",
            "Route::resource('/subscriptions', Dummy::class);",
            "Route::apiResource('/payments', Dummy::class);",
            "Route::prefix('/trial')->group(function () {});",
            "Route::group(['prefix' => 'checkout'], function () {"
            " Route::get('/', Dummy::class); });",
            "Route::group(['middleware' => 'auth', 'prefix' => 'pricing'],"
            " function () {});",
        )
        for source in examples:
            with self.subTest(source=source):
                self.assertTrue(commercial_route_paths(source))

    def test_symfony_routes_detected_without_scanning_arbitrary_strings(self) -> None:
        """#353: solo rutas efectivas, no textos de negocio ni comentarios."""
        for source in (
            "#[Route('/api/trial/start', methods: ['POST'])]",
            "#[Route(path: '/billing/pay', name: 'x', methods: ['POST'])]",
        ):
            with self.subTest(source=source):
                self.assertTrue(commercial_route_paths(source))
        safe = (
            "// Route::post('/checkout', Dummy::class);\n"
            "/* Route::get('/pricing', Dummy::class); */\n"
            "Route::get('/public', Dummy::class);\n"
            "Route::group(['prefix' => 'public'], function () {});\n"
            "#[Route('/api/admin/plans', name: 'plan_limits')]\n"
            "$info = 'Trial y pricing son propuestas, no endpoints';\n"
        )
        self.assertEqual([], commercial_route_paths(safe))

    def test_commercial_proposal_remains_explicitly_deferred(self) -> None:
        """La especificación habla de propuesta, no de pricing aprobado."""
        spec_text = (ROOT / "docs" / "GRINDFLOW-SPEC.md").read_text(
            encoding="utf-8"
        )
        self.assertIn("**Decisión pendiente:**", spec_text)
        self.assertIn("30 días", spec_text)
        self.assertIn("dos redes", spec_text)
        # El contrato documental comercial no se convierte en autorización.
        readiness = ROOT / "docs" / "launch" / "commercial-readiness.md"
        self.assertTrue(readiness.is_file())
        content = readiness.read_text(encoding="utf-8")
        self.assertIn("NO DEFINIDOS", content)
        self.assertIn("NO AUTORIZADO", content)


if __name__ == "__main__":
    unittest.main()
