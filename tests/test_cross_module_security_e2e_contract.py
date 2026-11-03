#!/usr/bin/env python3
"""Guard the real cross-module HTTP/browser security regressions for GF-NFR-006.

The Python acceptance target is intentionally offline. It audits executable
PHPUnit/Chromium assertions already exercised by disposable CI, not mock
security decisions or a production endpoint. A missing assertion fails closed.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
JOURNEY = ROOT / "symfony/tests/php/MvpVaultToDistributionJourneyTest.php"
PHPUNIT = ROOT / "symfony/phpunit.xml.dist"
CI = ROOT / ".github/workflows/grindflow-ci.yml"
BROWSER = ROOT / "scripts/browser-workflow.sh"
SMOKE = ROOT / "scripts/browser-smoke.sh"
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"

BROWSER_GATES = (
    "PASS: real browser login",
    "PASS: Scheduler 106 ready assets",
    "PASS: search deep media/link",
    "PASS: navigate real calendar page 2",
    "PASS: detach and reattach tracked link",
    "PASS: edit existing Traffic link",
    "PASS: pause/resume link",
    "PASS: authenticated Traffic CSV",
    "PASS: Finance append-only allocation",
    "PASS: reversal and filtered grouped Finance CSV",
)
MODULE_ROUTES = (
    "/api/admin/vault",
    "/api/admin/content-reviews/",
    "/api/admin/distribution-authorizations/",
    "/api/admin/schedules",
)
CSRF_CONTEXTS = (
    "vault_upload_csrf",
    "vault_manage_csrf",
    "content_review_csrf",
    "distribution_authorization_csrf",
    "schedule_draft_csrf",
)


def _need(haystack: str, needle: str, reason: str) -> None:
    if needle not in haystack:
        raise AssertionError(reason + ": " + needle)


def _method(source: str, name: str) -> str:
    marker = "public function " + name + "(): void"
    _need(source, marker, "Missing real PHPUnit HTTP scenario")
    start = source.index(marker)
    following = re.search(r"\n    (?:public|private|protected) function ", source[start + len(marker):])
    end = start + len(marker) + following.start() if following else len(source)
    return source[start:end]


def audit_http_journey(source: str) -> None:
    """Check real request/response tests, not comments or simulated handlers."""
    _need(source, "extends WebTestCase", "Must exercise Symfony's HTTP kernel")
    positive = _method(source, "testFullAssetJourneyPreservesTenantAndExplicitAuthorization")
    negative = _method(source, "testCrossTenantOrUnauthorizedJourneyDoesNotCallTransport")
    for section, name in ((positive, "positive"), (negative, "negative")):
        _need(section, "static::createClient()", name + " must use actual HTTP client")
        _need(section, "$this->seed($db)", name + " must use disposable tenant fixture")
        _need(section, "$this->installNoNetworkTransport", name + " must forbid provider I/O")
        for route in MODULE_ROUTES:
            _need(section, route, name + " missing module route")

    for token in CSRF_CONTEXTS:
        _need(positive, "HTTP_X_CSRF_TOKEN' => $context['" + token + "']",
              "Positive request needs its scoped CSRF token")
    for status, minimum in ((401, 4), (403, 4), (404, 4), (409, 1)):
        hits = negative.count("assertResponseStatusCodeSame(" + str(status) + ")")
        if hits < minimum:
            raise AssertionError("Negative HTTP boundary lacks status %d: %d < %d" %
                                 (status, hits, minimum))
    for required in (
        "asset_not_eligible",
        "['foreignAsset']",
        "$db->update('gf_identity_memberships'",
        "'role' => 'model'",
        "'SELECT COUNT(*) FROM '.$table.' WHERE organization_id IN",
        "self::assertSame(0, $transport->calls);",
    ):
        _need(negative, required, "Tenant/role/no-side-effect regression missing")
    for table in (
        "gf_content_review_events", "gf_distribution_authorization_events",
        "gf_schedule_drafts", "gf_external_publication_attempts",
    ):
        _need(negative, table, "No-write check omits a module")


def audit_browser_contract(source: str) -> None:
    _need(source, "E2E_USER_PASSWORD", "Browser login must be authenticated")
    _need(source, "trap cleanup_workflow EXIT", "Temporary browser fixtures must be cleaned")
    _need(source, 'grep -Fq "$E2E_USER_PASSWORD" "$dom"',
          "Browser artifacts need credential leak guard")
    _need(source, 'grep -Fq "$gate" "$dom"', "Browser milestones must fail the command")
    _need(source, 'exit 1', "Missing browser gates must fail")
    for gate in BROWSER_GATES:
        _need(source, "'" + gate + "'", "Real browser milestone missing")


def audit_ci_contract(ci: str, phpunit: str, smoke: str, requirements: str) -> None:
    _need(requirements, "### GF-NFR-006", "Missing source requirement")
    _need(requirements, "Scheduler → Traffic → Finance", "Missing browser requirement")
    _need(phpunit, '<directory>tests/php</directory>', "Real PHP scenario not discovered")
    _need(ci, "  symfony-preview:", "Symfony integration job missing")
    _need(ci, "php vendor/bin/simple-phpunit -c phpunit.xml.dist",
          "Disposable DB HTTP suite not executed")
    _need(ci, "  validate:", "Required aggregate gate missing")
    _need(ci, "symfony-preview", "Aggregate must account for Symfony")
    _need(smoke, "bash scripts/browser-workflow.sh",
          "Real authenticated browser journey not invoked")


class CrossModuleSecurityE2ETests(unittest.TestCase):
    def test_tenant_csrf_permissions_cases(self) -> None:
        """AC-01: verify actual HTTP negatives and browser milestones remain gated."""
        audit_http_journey(JOURNEY.read_text(encoding="utf-8"))
        audit_browser_contract(BROWSER.read_text(encoding="utf-8"))
        audit_ci_contract(
            CI.read_text(encoding="utf-8"),
            PHPUNIT.read_text(encoding="utf-8"),
            SMOKE.read_text(encoding="utf-8"),
            REQUIREMENTS.read_text(encoding="utf-8"),
        )

    def test_guard_rejects_missing_cross_tenant_or_csrf_assertion(self) -> None:
        source = JOURNEY.read_text(encoding="utf-8")
        for altered in (
            source.replace("assertResponseStatusCodeSame(404)", "assertResponseIsSuccessful()", 1),
            source.replace("'role' => 'model'", "'role' => 'admin'", 1),
            source.replace("'vault_upload_csrf'", "'missing_vault_token'", 1),
            source.replace("self::assertSame(0, $transport->calls);", "", 2),
        ):
            with self.subTest(case=altered != source):
                self.assertNotEqual(source, altered)
                with self.assertRaises(AssertionError):
                    audit_http_journey(altered)

    def test_browser_milestone_and_ci_wiring_fail_closed(self) -> None:
        browser = BROWSER.read_text(encoding="utf-8")
        ci = CI.read_text(encoding="utf-8")
        for gate in BROWSER_GATES:
            with self.subTest(gate=gate):
                with self.assertRaises(AssertionError):
                    audit_browser_contract(browser.replace("'" + gate + "'", "'MISSING-GATE'", 1))
        with self.assertRaises(AssertionError):
            audit_ci_contract(
                ci.replace("php vendor/bin/simple-phpunit -c phpunit.xml.dist", "true", 1),
                PHPUNIT.read_text(encoding="utf-8"),
                SMOKE.read_text(encoding="utf-8"),
                REQUIREMENTS.read_text(encoding="utf-8"),
            )


if __name__ == "__main__":
    unittest.main()
