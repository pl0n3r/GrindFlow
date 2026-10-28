#!/usr/bin/env python3
"""GrindFlow #710: evidence adapter for five distinct REQ coverage gaps.

The five requirements are NOT credited as deployed or verified in Hostinger.
Existing Laravel Feature tests exercise tenant-scoped synthetic behavior on
SQLite. This adapter checks the *actual test methods*, executes a focused
selection when PHPUnit is installed, and reports UNKNOWN without that runner.
It never writes the coverage matrix or opens a network connection.
"""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
PHPUNIT = ROOT / "vendor/bin/phpunit"

# Each requirement has positive AND negative behavioral PHP regression cases.
# Keep file names exact: absence is UNKNOWN, not evidence of implementation.
CASES = {
    "GF-FR-006A": (
        "tests/Feature/ScheduleTrackedLinkTest.php",
        (
            "test_schedule_can_attach_active_link_from_same_organization",
            "test_foreign_tracked_link_is_not_schedulable",
            "test_disabling_link_before_delivery_blocks_provider_io",
        ),
    ),
    "GF-FR-006B": (
        "tests/Feature/TrafficAttributionTest.php",
        (
            "test_daily_csv_export_applies_filters_and_never_leaks_another_tenant",
            "test_daily_csv_exports_all_filtered_links_beyond_dashboard_preview",
            "test_daily_csv_export_rejects_unprivileged_invalid_or_unbounded_requests",
        ),
    ),
    "GF-FR-006C": (
        "tests/Feature/TrafficAttributionTest.php",
        (
            "test_traffic_manager_can_pause_resume_link_without_losing_history",
            "test_traffic_link_status_rejects_model_foreign_link_and_invalid_status",
            "test_traffic_link_status_is_safe_before_migration",
        ),
    ),
    "GF-FR-006D": (
        "tests/Feature/TrafficAttributionTest.php",
        (
            "test_editing_link_details_preserves_short_url_clicks_status_and_redirects",
            "test_tracked_link_index_paginates_deep_history_with_filters_and_global_totals",
            "test_link_detail_edits_reject_cross_tenant_model_and_invalid_input",
        ),
    ),
    "GF-FR-007": (
        "tests/Feature/FinanceTest.php",
        (
            "test_editor_and_model_cannot_manage_finance",
            "test_reversal_is_append_only_and_cannot_repeat",
            "test_finance_totals_never_mix_currencies",
        ),
    ),
}

UNKNOWN = "UNKNOWN"


def method_body(source: str, name: str) -> str | None:
    """Only count assertions inside the named executable PHPUnit test."""
    pattern = r"(?m)^    public function " + re.escape(name) + r"\s*\(\)\s*:\s*void\s*\{"
    matches = list(re.finditer(pattern, source))
    if len(matches) != 1:
        return None
    tail = source[matches[0].end():]
    next_method = re.search(r"(?m)^    (?:public|protected|private) function \w+\s*\(", tail)
    return tail[:next_method.start()] if next_method else tail


def has_executable_assertion(body: str) -> bool:
    """Ignore PHP line/block comments, which cannot prove an assertion ran."""
    code = re.sub(
        r"/\*[\s\S]*?\*/|(?m:^[ \t]*(?://|#)[^\n]*|[ \t]+(?://|#)[^\n]*)",
        "",
        body,
    )
    return bool(re.search(r"(?:->assert[A-Z]|\$this->assert[A-Z])", code))


def disposable_php_env(source: dict[str, str] | None = None) -> dict[str, str]:
    """Allowlist environment; never forward inherited credentials or DB URLs."""
    original = os.environ if source is None else source
    allowed = ("PATH", "HOME", "CI", "LANG", "TZ", "TMPDIR")
    env = {key: original[key] for key in allowed if key in original}
    env.update({
        "APP_ENV": "testing",
        "APP_DEBUG": "false",
        "APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
        "DB_CONNECTION": "sqlite",
        "DB_DATABASE": ":memory:",
        "DB_URL": "",
        "DATABASE_URL": "",
        "CACHE_STORE": "array",
        "SESSION_DRIVER": "array",
        "QUEUE_CONNECTION": "sync",
    })
    return env


