#!/usr/bin/env python3
"""#640 / GF348: real cross-module tenant/role/destination contracts.

Audit executable Laravel HTTP scenarios plus the production authorization
path. Where Composer is installed, re-run only six disposable SQLite cases;
the canonical CI "tests" job runs the complete PHP suite regardless.
No synthetic result is represented as production or Hostinger validation.
"""
from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

SOURCES = {
    "routes": "routes/web.php",
    "middleware": "app/Http/Middleware/ResolveOrganizationContext.php",
    "tenant": "app/Support/Tenancy/TenantContext.php",
    "scope": "app/Models/Scopes/TenantScope.php",
    "concern": "app/Models/Concerns/BelongsToOrganization.php",
    "destination": "app/Models/PublishingDestination.php",
    "store": "app/Http/Requests/Distribution/StoreDestinationRequest.php",
    "update": "app/Http/Requests/Distribution/UpdateDestinationRequest.php",
    "scheduler_request": "app/Http/Requests/Scheduling/StoreScheduledPublicationRequest.php",
    "scheduler": "app/Http/Controllers/Scheduling/SchedulerController.php",
    "distribution": "app/Http/Controllers/Distribution/DistributionController.php",
    "finance": "app/Http/Controllers/Finance/FinanceController.php",
    "user": "app/Models/User.php",
    "role": "app/Enums/UserRole.php",
    "distribution_test": "tests/Feature/DistributionTest.php",
    "scheduler_test": "tests/Feature/SchedulingTest.php",
    "finance_test": "tests/Feature/FinanceTest.php",
    "report_test": "tests/Feature/FinanceReconciliationTest.php",
    "ci": ".github/workflows/grindflow-ci.yml",
    "phpunit": "phpunit.xml",
}

PHP_SCENARIOS = (
    "test_model_distribution_navigation_hides_forbidden_traffic_link",
    "test_destination_edit_uses_target_uuid_after_organization_route_parameter",
    "test_model_role_cannot_create_schedules",
    "test_cross_tenant_destination_is_not_visible_to_scheduler_post",
    "test_editor_and_model_cannot_manage_finance",
    "test_finance_index_does_not_leak_foreign_entries",
)


def source_text() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8") for key, path in SOURCES.items()}


def method(text: str, name: str) -> str:
    """Extract the entire PHP body, not another test's unrelated 403/404."""
    match = re.search(r"\bfunction\s+" + re.escape(name) + r"\s*\([^)]*\)", text)
    if match is None:
        raise AssertionError("Missing PHP method: " + name)
    start = text.find("{", match.end())
    if start < 0:
        raise AssertionError("Missing method body: " + name)
    # These production/test methods do not contain braces in literal strings;
    # fail closed if an extracted block loses its closing brace.
    depth = 0
    for position in range(start, len(text)):
        if text[position] == "{":
            depth += 1
        elif text[position] == "}":
            depth -= 1
            if depth == 0:
                return text[start:position + 1]
    raise AssertionError("Unclosed PHP method: " + name)


def require_in(text: str, *parts: str) -> None:
    for part in parts:
        if part not in text:
            raise AssertionError("Missing cross-module security gate: " + part)


