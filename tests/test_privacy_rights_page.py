"""Contrato offline #785: ambas superficies exponen derechos sin inventar aprobación legal.

No abre una sesión, accede a datos reales ni utiliza red.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILE = {
    "twig": "symfony/templates/privacidad/derechos.html.twig",
    "controller": "symfony/src/Http/Controller/PrivacyRightsController.php",
    "base": "symfony/templates/base.html.twig",
    "blade": "resources/views/privacy/rights.blade.php",
    "footer": "resources/views/components/release-footer.blade.php",
    "routes": "routes/web.php",
    "config": "config/app.php",
}
PATH = "/privacidad-y-mis-derechos"
PLACEHOLDER = "[COMPLETAR POR EL DUEÑO]"


class PrivacyRightsPageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = {
            name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in FILE.items()
        }

    def test_criterio_principal(self) -> None:
        """AC-01: páginas GET públicas, enlaces globales y texto provisional."""
        s = self.source
        self.assertIn("#[Route('" + PATH + "', name: 'grindflow_privacy_rights', methods: ['GET'])]", s["controller"])
        self.assertIn("extends AbstractController", s["controller"])
        self.assertIn("{% extends 'base.html.twig' %}", s["twig"])
        self.assertIn("{{ path('grindflow_privacy_rights') }}", s["base"])
        self.assertIn("Route::view('" + PATH + "', 'privacy.rights')->name('privacy.rights')", s["routes"])
        self.assertIn("{{ route('privacy.rights') }}", s["footer"])
        self.assertIn('<x-release-footer context="public" />', s["blade"])
        for template in (s["twig"], s["blade"]):
            for fragment in (
                "Privacidad y mis derechos", "acceso, corrección o borrado", "responsable",
                "En cuánto tiempo", "pendiente de aprobación del abogado", PLACEHOLDER,
            ):
                self.assertIn(fragment, template)
            self.assertIn('id="contenido"', template)
        self.assertEqual(s["base"].count("{{ path('grindflow_privacy_rights') }}"), 1)
        self.assertEqual(s["footer"].count("{{ route('privacy.rights') }}"), 1)

    def test_configuracion_falla_cerrado(self) -> None:
        """AC-01: correo solo de variable aprobada y sin plazo inventado."""
        s = self.source
        self.assertIn("env('GRINDFLOW_PRIVACY_CONTACT_EMAIL', '')", s["config"])
        self.assertIn("config('app.privacy_contact_email', '')", s["blade"])
        self.assertIn("GRINDFLOW_PRIVACY_CONTACT_EMAIL", s["controller"])
        self.assertIn("FILTER_VALIDATE_EMAIL", s["blade"])
        self.assertIn("FILTER_VALIDATE_EMAIL", s["controller"])
        self.assertIn("{% if contact_email %}", s["twig"])
        self.assertIn("@if ($contactEmail !== '')", s["blade"])
        self.assertIn("Cache-Control", s["controller"])
        for template in (s["twig"], s["blade"]):
            self.assertIn(PLACEHOLDER, template)
            self.assertIn("sin prometer un plazo no aprobado", template)
            self.assertNotRegex(template, r"(?i)\b(\d{1,3}\s*días hábiles|ley\s*1581.*cumple)\b")
            self.assertNotRegex(template, r"(?i)(<form|method=[\"']post|https?://)")

    def test_public_routes_and_output_safety(self) -> None:
        """Regression: do not hide rights behind authentication or raw HTML."""
        s = self.source
        # Both GET endpoints are registered exactly once, outside of tenant
        # authenticated groups (the Laravel route precedes the auth group).
        public_route = "Route::view('" + PATH + "', 'privacy.rights')->name('privacy.rights')"
        self.assertEqual(s["routes"].count(public_route), 1)
        self.assertLess(s["routes"].index(public_route), s["routes"].index("Route::middleware(['auth'"))
        self.assertEqual(s["controller"].count("#[Route('" + PATH + "'"), 1)
        self.assertNotIn("methods: ['POST']", s["controller"])
        self.assertIn("contact_email|url_encode", s["twig"])
        self.assertNotIn("contact_email|raw", s["twig"])
        self.assertIn("{{ rawurlencode($contactEmail) }}", s["blade"])
        self.assertNotIn("{!! $contactEmail !!}", s["blade"])
        for template in (s["twig"], s["blade"]):
            self.assertNotRegex(template, r"(?i)<form\b|<script\b|onerror\s*=")


    def test_runtime_contacto_php_falla_cerrado(self) -> None:
        """Ejecuta el controlador Symfony real con identidades y correos sintéticos.

        El texto de una llamada filter_var no equivale a validar el resultado:
        esta prueba debe fallar si se suprime la rama que rechaza correos inválidos.
        """
        php = shutil.which("php")
        if php is None:
            self.fail("AC-01 requiere PHP CLI; prohibido omitir pruebas de seguridad cuando falta")
        # El proceso PHP es desechable, sin Symfony/servidor ni solicitudes de red.
        # Stubs mínimos para las dependencias de renderizado; el __invoke real
        # del controlador sí se ejecuta en cada escenario.
        fixture = r"""<?php
