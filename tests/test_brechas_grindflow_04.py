#!/usr/bin/env python3
"""GrindFlow #711: evidence and fail-closed tests for five coverage gaps.

The check is LOCAL/OFFLINE only: it does not certify a deployed SHA, a
production migration, or compliance with superseded Laravel architecture.
In particular, GF-MIG-001 and GF-MIG-002 are historical requirements.
"""
from __future__ import annotations

import os
import re
import subprocess
import unittest
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"
MIGRATION = ROOT / "docs/MIGRATION-LARAVEL.md"
ARCHITECTURE = ROOT / "docs/STACK-TRANSITION-SYMFONY.md"
CI_SCOPE = ROOT / "scripts/ci-scope.sh"
CI_WORKFLOW = ROOT / ".github/workflows/grindflow-ci.yml"
PHPUNIT_XML = ROOT / "phpunit.xml"
UNKNOWN = "UNKNOWN"
DECLARED = "ASSERTING_TEST_DECLARED"


@dataclass(frozen=True)
class Evidence:
    row: str
    source: str
    files_and_tests: tuple[tuple[str, tuple[str, ...]], ...]
    historical: bool = False


CASES = (
    Evidence("REQ:GF-FR-007B", "GF-FR-007B", (
        ("tests/Feature/FinanceReconciliationTest.php", (
            "test_all_currency_and_beneficiary_groups_reconcile_with_event_date_filters",
            "test_full_reconciliation_csv_and_ledger_pagination_do_not_leak_foreign_records",
            "test_reconciliation_validates_filters_and_preserves_them_across_pages",
            "test_finance_csv_is_private_and_safe_before_schema_migration",
        )),
    )),
    Evidence("REQ:GF-MIG-001", "GF-MIG-001", (
        ("tests/Feature/AdminMigrationReadinessTest.php", (
            "test_admin_sees_exact_pending_migration_inventory",
            "test_missing_operator_confirmation_never_runs_migrations",
        )),
    ), historical=True),
    Evidence("REQ:GF-MIG-002", "GF-MIG-002", (
        ("tests/Feature/AuthenticationTest.php", (
            "test_user_can_authenticate_and_logout",
            "test_invalid_credentials_do_not_authenticate",
        )),
        ("tests/Feature/OrganizationContextTest.php", (
            "test_queue_context_revalidates_membership_before_running",
            "test_queue_context_rejects_foreign_organization",
        )),
    ), historical=True),
    Evidence("REQ:GF-NFR-001", "GF-NFR-001", (), historical=True),
    Evidence("REQ:GF-NFR-002", "GF-NFR-002", ()),
)


def read_optional(path: Path) -> str | None:
    try:
        return path.read_text(encoding="utf-8")
    except (FileNotFoundError, UnicodeError, OSError):
        return None


def markdown_visible(source: str) -> str:
    """Ignore fenced examples and HTML comments when reading requirements."""
    # Unclosed HTML comments remain non-authoritative through end of file.
    without_comments = re.sub(r"<!--.*?(?:-->|\Z)", "", source, flags=re.S)
    visible = []
    fence_char = None
    fence_length = 0
    for line in without_comments.splitlines():
        found = re.match(r"^[ \t]{0,3}(`{3,}|~{3,})", line)
        if fence_char is None:
            if found:
                fence_char, fence_length = found.group(1)[0], len(found.group(1))
                continue
            visible.append(line)
        elif re.match(r"^[ \t]{0,3}" + re.escape(fence_char)
                      + "{" + str(fence_length) + r",}[ \t]*$", line):
            fence_char, fence_length = None, 0
    return "\n".join(visible)