def audit_runtime(s: dict[str, str]) -> None:
    # Route group guards the entire organization subtree, not just the index.
    require_in(s["routes"],
               "Route::middleware(['auth', 'tenant.user'])",
               "->middleware('tenant.organization')",
               "'/organizations/{organizationId}'",
               "'organizations.distribution.destinations.store'",
               "'organizations.distribution.destinations.update'",
               "'organizations.scheduler.store'",
               "'organizations.finance.index'",
               "'organizations.finance.store'")
    require_in(method(s["middleware"], "handle"),
               "abort_unless($user instanceof User, 401)",
               "->visibleTo($user)", "->whereKey($organizationId)",
               "->firstOrFail()", "runWithinOrganization(")
    require_in(method(s["tenant"], "runWithinOrganization"),
               "->where('organization_id', $organizationId)",
               "->where('user_id', $userId)",
               "throw new AuthorizationException(")
    require_in(method(s["scope"], "apply"),
               "organizationId()", "whereRaw('1 = 0')",
               "qualifyColumn('organization_id')")
    require_in(s["concern"],
               "static::addGlobalScope(new TenantScope)",
               "Tenant context is required to create this record.",
               "Cross-tenant record creation is not allowed.",
               "organization_id is immutable.")
    require_in(s["destination"], "extends TenantModel",
               "'name'", "'provider'", "'status'")
    for request_name in ("store", "update"):
        auth = method(s[request_name], "authorize")
        require_in(auth, "$this->user()",
                   "attributes->get('tenantOrganization')",
                   "$user instanceof User",
                   "$organization instanceof Organization",
                   "$user->canManageOrganization($organization)")
    require_in(method(s["scheduler_request"], "authorize"),
               "$user->canScheduleOrganization($organization)")
    require_in(method(s["scheduler"], "store"),
               "MediaAsset::query()", "->findOrFail(",
               "PublishingDestination::query()",
               "->whereIn('id', $validated['destination_ids'])",
               "abort_unless($destinations->count() === count($validated['destination_ids']), 404)")
    require_in(method(s["distribution"], "updateDestination"),
               "PublishingDestination::query()",
               "->findOrFail($destinationId)",
               "->safe()->only(['name', 'status'])")
    for name in ("index", "export"):
        require_in(method(s["finance"], name),
                   "$user->canManageFinanceOrganization($organization)",
                   "abort_unless(", "->beneficiarySummaries(")
    require_in(method(s["user"], "canManageOrganization"),
               "UserRole::Admin->value", "UserRole::Studio->value",
               "->where('organization_id', $organizationId)",
               "->exists()")
    require_in(method(s["user"], "canManageFinanceOrganization"),
               "UserRole::Admin->value", "UserRole::Studio->value")
    require_in(method(s["user"], "canScheduleOrganization"),
               "UserRole::Admin->value", "UserRole::Studio->value",
               "UserRole::Editor->value")
    for name in ("canManageOrganization", "canManageFinanceOrganization", "canScheduleOrganization"):
        if "UserRole::Model->value" in method(s["user"], name):
            raise AssertionError("Model role granted cross-module mutating permission")
    require_in(s["role"],
               "case Admin = 'admin'", "case Studio = 'studio'",
               "case Editor = 'editor'", "case Model = 'model'")
    require_in(s["phpunit"], 'value=":memory:"', 'value="sqlite"')
    require_in(s["ci"], "vendor/bin/phpunit", "  tests:", "  validate:")


def audit_real_http_negatives(s: dict[str, str]) -> None:
    # Each case actually uses Laravel HTTP/SQLite fixtures and asserts
    # a response plus no-leak/no-write, not a fabricated policy function.
    d = method(s["distribution_test"], PHP_SCENARIOS[0])
    require_in(d, "Membership::query()->create(", "UserRole::Model",
               "UserRole::Editor", "->assertDontSee($traffic)",
               "->assertForbidden()")
    d = method(s["distribution_test"], PHP_SCENARIOS[1])
    require_in(d, "TenantContext::class", "->patch(route(",
               "'organizations.distribution.destinations.update'",
               "->assertRedirect()", "STATUS_DISABLED")
    scheduler_role = method(s["scheduler_test"], PHP_SCENARIOS[2])
    require_in(scheduler_role, "UserRole::Model", "->post(",
               "'organizations.scheduler.store'", "->assertForbidden()")
    cross = method(s["scheduler_test"], PHP_SCENARIOS[3])
    require_in(cross, "otherOrganization", "foreignDestination", "foreignAsset",
               "'organizationId' => $organization->getKey()",
               "'destination_id' => $foreignDestination->getKey()",
               "'asset_id' => $foreignAsset->getKey()",
               "->assertNotFound()",
               "ScheduledPublication::query()->count()")
    if cross.count("->assertNotFound()") != 2:
        raise AssertionError("Two independent cross-tenant scheduler denials required")
    finance_role = method(s["finance_test"], PHP_SCENARIOS[4])
    require_in(finance_role, "UserRole::Editor", "UserRole::Model",
               "'organizations.finance.index'",
               "'organizations.finance.store'")
    if finance_role.count("->assertForbidden()") < 2:
        raise AssertionError("Finance must deny both read and write")
    report = method(s["finance_test"], PHP_SCENARIOS[5])
    require_in(report, "Foreign secret record", "Local finance record",
               "runWithinOrganization(", "->assertDontSee('Foreign secret record')")
    reconciliation = method(s["report_test"],
                            "test_full_reconciliation_csv_and_ledger_pagination_do_not_leak_foreign_records")
    require_in(reconciliation, "->streamedContent()", "'Cache-Control'",
               "'no-store, private'", "assertStringNotContainsString('Foreign secret'",
               "assertStringNotContainsString('9000000'")
    # An authorization error must not be handled by making external calls.
    require_in(s["store"], "Rule::in(['sandbox'])")
    require_in(method(s["distribution"], "storeDestination"),
               "'provider' => 'sandbox'", "'metadata' => ['mode' => 'sandbox']")