namespace Symfony\Component\HttpFoundation {
    class Response {
        public const HTTP_OK = 200;
        public function __construct(public string $content = '', public int $status = 200, public array $headers = []) {}
    }
}
namespace Symfony\Bundle\FrameworkBundle\Controller {
    class AbstractController {
        public function render(string $view, array $data, ?\Symfony\Component\HttpFoundation\Response $response = null): \Symfony\Component\HttpFoundation\Response {
            $response ??= new \Symfony\Component\HttpFoundation\Response();
            $response->content = json_encode(['view' => $view, 'data' => $data], JSON_THROW_ON_ERROR);
            return $response;
        }
    }
}
namespace GrindFlow\Shared\Version {
    class ProductVersion { public function human(): string { return 'synthetic-v1'; } }
}
namespace {
    require $argv[1];
    $contact = new \GrindFlow\Http\Controller\PrivacyRightsController();
    $version = new \GrindFlow\Shared\Version\ProductVersion();
    function scenario($contact, $version, $server, $env, $process) {
        unset($_SERVER['GRINDFLOW_PRIVACY_CONTACT_EMAIL'], $_ENV['GRINDFLOW_PRIVACY_CONTACT_EMAIL']);
        putenv('GRINDFLOW_PRIVACY_CONTACT_EMAIL');
        if ($server !== null) $_SERVER['GRINDFLOW_PRIVACY_CONTACT_EMAIL'] = $server;
        if ($env !== null) $_ENV['GRINDFLOW_PRIVACY_CONTACT_EMAIL'] = $env;
        if ($process !== null) putenv('GRINDFLOW_PRIVACY_CONTACT_EMAIL=' . $process);
        $response = $contact($version);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);
        return [$payload['data']['contact_email'], $payload['view'], $response->status,
                $response->headers['Cache-Control'] ?? null, $response->headers['X-Content-Type-Options'] ?? null];
    }
    $output = [
        'missing' => scenario($contact, $version, null, null, null),
        'server' => scenario($contact, $version, 'owner@example.test', null, null),
        'env' => scenario($contact, $version, null, 'env@example.test', null),
        'getenv' => scenario($contact, $version, null, null, 'process@example.test'),
        'invalid' => scenario($contact, $version, '<script>@example.test', null, null),
        'crlf' => scenario($contact, $version, "owner@example.test\r\nBcc:someone@example.test", null, null),
        'header' => scenario($contact, $version, 'owner@example.test', null, null),
    ];
    echo json_encode($output, JSON_THROW_ON_ERROR);
}
"""
        result = subprocess.run(
            [php, "-d", "display_errors=stderr", "-r", fixture.removeprefix("<?php"),
             str(ROOT / FILE["controller"])],
            capture_output=True, text=True, timeout=15, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        import json
        cases = json.loads(result.stdout)
        expected = {
            "missing": "", "server": "owner@example.test",
            "env": "env@example.test", "getenv": "process@example.test",
            "invalid": "", "crlf": "", "header": "owner@example.test",
        }
        for case, email in expected.items():
            with self.subTest(case=case):
                actual, template, status, cache, nosniff = cases[case]
                self.assertEqual(actual, email)
                self.assertEqual(template, "privacidad/derechos.html.twig")
                self.assertEqual(status, 200)
                self.assertEqual(cache, "no-store")
                self.assertEqual(nosniff, "nosniff")

    def test_mailto_correo_valido_con_delimitadores_uri(self) -> None:
        """Las direcciones RFC válidas con ?/# deben ser un único receptor mailto.

        El escape HTML de Blade protege el atributo, pero no realiza escape
        de componente URI. Esta prueba protege contra query/fragment injection.
        """
        import re
        from urllib.parse import unquote, urlsplit

        blade = self.source["blade"]
        link = re.search(r'href="mailto:{{\s*([^}]+?)\s*}}"', blade)
        self.assertIsNotNone(link)
        self.assertEqual(link.group(1).strip(), "rawurlencode($contactEmail)")
        self.assertNotIn("{!!", blade)
        php = shutil.which("php")
        if php is None:
            self.fail("AC-01 requiere PHP CLI; prohibido omitir pruebas de seguridad cuando falta")
        for address in (
            "owner@example.test",
            "foo?bar@example.test",
            "foo#bar@example.test",
            "foo&bar@example.test",
            "foo%bar@example.test",
        ):
            with self.subTest(address=address):
                run = subprocess.run(
                    [php, "-r", """$email=$argv[1];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) exit(8);