def matrix_rows(source: str | None) -> dict[str, tuple[str, ...]]:
    """Malformed and duplicate rows are ambiguous; reject instead of guessing."""
    if source is None:
        return {}
    targets = {case.row for case in CASES}
    rows: dict[str, tuple[str, ...]] = {}
    for line in markdown_visible(source).splitlines():
        if not line.startswith("| REQ:GF-"):
            continue
        columns = tuple(piece.strip() for piece in line.strip().strip("|").split("|"))
        row = columns[0]
        if row not in targets:
            continue
        if row in rows:
            raise ValueError(f"{row}: duplicate coverage row")
        if len(columns) != 8:
            raise ValueError(f"{row}: expected eight coverage columns")
        if columns[2] not in {"NO_VERIFICABLE", "SIN_COBERTURA", "EN_CURSO", "CUBIERTO", "DESCARTADO"}:
            raise ValueError(f"{row}: invalid coverage state")
        rows[row] = columns
    return rows


def canonical_section(source: str | None, ident: str) -> str | None:
    if source is None:
        return None
    source = markdown_visible(source)
    pattern = re.compile(r"(?m)^### " + re.escape(ident) + r"(?:\s+|\s*[—–-])[^\n]*$")
    matches = list(pattern.finditer(source))
    if len(matches) != 1:
        return None
    tail = source[matches[0].end():]
    return re.split(r"(?m)^#{2,3}\s+", tail, maxsplit=1)[0]


def php_code_only(source: str) -> str:
    """Mask PHP comments, quoted strings and heredoc/nowdoc without shifting offsets.

    Fail closed on unterminated literals: no trailing apparent assertions are code.
    """
    chars = list(source)
    n = len(source)

    def mask(start: int, end: int) -> None:
        for pos in range(start, end):
            if chars[pos] not in ("\n", "\r"):
                chars[pos] = " "

    i = 0
    in_php = True  # Snippet support; repository evidence requires a real <?php tag.
    while i < n:
        if not in_php:
            if source.startswith("<?php", i) or source.startswith("<?=", i):
                i += 5 if source.startswith("<?php", i) else 3
                in_php = True
                continue
            mask(i, i + 1)
            i += 1
            continue
        if source.startswith("?>", i):
            mask(i, i + 2)
            i += 2
            in_php = False
            continue
        if source.startswith("//", i) or source[i] == "#":
            end = source.find("\n", i)
            end = n if end < 0 else end
            mask(i, end)
            i = end
            continue
        if source.startswith("/*", i):
            end = source.find("*/", i + 2)
            end = n if end < 0 else end + 2
            mask(i, end)
            i = end
            continue
        if source[i] in ('"', "'", "`"):
            quote = source[i]
            start = i
            i += 1
            while i < n:
                if source[i] == "\\":
                    i += 2
                elif source[i] == quote:
                    i += 1
                    break
                else:
                    i += 1
            mask(start, min(i, n))
            continue
        if source.startswith("<<<", i):
            marker = re.match(
                r"<<<[ \t]*(?:'([A-Za-z_]\w*)'|\"([A-Za-z_]\w*)\"|([A-Za-z_]\w*))(?=[ \t]*\r?\n)",
                source[i:],
            )
            if marker:
                label = next(v for v in marker.groups() if v is not None)
                start = i
                lineend = source.find("\n", i)
                linepos = n if lineend == -1 else lineend + 1
                # Only an exact standalone/flexible PHP heredoc ending closes the mask.
                end = n
                while linepos < n:
                    endline = source.find("\n", linepos)
                    endline = n if endline < 0 else endline
                    line = source[linepos:endline]
                    if re.match(r"^[ \t]*" + re.escape(label) + r"(?=$|[;,)\]}])", line):
                        end = endline
                        break
                    linepos = min(n, endline + 1)
                mask(start, end)
                i = end
                continue
        i += 1
    return "".join(chars)