def verify_disposable_database(php: str, env: dict[str, str]) -> None:
    """Fail before PHPUnit if Laravel resolves to a non-ephemeral database."""
    if (ROOT / "bootstrap/cache/config.php").exists():
        raise AssertionError("UNKNOWN: cached Laravel config could target a real database")
    probe = (
        "require 'vendor/autoload.php';"
        "$app=require 'bootstrap/app.php';"
        "$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
        "$db=config('database.default');"
        "$path=config('database.connections.sqlite.database');"
        "if ($db !== 'sqlite' || $path !== ':memory:') {"
        "fwrite(STDERR,'UNKNOWN: database is not disposable'); exit(7); }"
        "echo 'ISOLATED_SQLITE_MEMORY_OK';"
    )
    result = subprocess.run(
        [php, "-r", probe], cwd=ROOT, env=env,
        capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode != 0 or "ISOLATED_SQLITE_MEMORY_OK" not in result.stdout:
        raise AssertionError("UNKNOWN: effective database isolation could not be verified")


def evidence_status(source: str | None, names: tuple[str, ...], executed: bool) -> str:
    """No code search or passing CI alone certifies the behavior."""
    if not source or not names:
        return UNKNOWN
    for name in names:
        body = method_body(source, name)
        if body is None or not has_executable_assertion(body):
            return UNKNOWN
    return "TEST_EXECUTED" if executed else UNKNOWN


def matrix_state(content: str, requirement: str) -> str:
    matches = [
        line for line in content.splitlines()
        if line.startswith("| REQ:" + requirement + " |")
    ]
    if len(matches) != 1:
        return UNKNOWN
    fields = [part.strip() for part in matches[0].strip("|").split("|")]
    return fields[2] if len(fields) == 8 else UNKNOWN


class BrechasGrindflow03Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Map five requirements to real behavioral tests, not named stubs."""
        self.assertEqual(5, len(CASES))
        sources: dict[str, str] = {}
        for requirement, (path, names) in CASES.items():
            with self.subTest(requirement=requirement):
                file = ROOT / path
                self.assertTrue(file.is_file(), f"{requirement}: {UNKNOWN}, suite absent")
                source = sources.setdefault(path, file.read_text(encoding="utf-8"))
                self.assertIn("use RefreshDatabase;", source)
                self.assertIn("extends TestCase", source)
                self.assertEqual(3, len(names))
                for name in names:
                    body = method_body(source, name)
                    self.assertIsNotNone(body, f"{requirement}: {UNKNOWN}, {name}")
                    self.assertTrue(
                        has_executable_assertion(body or ""),
                        f"{requirement}: {UNKNOWN}, no executable assertion in {name}",
                    )

        # If Laravel's disposable test runner is available, execute the real
        # PHP tests. Without Composer, code-level evidence remains UNKNOWN.
        php = shutil.which("php")
        if php and PHPUNIT.is_file():
            env = disposable_php_env()
            verify_disposable_database(php, env)
            by_file: dict[str, set[str]] = {}
            for path, names in CASES.values():
                by_file.setdefault(path, set()).update(names)
            for path, names in by_file.items():
                selector = r"^(?:.*::)?(?:" + "|".join(sorted(map(re.escape, names))) + r")$"
                run = subprocess.run(
                    [php, str(PHPUNIT), "--configuration", "phpunit.xml",
                     "--no-coverage", "--filter", selector, path],
                    cwd=ROOT, env=env, capture_output=True, text=True,
                    timeout=180, check=False,
                )
                self.assertEqual(
                    0, run.returncode, f"{path}: {UNKNOWN}\n"
                    + (run.stdout + run.stderr)[-2600:],
                )
                self.assertRegex(run.stdout + run.stderr, r"(?i)(?:tests?:|OK \()")
        else:
            for path, names in CASES.values():
                self.assertEqual(
                    UNKNOWN, evidence_status(sources[path], names, executed=False),
                )

    def test_falla_cerrado(self) -> None:
        """Deleted, renamed, empty or assertion-free tests never pass as proof."""
        for requirement, (_, names) in CASES.items():
            with self.subTest(requirement=requirement):
                self.assertEqual(UNKNOWN, evidence_status(None, names, False))
                self.assertEqual(UNKNOWN, evidence_status("", names, True))
                self.assertEqual(UNKNOWN, evidence_status(
                    "public function " + names[0] + "() : void {}", names, True
                ))
                fake = "    public function " + names[0] + "() : void {\n"
                fake += "        // Test stub with no assertion\n    }\n"
                self.assertEqual(UNKNOWN, evidence_status(fake, (names[0],), True))
                comment_only = (
                    "    public function " + names[0] + "() : void {\n"
                    "        // $this->assertTrue(true);\n"
                    "        /* $this->assertSame(1, 1); */\n"
                    "    }\n"
                )
                self.assertEqual(
                    UNKNOWN, evidence_status(comment_only, (names[0],), True),
                )
                executable = (
                    "    public function " + names[0] + "() : void {\n"
                    "        // $this->assertFalse(true);\n"
                    "        $this->assertTrue(true);\n"
                    "    }\n"
                )
                self.assertEqual(
                    "TEST_EXECUTED", evidence_status(executable, (names[0],), True),
                )
                inline_hash_only = (
                    "    public function " + names[0] + "() : void {\n"
                    "        $value = 1; # $this->assertTrue(true);\n"
                    "    }\n"
                )
                self.assertEqual(
                    UNKNOWN, evidence_status(inline_hash_only, (names[0],), True),
                )

    def test_sin_datos_reales(self) -> None:
        """Require disposable DB/fixtures, not production secrets or network."""
        xml = (ROOT / "phpunit.xml").read_text(encoding="utf-8")
        self.assertIn('name="APP_ENV" value="testing"', xml)
        self.assertIn('name="DB_CONNECTION" value="sqlite"', xml)
        self.assertIn('name="DB_DATABASE" value=":memory:"', xml)
        inherited = {
            "PATH": os.environ.get("PATH", ""),
            "DB_CONNECTION": "mysql",
            "DB_DATABASE": "production",
            "DB_URL": "mysql://private.example/production",
            "APP_KEY": "private-value",
        }
        isolated = disposable_php_env(inherited)
        self.assertEqual("testing", isolated["APP_ENV"])
        self.assertEqual("sqlite", isolated["DB_CONNECTION"])
        self.assertEqual(":memory:", isolated["DB_DATABASE"])
        self.assertEqual("", isolated["DB_URL"])
        self.assertEqual("", isolated["DATABASE_URL"])
        self.assertNotEqual("private-value", isolated["APP_KEY"])
        self.assertFalse(any("private.example" in v for v in isolated.values()))
        php = shutil.which("php")
        if php and PHPUNIT.is_file():
            verify_disposable_database(php, isolated)
        for path in {entry[0] for entry in CASES.values()}:
            with self.subTest(path=path):
                source = (ROOT / path).read_text(encoding="utf-8")
                self.assertIn("use RefreshDatabase;", source)
                self.assertIn("$this->identity(", source)
                self.assertNotIn("env('DB_HOST'", source)
                self.assertNotIn("file_get_contents('https://", source)
                self.assertNotIn("Http::post('https://", source)

    def test_estados_unknown_explicitos(self) -> None:
        """A test-only PR cannot silently promote matrix/CI/Hostinger evidence."""
        content = MATRIX.read_text(encoding="utf-8")
        for requirement, (path, names) in CASES.items():
            with self.subTest(requirement=requirement):
                self.assertEqual("NO_VERIFICABLE", matrix_state(content, requirement))
                self.assertEqual(UNKNOWN, matrix_state("", requirement))
                self.assertEqual(UNKNOWN, matrix_state(
                    "| REQ:" + requirement + " | duplicate |", requirement
                ))
                source = (ROOT / path).read_text(encoding="utf-8")
                self.assertEqual(
                    UNKNOWN, evidence_status(source, names, executed=False),
                    f"{requirement}: static source is not terminal test evidence",
                )


if __name__ == "__main__":
    unittest.main()