echo rawurlencode($email);""", address],
                    capture_output=True, text=True, timeout=10, check=False,
                )
                self.assertEqual(run.returncode, 0, run.stderr)
                mailto = urlsplit("mailto:" + run.stdout)
                self.assertEqual(mailto.scheme, "mailto")
                self.assertEqual(mailto.query, "")
                self.assertEqual(mailto.fragment, "")
                self.assertEqual(unquote(mailto.path), address)

    def test_runtime_blade_php_contacto_falla_cerrado(self) -> None:
        """Ejecuta el @php de Blade con configuración sintética; sin Laravel/HTTP."""
        import json
        import re
        php = shutil.which("php")
        if php is None:
            self.fail("AC-01 requiere PHP CLI; prohibido omitir pruebas de seguridad cuando falta")
        # Extract the actual PHP expression from the candidate view. Merely
        # matching FILTER_VALIDATE_EMAIL text would miss a bypass of its guard.
        source = self.source["blade"]
        blocks = re.findall(r"@php\s*(.*?)\s*@endphp", source, re.DOTALL)
        self.assertEqual(len(blocks), 1, "La vista debe tener un bloque PHP auditable")
        program = r"""function config($key, $default = '') { return $GLOBALS['fixture_contact']; }
$cases = [
    null, '', '  owner@example.test  ', 'owner@example.test',
    'no-es-un-correo', "owner@example.test\r\nBcc:fake@example.test",
    '<script>@example.test', [], 12345,
    'foo?bar@example.test', 'foo#bar@example.test',
];
$result = [];
foreach ($cases as $value) {
    $GLOBALS['fixture_contact'] = $value;
    $contactEmail = '';
__BLADE_PHP__
    $result[] = [$contactEmail, $contactEmail !== '' ? rawurlencode($contactEmail) : ''];
}
echo json_encode($result, JSON_THROW_ON_ERROR);""".replace("__BLADE_PHP__", blocks[0])
        run = subprocess.run([php, "-r", program], capture_output=True, text=True,
                             timeout=15, check=False)
        self.assertEqual(run.returncode, 0, run.stderr)
        actual = json.loads(run.stdout)
        expected = ["", "", "owner@example.test", "owner@example.test", "", "", "", "", "",
                    "foo?bar@example.test", "foo#bar@example.test"]
        self.assertEqual([item[0] for item in actual], expected)
        for address, url_encoded in actual:
            if address:
                self.assertNotIn("?", url_encoded)
                self.assertNotIn("#", url_encoded)
                self.assertNotIn("\r", url_encoded)
                self.assertNotIn("\n", url_encoded)

    def test_todas_las_paginas_symfony_heredan_footer(self) -> None:
        """Toda pagina Twig completa hereda el footer; un parcial no es pagina.

        Una pagina independiente futura no puede quedar sin el enlace de
        derechos solo porque el controlador principal y base.twig lo tengan.
        """
        template_root = ROOT / "symfony" / "templates"
        expected = {
            "home/index.html.twig",
            "identity/admin.html.twig",
            "identity/forgot-password.html.twig",
            "identity/login.html.twig",
            "identity/organizations.html.twig",
            "identity/recover-password.html.twig",
            "preview/index.html.twig",
            "privacidad/derechos.html.twig",
        }
        pages = {
            page.relative_to(template_root).as_posix(): page
            for page in template_root.rglob("*.html.twig")
            if page.name != "base.html.twig" and not page.name.startswith("_")
        }
        self.assertTrue(expected <= pages.keys(),
                        f"Faltan vistas de pagina esperadas: {sorted(expected - pages.keys())}")
        for relative, page in sorted(pages.items()):
            with self.subTest(template=relative):
                source = page.read_text(encoding="utf-8")
                self.assertIn("{% extends 'base.html.twig' %}", source,
                              f"{relative} no hereda el enlace global de privacidad")

    def test_gobierno_no_publica_live_con_borrador_legal(self) -> None:
        """Un cambio a live debe fallar antes de publicar datos o plazos sin aprobar.

        La fase de construccion permite placeholders; live exige identidad del
        responsable, correo y textos juridicos revisados, sin interpretar CI
        verde como aprobacion de abogados ni activacion productiva.
        """
        import json
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        phase = data.get("phase")
        self.assertIn(phase, ("construccion", "live"), "Fase del producto no reconocida")
        if phase != "live":
            return
        responsable = data.get("controller")
        self.assertIsInstance(responsable, dict)
        for field in ("name", "identifier", "address", "rights_email"):
            with self.subTest(field=field):
                value = responsable.get(field)
                self.assertIsInstance(value, str, f"Falta controller.{field} aprobado")
                self.assertTrue(value.strip() and PLACEHOLDER not in value,
                                f"controller.{field} pendiente; no habilitar live")
        self.assertRegex(responsable["rights_email"], r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
        for name in ("twig", "blade"):
            with self.subTest(template=name):
                self.assertNotIn(PLACEHOLDER, self.source[name],
                                 "Texto juridico sin aprobar: prohibido live")
                self.assertNotIn("pendiente de aprobación del abogado", self.source[name],
                                 "La revisión legal sigue pendiente: prohibido live")

    def test_php_lint(self) -> None:
        """AC-01: no se introducen errores de sintaxis PHP."""
        php = shutil.which("php")
        if php is None:
            self.fail("AC-01 requiere PHP CLI; prohibido omitir pruebas de seguridad cuando falta")
        # Every PHP file must be syntactically complete. Test fixtures cannot
        # disable these checks with a marker or environment variable.
        for name in ("controller", "routes", "config"):
            source = (ROOT / FILE[name]).read_text(encoding="utf-8")
            self.assertTrue(source.lstrip().startswith("<?php"), name)
            result = subprocess.run(
                [php, "-l", str(ROOT / FILE[name])],
                capture_output=True, text=True, timeout=10, check=False,
            )
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)


if __name__ == "__main__":
    unittest.main()