def php_method(source: str | None, method: str) -> str | None:
    """Extract the exact named method body, excluding literals/comments and next methods."""
    if not source:
        return None
    code = php_code_only(source)
    pattern = re.compile(
        r"\bpublic\s+function\s+" + re.escape(method) +
        r"\s*\(\s*\)\s*:\s*void\s*\{"
    )
    matches = list(pattern.finditer(code))
    if len(matches) != 1:
        return None
    start = matches[0].end()
    depth = 1
    end = start
    while end < len(code):
        if code[end] == "{":
            depth += 1
        elif code[end] == "}":
            depth -= 1
            if depth == 0:
                break
        end += 1
    if depth != 0:
        return None
    body = code[start:end]
    assertion = re.search(
        r"(?:\$this\s*->\s*assert[A-Z]\w*|(?:self|static)\s*::\s*assert[A-Z]\w*)\s*\(",
        body,
    )
    if assertion:
        return body
    # Laravel HTTP TestResponse assertions are meaningful when reached from
    # an actual test request on $this, not a disconnected collaborator.
    # Restrict to one PHP statement and a closed set of HTTP assertion methods.
    http_request = re.compile(
        r"\$this\s*->\s*(?:actingAs|withSession|get|post|put|patch|delete|json|getJson|postJson)"
        r"\s*\("
    )
    http_assert = re.compile(
        r"->\s*assert(?:Ok|Created|Accepted|NoContent|Forbidden|Unauthorized|"
        r"NotFound|Status|See|DontSee|Redirect|SessionHasErrors|Json|ExactJson)"
        r"\s*\("
    )
    if any(http_request.search(chunk) and http_assert.search(chunk)
           for chunk in body.split(";")):
        return body
    # PHPUnit expectException only counts if a subsequent executable operation follows.
    expectation = re.search(r"\$this\s*->\s*expectException\s*\([^;]*\)\s*;", body)
    if expectation:
        after = body[expectation.end():]
        if (re.search(r"\$[A-Za-z_]\w*\s*->\s*(?!expectException\b)[A-Za-z_]\w*\s*\(", after)
                or re.search(r"\bapp\s*\([^;]*\)\s*->\s*[A-Za-z_]\w*\s*\(", after)):
            return body
    return None


def external_evidence_status(*, exact_sha: str, run_sha: str,
                             authenticated: bool, terminal_success: bool) -> str:
    """Even trusted-looking metadata is only CI evidence, never deployment evidence."""
    if not (type(authenticated) is bool and authenticated is True
            and type(terminal_success) is bool and terminal_success is True):
        return UNKNOWN
    if not (isinstance(exact_sha, str) and isinstance(run_sha, str)):
        return UNKNOWN
    if not re.fullmatch(r"[0-9a-f]{40}", exact_sha) or run_sha != exact_sha:
        return UNKNOWN
    return "EXACT_SHA_CI_ONLY"


def evidence_status(case: Evidence, source_files: dict[str, str | None]) -> str:
    if not case.files_and_tests:
        return UNKNOWN
    for path, methods in case.files_and_tests:
        source = source_files.get(path)
        # A code-looking declaration in comments, strings or inline HTML is
        # never evidence of a PHPUnit class or a database-isolated fixture.
        if not source or not source.lstrip().startswith("<?php"):
            return UNKNOWN
        php = php_code_only(source)
        if not (re.search(r"\buse\s+RefreshDatabase\s*;", php)
                and re.search(r"\bextends\s+TestCase\b", php)):
            return UNKNOWN
        if not all(php_method(source, method) is not None for method in methods):
            return UNKNOWN
    # Tests declared in source are not proof they ran on the exact PR HEAD.
    return DECLARED


def isolated_env(_inherited: dict[str, str] | None = None) -> dict[str, str]:
    """Do not propagate GitHub tokens, database URLs, or operator secrets."""
    return {"PATH": os.defpath, "LANG": "C", "HOME": "/nonexistent"}


