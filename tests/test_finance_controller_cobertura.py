"""Executable acceptance for the tenant-safe FinanceController regression suite."""

from __future__ import annotations

from pathlib import Path
import re
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "app/Http/Controllers/Finance/FinanceController.php"
PHP_TEST = ROOT / "tests/Unit/FinanceControllerTest.php"

# The canonical controller is loaded without Laravel for the pure CSV boundary
# only. Full tenant/permission assertions are PHPUnit HTTP tests in PHP_TEST.
ISOLATED_PHP = r"""
namespace App\Http\Controllers { class Controller {} }
namespace {
    require __CONTROLLER__;
    $method = new \ReflectionMethod(
        \App\Http\Controllers\Finance\FinanceController::class,
        'safeCsvText'
    );
    foreach ([
        '=1+1' => "'=1+1",
        ' +HYPERLINK(A1)' => "' +HYPERLINK(A1)",
        "\t-3" => "'\t-3",
        '@formula' => "'@formula",
        'Normal beneficiary' => 'Normal beneficiary',
        '10 USD' => '10 USD',
    ] as $input => $expected) {
        if ($method->invoke(null, $input) !== $expected) {
            fwrite(STDERR, 'CSV escaping contract failed');
            exit(1);
        }
    }
    echo "controller-csv-safe\n";
}
"""


class FinanceControllerCoberturaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file())
        self.assertTrue(PHP_TEST.is_file())
        source = PHP_TEST.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")

        # The acceptance file owns an actual PHPUnit HTTP regression suite,
        # not a list of names or hand-made substitutes for Laravel routing.
        self.assertIn("extends TestCase", source)
        self.assertIn("use RefreshDatabase;", source)
        self.assertIn("FinanceController::class", source)
        self.assertIn("Route::getRoutes()->getByName", source)
        for operation in ("index", "export", "store", "reverse"):
            self.assertIn(f"organizations.finance.{operation}", source)
            self.assertRegex(controller, rf"public function {operation}\(")
        for test_name in (
            "test_routes_dispatch_to_the_real_finance_controller",
            "test_unauthorized_roles_cannot_read_export_create_or_reverse",
            "test_invalid_minor_units_and_foreign_beneficiaries_never_create_entries",
            "test_reversal_is_append_only_once_and_foreign_tenant_cannot_reverse",
            "test_reconciliation_and_csv_keep_currency_and_tenant_boundaries",
        ):
            self.assertRegex(source, rf"function {re.escape(test_name)}\(")
        for invariant in (
            "assertForbidden()", "assertNotFound()",
            "assertSessionHasErrors('amount_minor')",
            "assertSessionHasErrors('beneficiary_user_id')",
            "assertSessionHasErrors('allocation')",
            "RevenueAllocation::query()->sole()",
            "viewData('currencySummaries')",
            "streamedContent()",
        ):
            self.assertIn(invariant, source)

        lint = subprocess.run(
            ["php", "-l", str(PHP_TEST)],
            cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
        )
        self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)

        script = ISOLATED_PHP.replace(
            "__CONTROLLER__", repr(str(CONTROLLER)).replace('"', '\\"')
        )
        # repr produces a PHP-compatible single-quoted path on Linux.
        check = subprocess.run(
            ["php", "-r", script],
            cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
        )
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
        self.assertIn("controller-csv-safe", check.stdout)

        # When the application test runner is available, also execute the
        # Laravel/PHPUnit suite against its disposable testing database.
        phpunit = ROOT / "vendor/bin/phpunit"
        if phpunit.is_file():
            run = subprocess.run(
                [str(phpunit), "--configuration", "phpunit.xml", str(PHP_TEST)],
                cwd=ROOT, text=True, capture_output=True, timeout=120,
                check=False,
            )
            self.assertEqual(run.returncode, 0, run.stdout + run.stderr)


if __name__ == "__main__":
    unittest.main()
