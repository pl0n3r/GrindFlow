#!/usr/bin/env python3
"""Mock HTTP contract: migration runner never mutates without exact approval."""
from __future__ import annotations

import http.server
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
from urllib.parse import parse_qs


class ProductionFixture(http.server.BaseHTTPRequestHandler):
    pending = 7
    submitted = []
    duplicate_batch = False
    fail_migrate = False

    def log_message(self, *args: object) -> None:
        pass

    def reply(self, status: int, content: str = "", cookie: bool = False) -> None:
        payload = content.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        if cookie:
            self.send_header("Set-Cookie", "synthetic=1; Path=/; HttpOnly")
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self) -> None:
        if self.path == "/login":
            self.reply(
                200,
                '<form><input name="_token" value="' + "a" * 40 + '"></form>',
                cookie=True,
            )
            return
        if self.path == "/admin/system":
            batch = "b" * 64
            extra = (
                '<input name="migration_batch" value="' + batch + '">'
                if self.duplicate_batch else ""
            )
            self.reply(
                200,
                '<span data-pending-migrations="' + str(type(self).pending) + '"></span>'
                '<form action="/admin/system/migrations">'
                '<input name="_token" value="' + "c" * 40 + '">'
                '<input name="migration_batch" value="' + batch + '">'
                + extra + '</form>',
            )
            return
        self.reply(404)

    def do_POST(self) -> None:
        length = int(self.headers["Content-Length"])
        data = parse_qs(self.rfile.read(length).decode("utf-8"))
        if self.path == "/login":
            self.reply(302, cookie=True)
            return
        if self.path == "/admin/system/migrations":
            type(self).submitted.append(data)
            fields_ok = (
                data.get("_token") == ["c" * 40]
                and data.get("backup_confirmed") == ["1"]
                and data.get("confirmation") == ["MIGRAR"]
                and data.get("migration_batch") == ["b" * 64]
            )
            if fields_ok and not type(self).fail_migrate:
                type(self).pending = 0
                self.reply(302)
            else:
                self.reply(422)
            return
        self.reply(404)


def run_case(
    server: http.server.HTTPServer,
    *,
    pending: int,
    duplicate_batch: bool = False,
    fail_migrate: bool = False,
    expected_ok: bool,
    expected_posts: int,
    expected_after: int | None,
) -> None:
    ProductionFixture.pending = pending
    ProductionFixture.submitted = []
    ProductionFixture.duplicate_batch = duplicate_batch
    ProductionFixture.fail_migrate = fail_migrate

    with tempfile.TemporaryDirectory() as temp:
        output = Path(temp) / "migration.json"
        env = os.environ.copy()
        env.update({
            "BASE_URL": f"http://127.0.0.1:{server.server_address[1]}",
            "E2E_USER_EMAIL": "synthetic-admin@example.test",
            "E2E_USER_PASSWORD": "synthetic-only-not-a-secret",
            "EXPECTED_PENDING": "7",
            "OUTPUT_PATH": str(output),
        })
        result = subprocess.run(
            ["bash", "scripts/run-production-migrations.sh"],
            env=env,
            capture_output=True,
            text=True,
            timeout=20,
            check=False,
        )
        payload = json.loads(output.read_text(encoding="utf-8"))
        assert (result.returncode == 0) == expected_ok, (result.returncode, payload)
        assert payload["ok"] is expected_ok, payload
        assert len(ProductionFixture.submitted) == expected_posts
        assert payload["pending_before"] == pending, payload
        assert payload["pending_after"] == expected_after, payload
        assert ProductionFixture.pending == (0 if expected_ok else pending)
        print(f"PASS migration contract: pending={pending}, posts={expected_posts}, ok={expected_ok}")


def main() -> None:
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), ProductionFixture)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        run_case(server, pending=7, expected_ok=True, expected_posts=1, expected_after=0)
        run_case(server, pending=6, expected_ok=False, expected_posts=0, expected_after=None)
        run_case(server, pending=7, duplicate_batch=True, expected_ok=False, expected_posts=0, expected_after=None)
        run_case(server, pending=7, fail_migrate=True, expected_ok=False, expected_posts=1, expected_after=7)
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)


if __name__ == "__main__":
    main()
