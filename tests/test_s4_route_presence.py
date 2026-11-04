"""Regresión aislada y sin red para el monitor de rutas Symfony S4."""
from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "s4-route-presence.py"
SPEC = importlib.util.spec_from_file_location("s4_route_presence", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
S4 = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(S4)


class S4RoutePresenceTests(unittest.TestCase):
    def test_extracts_get_routes_without_dynamic_or_write_routes(self) -> None:
        php = """<?php
        final class ExampleController {
            #[Route('/', name: 'home', methods: ['GET'])]
            public function home() {}
            #[Route('/first', methods: ['GET'])]
            public function first() {}
            #[Route('/first', methods: ['GET'])]
            public function duplicate() {}
            #[Route('/both', methods: ['POST', 'GET'], requirements: ['kind' => '(a|b)'])]
            public function both() {}
            #[Route('/unrestricted')]
            public function unrestricted() {}
            #[Route('/dynamic/{id}', methods: ['GET'])]
            public function dynamic() {}
            #[Route('/only-post', methods: ['POST'])]
            public function post() {}
            #[Route('/only-put', methods: ['PUT'])]
            public function put() {}
            #[Route('//outside', methods: ['GET'])]
            public function outside() {}
            #[Route('/unsafe?token=x', methods: ['GET'])]
            public function query() {}
            #[Route('/a/../private', methods: ['GET'])]
            public function traversal() {}
            #[Route(name: 'after_name', path: '/named', methods: ['GET'])]
            public function named() {}
            // #[Route('/line-comment', methods: ['GET'])]
            # #[Route('/hash-comment', methods: ['GET'])]
            /**
             * Ejemplo: #[Route('/docblock-route', methods: ['GET'])]
             */
            /* #[Route('/block-comment', methods: ['GET'])] */
            const SAMPLE = "#[Route('/literal-string', methods: ['GET'])]";
        }
        """
        with tempfile.TemporaryDirectory() as directory:
            controllers = Path(directory)
            (controllers / "ExampleController.php").write_text(php, encoding="utf-8")
            (controllers / "Other.php").write_text(
                "<?php #[Route('/extra', methods: ['GET'])]", encoding="utf-8",
            )
            self.assertEqual(
                S4.get_routes(controllers),
                ["/", "/both", "/extra", "/first", "/named", "/unrestricted"],
            )
        with self.assertRaises(ValueError):
            S4.get_routes(Path(directory) / "absent")

    def test_comment_example_is_not_probed(self) -> None:
        php = """<?php
        /**
         * Example: #[Route('/phantom', methods: ['GET'])]
         */
        final class ExampleController {
            #[Route(name: 'real', path: '/real', methods: ['GET'])]
            public function real() {}
        }
        """
        with tempfile.TemporaryDirectory() as directory:
            (Path(directory) / "ExampleController.php").write_text(php, encoding="utf-8")
            self.assertEqual(["/real"], S4.get_routes(Path(directory)))
            seen = []
            def fake_inspect(routes, base_url, **kwargs):
                seen.extend(routes)
                return [{"path": path, "status": 200, "result": "present"} for path in routes]
            with mock.patch.object(S4, "inspect_routes", side_effect=fake_inspect), (
                mock.patch.object(S4.urllib.request, "build_opener", side_effect=AssertionError("network"))
            ), contextlib.redirect_stdout(io.StringIO()):
                code = S4.main(["--controllers", directory, "--base-url", "https://example.org/s4"])
            self.assertEqual(0, code)
            self.assertEqual(["/real"], seen)

    def test_real_symfony_routes_are_inventoryable_without_network(self) -> None:
        routes = S4.get_routes()
        for expected in (
            "/health",
            "/api/admin/library/collections",
            "/api/admin/plans",
            "/api/admin/rules/automation",
            "/privacidad-y-mis-derechos",
        ):
            self.assertIn(expected, routes)
        self.assertEqual(sorted(set(routes)), routes)
        self.assertTrue(all("{" not in route and "}" not in route for route in routes))

    def test_generic_symfony_404_is_different_from_http_statuses(self) -> None:
        response = b'<h1>Oops! An Error Occurred</h1><h2>The server returned a "404 Not Found".</h2>'
        self.assertEqual("missing", S4.classify_response(404, response))
        self.assertEqual("missing", S4.classify_response(404, b'No route found for "GET /new"'))
        self.assertEqual("unknown_404", S4.classify_response(404, b'{"error":"resource not found"}'))
        self.assertEqual("unknown_404", S4.classify_response(404, b"<title>Not Found</title>"))
        for status in (200, 301, 302, 303, 307, 308, 401, 403):
            self.assertEqual("present", S4.classify_response(status, response))
        self.assertEqual("service_unavailable", S4.classify_response(503, response))
        self.assertEqual("http_status", S4.classify_response(500, response))

    def test_mocked_probe_reports_missing_and_inconclusive_without_network(self) -> None:
        responses = {
            "/ok": (200, b""),
            "/login": (302, b""),
            "/secure": (401, b""),
            "/degraded": (503, b""),
            "/gone": (404, b'No route found for "GET /gone"'),
            "/custom": (404, b"Application record missing"),
        }
        seen: list[tuple[str, float]] = []
        pauses: list[float] = []

        def fake_fetch(url: str, timeout: float) -> tuple[int, bytes]:
            self.assertTrue(url.startswith("https://www.grindflow.com.co/s4/"))
            seen.append((url, timeout))
            if url.endswith("/timeout"):
                raise TimeoutError("simulated")
            for suffix, value in responses.items():
                if url.endswith(suffix):
                    return value
            raise AssertionError("Unexpected URL")

        paths = ["/ok", "/login", "/secure", "/degraded", "/gone", "/custom", "/timeout"]
        rows = S4.inspect_routes(
            paths, "https://www.grindflow.com.co/s4",
            delay=0.1, timeout=2, fetch=fake_fetch, sleep=pauses.append,
        )
        self.assertEqual(
            [row["result"] for row in rows],
            ["present", "present", "present", "service_unavailable",
             "missing", "unknown_404", "network_error"],
        )
        self.assertEqual([200, 302, 401, 503, 404, 404, None], [r["status"] for r in rows])
        self.assertEqual(len(pauses), len(paths) - 1)
        self.assertTrue(all(p == 0.1 for p in pauses))
        self.assertEqual([2] * len(paths), [t for _, t in seen])

    def test_request_never_sends_credentials_or_follows_redirects(self) -> None:
        class FakeResponse:
            status = 302
            def __enter__(self):
                return self
            def __exit__(self, *arguments):
                return False
            def read(self, amount):
                raise AssertionError("Redirect must not read a body")

        fake_opener = mock.Mock()
        fake_opener.open.return_value = FakeResponse()
        with mock.patch.object(S4.urllib.request, "build_opener", return_value=fake_opener) as builder:
            self.assertEqual((302, b""), S4.fetch_status("https://example.org/s4/login", 3))
        handlers = builder.call_args.args
        self.assertTrue(any(isinstance(h, S4.NoRedirects) for h in handlers))
        self.assertIsNone(S4.NoRedirects().redirect_request(None, None, 302, "", {}, "/other"))
        request = fake_opener.open.call_args.args[0]
        self.assertEqual("GET", request.get_method())
        self.assertIsNone(request.get_header("Authorization"))
        self.assertIsNone(request.get_header("Cookie"))
        self.assertIsNone(request.data)
        self.assertEqual(3, fake_opener.open.call_args.kwargs["timeout"])

    def test_url_validation_fails_closed_and_pause_is_bounded(self) -> None:
        self.assertEqual(
            "https://www.grindflow.com.co/s4",
            S4.validate_base_url("https://www.grindflow.com.co/s4/"),
        )
        for bad in (
            "http://example.org/s4",
            "https://user:password@example.org/s4",
            "https://example.org/s4?token=x",
            "https://example.org/s4#fragment",
            "https://example.org/../admin",
            "https://example.org:wrong/s4",
            "https://example.org//outside",
        ):
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                S4.validate_base_url(bad)
        for bad_delay, bad_timeout in ((0, 3), (0.01, 3), (11, 3), (0.35, 0), (0.35, 31)):
            with self.assertRaises(ValueError):
                S4.inspect_routes([], "https://example.org/s4", delay=bad_delay, timeout=bad_timeout)

    def test_main_returns_inconclusive_for_500_and_503(self) -> None:
        for status, result in ((500, "http_status"), (503, "service_unavailable")):
            with self.subTest(status=status):
                responses = [{"path": "/unavailable", "status": status, "result": result}]
                output = io.StringIO()
                with mock.patch.object(S4, "get_routes", return_value=["/unavailable"]), (
                    mock.patch.object(S4, "inspect_routes", return_value=responses)
                ), contextlib.redirect_stdout(output):
                    code = S4.main(["--base-url", "https://example.org/s4"])
                self.assertEqual(2, code)
                self.assertEqual(["/unavailable"], json.loads(output.getvalue())["inconclusive"])

    def test_cli_list_only_never_opens_network(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            controller = Path(directory) / "ExampleController.php"
            controller.write_text("<?php #[Route('/ok', methods: ['GET'])]", encoding="utf-8")
            output = io.StringIO()
            with mock.patch.object(S4.urllib.request, "build_opener", side_effect=AssertionError("network")) as network, (
                contextlib.redirect_stdout(output)
            ):
                exit_code = S4.main(["--list-only", "--controllers", directory])
            network.assert_not_called()
            self.assertEqual(0, exit_code)
            self.assertEqual({"routes": ["/ok"], "count": 1}, json.loads(output.getvalue()))


if __name__ == "__main__":
    unittest.main()
