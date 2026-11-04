#!/usr/bin/env python3
"""Audita, sin credenciales ni escrituras, las rutas GET de Symfony S4.

Ejemplo:
  python3 scripts/s4-route-presence.py --base-url https://www.grindflow.com.co/s4

No sigue redirecciones. Los 404 de aplicación no se confunden con el 404
genérico de Symfony. El script no imprime cuerpos de respuesta.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Callable

ROOT = Path(__file__).resolve().parents[1]
CONTROLLERS = ROOT / "symfony" / "src" / "Http" / "Controller"
ATTRIBUTE = re.compile(r"#\[\s*Route\s*\(")
PATH = re.compile(r"\s*(?:path\s*:\s*)?(['\"])(/[^'\"]*)\1", re.DOTALL)
METHODS = re.compile(r"\bmethods\s*:\s*(\[[^\]]*\]|['\"][^'\"]+['\"])", re.DOTALL)
MAX_RESPONSE_BYTES = 8192


def route_attributes(source: str) -> list[str]:
    """Lee #[Route(...)] sin cortar en los corchetes de methods o requirements."""
    attributes: list[str] = []
    for marker in ATTRIBUTE.finditer(source):
        start = marker.end()
        depth = 1
        quote = None
        escaped = False
        for position in range(start, len(source)):
            char = source[position]
            if quote is not None:
                if escaped:
                    escaped = False
                elif char == "\\":
                    escaped = True
                elif char == quote:
                    quote = None
            elif char in ("'", '"'):
                quote = char
            elif char == "(":
                depth += 1
            elif char == ")":
                depth -= 1
                if depth == 0:
                    if source[position + 1:].lstrip().startswith("]"):
                        attributes.append(source[start:position])
                    break
    return attributes


def get_routes(controller_dir: Path = CONTROLLERS) -> list[str]:
    """Devuelve rutas GET literales sin parámetros, duplicados ni rutas mutantes."""
    if not controller_dir.is_dir():
        raise ValueError("Directorio de controladores inexistente.")
    routes: set[str] = set()
    for file in sorted(controller_dir.rglob("*.php")):
        for attribute in route_attributes(file.read_text(encoding="utf-8")):
            match = PATH.match(attribute)
            if match is None:
                continue
            path = match.group(2)
            if not path.startswith("/") or path.startswith("//"):
                continue
            if any(mark in path for mark in ("{", "}", "?", "#", "%", "\\", "\r", "\n")):
                continue
            if ".." in path.split("/") or re.fullmatch(r"/[a-zA-Z0-9_./~-]*", path) is None:
                continue
            declared = METHODS.search(attribute)
            if declared is not None:
                methods = {m.upper() for m in re.findall(r"['\"]([a-zA-Z]+)['\"]", declared.group(1))}
                if "GET" not in methods:
                    continue
            routes.add(path)
    return sorted(routes)


def classify_response(status: int, body: bytes | str = b"") -> str:
    """Solo un 404 inequívocamente genérico de Symfony indica ruta ausente."""
    if status != 404:
        if status in (200, 301, 302, 303, 307, 308, 401, 403):
            return "present"
        if status == 503:
            return "service_unavailable"
        return "http_status"
    text = body.decode("utf-8", errors="replace") if isinstance(body, bytes) else body
    lower = text.lower()
    if "no route found for" in lower:
        return "missing"
    if "oops! an error occurred" in lower and ("404" in lower and "not found" in lower):
        return "missing"
    return "unknown_404"


def validate_base_url(base_url: str) -> str:
    parsed = urllib.parse.urlsplit(base_url)
    if (parsed.scheme != "https" or not parsed.hostname or parsed.username is not None
            or parsed.password is not None or parsed.query or parsed.fragment
            or parsed.path.startswith("//") or ".." in parsed.path.split("/")):
        raise ValueError("URL base inválida: exige HTTPS sin credenciales, query ni fragmento.")
    try:
        _ = parsed.port
    except ValueError as error:
        raise ValueError("Puerto de URL inválido.") from error
    return base_url.rstrip("/")


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


def fetch_status(url: str, timeout: float) -> tuple[int, bytes]:
    """GET anónimo; nunca reenvía cookies, Authorization ni sigue redirects."""
    request = urllib.request.Request(
        url, method="GET", headers={"Accept": "text/html, application/json"},
    )
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirects())
    try:
        with opener.open(request, timeout=timeout) as response:
            status = response.status
            return status, response.read(MAX_RESPONSE_BYTES) if status == 404 else b""
    except urllib.error.HTTPError as error:
        with error:
            return error.code, error.read(MAX_RESPONSE_BYTES) if error.code == 404 else b""


def inspect_routes(
    routes: list[str],
    base_url: str,
    *,
    delay: float = 0.35,
    timeout: float = 5.0,
    fetch: Callable[[str, float], tuple[int, bytes]] = fetch_status,
    sleep: Callable[[float], None] = time.sleep,
) -> list[dict[str, object]]:
    base = validate_base_url(base_url)
    if not 0.1 <= delay <= 10 or not 0.1 <= timeout <= 30:
        raise ValueError("Delay (0.1-10 s) o timeout (0.1-30 s) inválido.")
    results: list[dict[str, object]] = []
    for index, path in enumerate(routes):
        if index:
            sleep(delay)
        try:
            status, body = fetch(base + path, timeout)
            classification = classify_response(status, body)
            results.append({"path": path, "status": status, "result": classification})
        except (OSError, ValueError, TimeoutError):
            results.append({"path": path, "status": None, "result": "network_error"})
    return results


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", help="URL HTTPS del prefijo S4, p. ej. https://www.grindflow.com.co/s4")
    parser.add_argument("--controllers", type=Path, default=CONTROLLERS)
    parser.add_argument("--list-only", action="store_true", help="Enumera rutas sin acceder a la red")
    parser.add_argument("--delay", type=float, default=0.35, help="Pausa entre GET anónimos")
    parser.add_argument("--timeout", type=float, default=5.0, help="Tiempo máximo por GET")
    args = parser.parse_args(argv)
    try:
        routes = get_routes(args.controllers)
        if not routes:
            raise ValueError("No se encontraron rutas GET literales.")
        if args.list_only:
            print(json.dumps({"routes": routes, "count": len(routes)}, ensure_ascii=False, indent=2))
            return 0
        if args.base_url is None:
            parser.error("Se exige --base-url salvo con --list-only.")
        results = inspect_routes(routes, args.base_url, delay=args.delay, timeout=args.timeout)
        missing = [row["path"] for row in results if row["result"] == "missing"]
        inconclusive = [row["path"] for row in results if row["result"] in ("network_error", "unknown_404")]
        print(json.dumps({
            "base_url": validate_base_url(args.base_url),
            "checked": len(results),
            "missing": missing,
            "inconclusive": inconclusive,
            "results": results,
        }, ensure_ascii=False, indent=2))
        return 1 if missing else (2 if inconclusive else 0)
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