def audit_mutated_contract(snapshot: dict[str, str], changed_source: str) -> None:
    """Exactly one audit invocation so exception tests remain unambiguous."""
    if changed_source.endswith("_test"):
        audit_real_http_negatives(snapshot)
    else:
        audit_runtime(snapshot)


class TeamDestinationsAuthorizationTests(unittest.TestCase):
    def test_team_role_destination_isolation(self) -> None:
        """AC-01: real Laravel gates plus their real HTTP/tenant negatives."""
        snapshot = source_text()
        audit_runtime(snapshot)
        audit_real_http_negatives(snapshot)

    def test_fail_closed_when_any_guard_or_http_negative_is_removed(self) -> None:
        snapshot = source_text()
        mutations = (
            ("scope", "whereRaw('1 = 0')"),
            ("middleware", "->visibleTo($user)"),
            ("store", "$user->canManageOrganization($organization)"),
            ("update", "$user->canManageOrganization($organization)"),
            ("scheduler", "abort_unless($destinations->count() === count($validated['destination_ids']), 404)"),
            ("tenant", "->where('user_id', $userId)"),
            ("distribution_test", "->assertDontSee($traffic)"),
            ("scheduler_test", "'destination_id' => $foreignDestination->getKey()"),
            ("finance_test", "->assertDontSee('Foreign secret record')"),
        )
        for key, token in mutations:
            with self.subTest(source=key, token=token):
                self.assertIn(token, snapshot[key])
                modified = dict(snapshot)
                modified[key] = snapshot[key].replace(token, "/* missing gate */", 1)
                with self.assertRaises(AssertionError):
                    audit_mutated_contract(modified, key)

    def test_role_and_tenant_fixture_matrix_cannot_promote_model(self) -> None:
        """Synthetic cross-product checks don't claim to substitute PHP HTTP."""
        s = source_text()
        role_code = {
            "manage": method(s["user"], "canManageOrganization"),
            "schedule": method(s["user"], "canScheduleOrganization"),
            "finance": method(s["user"], "canManageFinanceOrganization"),
        }
        permitted = {
            operation: set(re.findall(r"UserRole::(Admin|Studio|Editor|Model)->value", code))
            for operation, code in role_code.items()
        }
        self.assertEqual({"Admin", "Studio"}, permitted["manage"])
        self.assertEqual({"Admin", "Studio", "Editor"}, permitted["schedule"])
        self.assertEqual({"Admin", "Studio"}, permitted["finance"])
        tenants = ("synthetic-a", "synthetic-b")
        for role in ("Admin", "Studio", "Editor", "Model"):
            for actor_tenant in tenants:
                for resource_tenant in tenants:
                    for operation, allowed_roles in permitted.items():
                        allowed = role in allowed_roles and actor_tenant == resource_tenant
                        with self.subTest(role=role, actor=actor_tenant,
                                          resource=resource_tenant, action=operation):
                            if actor_tenant != resource_tenant or role == "Model":
                                self.assertFalse(allowed)
                            elif role in allowed_roles:
                                self.assertTrue(allowed)

    def test_real_disposable_phpunit_scenarios_when_composer_is_available(self) -> None:
        runner = ROOT / "vendor/bin/phpunit"
        if not runner.is_file():
            self.skipTest("Composer absent; official CI tests job installs and runs PHPUnit")
        pattern = "|".join(PHP_SCENARIOS)
        run = subprocess.run(
            ["php", str(runner), "--colors=never", "--filter", pattern],
            cwd=ROOT, capture_output=True, text=True, timeout=90, check=False,
        )
        self.assertEqual(0, run.returncode, run.stdout[-1200:] + run.stderr[-1200:])
        self.assertRegex(run.stdout, r"OK \(6 tests, [1-9][0-9]* assertions\)")


if __name__ == "__main__":
    unittest.main()