def ci_scope(event: str, changed_paths: tuple[str, ...]) -> dict[str, str]:
    """Exercise the real path selector, with an allowlisted process environment."""
    env = isolated_env()
    try:
        result = subprocess.run(
            ["bash", str(CI_SCOPE), event], input="\n".join(changed_paths) + "\n",
            text=True, capture_output=True, timeout=10, cwd=ROOT, env=env, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise AssertionError(f"UNKNOWN: unable to exercise CI selector: {type(exc).__name__}") from exc
    if result.returncode:
        raise AssertionError(f"UNKNOWN: CI selector failed (exit {result.returncode})")
    values: dict[str, str] = {}
    for line in result.stdout.splitlines():
        key, sep, value = line.partition("=")
        if sep and key.startswith("run_"):
            if key in values or value not in {"true", "false"}:
                raise AssertionError("UNKNOWN: invalid CI selector output")
            values[key] = value
    expected = {"run_php_quality", "run_tests", "run_database", "run_browser", "run_realstack", "run_legacy", "run_symfony"}
    if not expected.issubset(values):
        raise AssertionError("UNKNOWN: incomplete CI selector output")
    return values


def source_registry() -> dict[str, str | None]:
    return {path: read_optional(ROOT / path)
            for case in CASES for path, _ in case.files_and_tests}


class BrechasGrindflow04Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """All five requirement IDs have an independently checked contract."""
        self.assertEqual(5, len(CASES))
        rows = matrix_rows(read_optional(MATRIX))
        self.assertEqual({case.row for case in CASES}, set(rows))
        req = read_optional(REQUIREMENTS)
        migration = read_optional(MIGRATION)
        sources = source_registry()
        for case in CASES:
            with self.subTest(requirement=case.row):
                source = migration if case.row.startswith("REQ:GF-MIG-") else req
                self.assertIsNotNone(canonical_section(source, case.source),
                                     f"{case.row}: UNKNOWN requirement source")
                self.assertEqual(8, len(rows[case.row]))
                if case.files_and_tests:
                    self.assertEqual(DECLARED, evidence_status(case, sources),
                                     f"{case.row}: UNKNOWN behavioral test contract")
                else:
                    # GF-NFR-001 is historical Laravel wording: target is now
                    # Symfony. Do not misrepresent it as delivered Laravel.
                    architecture = read_optional(ARCHITECTURE) or ""
                    self.assertIn("Symfony 7.4", architecture)
                    self.assertIn("monolito", architecture.lower())
                    self.assertEqual(UNKNOWN, evidence_status(case, sources))
        # The NFR-002 acceptance checks the actual path-based selector.
        scope = ci_scope("pull_request", ("tests/test_brechas_grindflow_04.py",))
        self.assertEqual("true", scope["run_tests"])
        self.assertEqual("true", scope["run_php_quality"])
        self.assertEqual("false", scope["run_browser"])
        docs = ci_scope("pull_request", ("docs/REQUIREMENTS.md",))
        self.assertEqual("false", docs["run_database"])
        self.assertEqual("false", docs["run_browser"])
        workflow = read_optional(CI_WORKFLOW) or ""
        self.assertIn("name: fast", workflow)
        self.assertIn("name: validate", workflow)
        self.assertIn("needs: preflight", workflow)

    def test_falla_cerrado(self) -> None:
        """Missing, duplicated, synthetic and commented assertions are UNKNOWN."""
        case = CASES[0]
        self.assertEqual(UNKNOWN, evidence_status(case, {}))
        empty = "class Test extends TestCase {\n use RefreshDatabase;\n }"
        self.assertEqual(UNKNOWN, evidence_status(case, {
            case.files_and_tests[0][0]: empty,
        }))
        example = "public function test_fixture(): void { // $this->assertTrue(true);\n}\n"
        self.assertIsNone(php_method(example, "test_fixture"))
        executable = "public function test_fixture(): void {\n $this->assertTrue(true);\n}\n"
        self.assertIsNotNone(php_method(executable, "test_fixture"))
        self.assertIsNone(php_method(executable + executable, "test_fixture"))
        self.assertIsNone(canonical_section("", "GF-MIG-001"))
        self.assertEqual({}, matrix_rows(None))
        sample = "| REQ:GF-NFR-002 | nfr | NO_VERIFICABLE | ref | #2 | NO_ACREDITADO | NO_ACREDITADO | NO_ACREDITADO |"
        with self.assertRaisesRegex(ValueError, "duplicate"):
            matrix_rows(sample + "\n" + sample)
        self.assertEqual({}, matrix_rows("```md\n" + sample + "\n```"))
        self.assertEqual({}, matrix_rows("<!--\n" + sample + "\n-->"))
        self.assertEqual({"REQ:GF-NFR-002"},
                         set(matrix_rows("<!--\n" + sample + "\n-->\n" + sample)))
        self.assertIsNone(canonical_section("```md\n### GF-NFR-001 foo\n```", "GF-NFR-001"))
        self.assertIsNone(canonical_section("<!--\n### GF-NFR-001 foo\n-->", "GF-NFR-001"))
        with self.assertRaisesRegex(ValueError, "eight"):
            matrix_rows("| REQ:GF-NFR-002 | invalid |")
        with self.assertRaisesRegex(ValueError, "invalid coverage"):
            matrix_rows(sample.replace("NO_VERIFICABLE", "PRODUCTION_APPROVED"))
        # Lexer and method-boundary adversarial tests: a string or next method is not code.
        for literal in (
            '"$this->assertTrue(true);"',
            "'$this->assertTrue(true);'",
            '`$this->assertTrue(true);`',
            '<<<EOT\n$this->assertTrue(true);\nEOT;',
            "<<<'EOT'\n$this->assertTrue(true);\nEOT;",
            '<<<"EOT"\n$this->assertTrue(true);\nEOT;',
            '<<<EOT\n$this->assertTrue(true);',  # unterminated
        ):
            with self.subTest(literal=literal):
                fake = 'public function test_fixture(): void {\n' + literal + '\n}'
                self.assertIsNone(php_method(fake, "test_fixture"))
        self.assertIsNone(php_method(
            'public function test_fixture(): void {\n $a = 1;\n}\n'
            'public function test_later(): void { $this->assertTrue(true); }',
            'test_fixture',
        ))
        self.assertIsNone(php_method(
            'public function test_fixture(): void { $this->expectException(\\Exception::class); }',
            'test_fixture',
        ))
        self.assertIsNone(php_method(
            'public function test_fixture(): void { \"$this->assertTrue(true);\" ',
            'test_fixture',
        ))
        self.assertIsNotNone(php_method(
            'public function test_fixture(): void {\n<<<EOT\n$this->assertTrue(true);\nEOT;\n'
            '$this->assertSame(1, 1);\n}', 'test_fixture',
        ))
        # PHP inline HTML is echoed, not executed PHPUnit code. Reopen tags
        # after the HTML must allow actual executable assertions again.
        inline = ('<?php public function test_fixture(): void { ?>'
                  '<div>$this->assertTrue(true);</div><?php }')
        self.assertIsNone(php_method(inline, "test_fixture"))
        self.assertIsNone(php_method(
            '<?php public function test_fixture(): void { ?>'
            '<?xml version="1.0"?><div>$this->assertTrue(true)</div><?php }',
            "test_fixture"))
        self.assertIsNotNone(php_method(
            '<?php public function test_fixture(): void { ?>'
            '<div>$this->assertTrue(true)</div><?php $this->assertSame(1, 1); }',
            "test_fixture"))
        # In the old implementation, fake traits/classes in PHP comments were
        # enough for ASSERTING_TEST_DECLARED: this must now fail closed.
        synthetic = Evidence('REQ:GF-NFR-002', 'GF-NFR-002',
                             (('tests/FakeTest.php', ('test_fixture',)),))
        bogus = ('<?php // use RefreshDatabase;\n'
                 '// extends TestCase\n'
                 'public function test_fixture(): void {$this->assertTrue(true);}')
        self.assertEqual(UNKNOWN, evidence_status(synthetic, {'tests/FakeTest.php': bogus}))
        executable_source = ('<?php class FakeTest extends TestCase { use RefreshDatabase; '
                             'public function test_fixture(): void {$this->assertTrue(true);}}')
        self.assertEqual(DECLARED, evidence_status(synthetic,
                         {'tests/FakeTest.php': executable_source}))
        self.assertEqual(UNKNOWN, evidence_status(synthetic,
                         {'tests/FakeTest.php': executable_source.removeprefix('<?php ')}))
        valid = 'a' * 40
        self.assertEqual('EXACT_SHA_CI_ONLY', external_evidence_status(
            exact_sha=valid, run_sha=valid, authenticated=True, terminal_success=True))
        for bad in ('false', 1, [], None):
            with self.subTest(bad=bad):
                self.assertEqual(UNKNOWN, external_evidence_status(
                    exact_sha=valid, run_sha=valid, authenticated=bad, terminal_success=True))
                self.assertEqual(UNKNOWN, external_evidence_status(
                    exact_sha=valid, run_sha=valid, authenticated=True, terminal_success=bad))
        for bad_sha in ('not-a-sha', 'A'*40, 'x'*40, 'a'*39):
            self.assertEqual(UNKNOWN, external_evidence_status(
                exact_sha=bad_sha, run_sha=bad_sha, authenticated=True, terminal_success=True))
        # A name or successful static parse never upgrades operational state.
        for item in CASES:
            self.assertEqual("UNKNOWN", operation_state(item, None)["production"])

    def test_sin_datos_reales(self) -> None:
        """Only local file reads and a shell CI selector with isolated env."""
        xml = read_optional(PHPUNIT_XML) or ""
        self.assertIn('name="APP_ENV" value="testing"', xml)
        self.assertIn('name="DB_CONNECTION" value="sqlite"', xml)
        self.assertIn('name="DB_DATABASE" value=":memory:"', xml)
        scope = ci_scope("pull_request", ("README.md",))
        self.assertEqual("false", scope["run_tests"])
        code = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(code, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(code, r"(?m)^(?:import|from)\s+(?:requests|httpx|urllib|socket)\b")
        self.assertNotRegex(code, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertNotIn("https://" + "api.github.com", code)
        isolated = isolated_env({"GITHUB_TOKEN": "do-not-propagate",
                                 "DATABASE_URL": "private-db",
                                 "AWS_SECRET_ACCESS_KEY": "private-storage"})
        self.assertEqual({"PATH", "LANG", "HOME"}, set(isolated))
        self.assertNotIn("GITHUB_TOKEN", isolated)
        self.assertNotIn("DATABASE_URL", isolated)
        self.assertNotIn("AWS_SECRET_ACCESS_KEY", isolated)

    def test_estados_unknown_explicitos(self) -> None:
        """Local code evidence never certifies exact CI, Hostinger or live."""
        source = source_registry()
        for case in CASES:
            with self.subTest(requirement=case.row):
                status = operation_state(case, source)
                self.assertEqual("UNKNOWN", status["ci_exact_head"])
                self.assertEqual("UNKNOWN", status["hostinger"])
                self.assertEqual("UNKNOWN", status["production"])
                if case.historical:
                    self.assertEqual("UNKNOWN", status["full_requirement"])
        rows = matrix_rows(read_optional(MATRIX))
        for case in CASES:
            with self.subTest(matrix=case.row):
                # The matrix belongs to another leaf: never pin this test to
                # NO_VERIFICABLE or treat a later CUBIERTO as live evidence.
                self.assertIn(rows[case.row][2],
                              {"NO_VERIFICABLE", "SIN_COBERTURA", "EN_CURSO", "CUBIERTO", "DESCARTADO"})


def operation_state(case: Evidence, files: dict[str, str | None] | None) -> dict[str, str]:
    return {
        "local_test": evidence_status(case, files or {}),
        "full_requirement": UNKNOWN,
        "ci_exact_head": UNKNOWN,
        "hostinger": UNKNOWN,
        "production": UNKNOWN,
    }


if __name__ == "__main__":
    unittest.main()
