from __future__ import annotations

from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
HTACCESS = (ROOT / "public/.htaccess").read_text(encoding="utf-8")
BRIDGE = (ROOT / "public/s4.php").read_text(encoding="utf-8")
DEPLOY = (ROOT / "scripts/deploy-hostinger.sh").read_text(encoding="utf-8")
SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
README = (ROOT / "README.md").read_text(encoding="utf-8")



class S4ProductionBridgeTests(unittest.TestCase):
    def test_only_s4_prefix_is_dispatched_to_symfony_kernel(self) -> None:
        s4_rule = "RewriteRule ^s4(?:/.*)?$ s4.php [QSA,L]"
        laravel_rule = "RewriteRule ^ index.php [L]"
        self.assertIn(s4_rule, HTACCESS)
        self.assertIn(laravel_rule, HTACCESS)
        self.assertLess(HTACCESS.index(s4_rule), HTACCESS.index(laravel_rule))

        self.assertIn("$path !== '/s4' && !str_starts_with($path, '/s4/')", BRIDGE)
        self.assertIn("$_SERVER['SCRIPT_NAME'] = '/s4/index.php';", BRIDGE)
        self.assertIn("$_SERVER['PHP_SELF'] = '/s4/index.php';", BRIDGE)
        self.assertIn("new Kernel(", BRIDGE)
        self.assertNotIn("require dirname(__DIR__).'/bootstrap/app.php'", BRIDGE)

    def test_deploy_prepares_symfony_without_automatic_migrations_or_secret_logging(self) -> None:
        self.assertIn('[[ -f symfony/composer.lock ]]', DEPLOY)
        self.assertIn("cd symfony", DEPLOY)
        self.assertIn('"$COMPOSER_BIN" install', DEPLOY)
        self.assertIn("--no-dev", DEPLOY)
        self.assertIn("--no-interaction", DEPLOY)

        lowered = DEPLOY.lower()
        self.assertNotIn("doctrine:migrations:migrate", lowered)
        self.assertNotIn("doctrine:schema:update", lowered)
        self.assertNotIn("grindflow:symfony:provision", lowered)
        self.assertNotIn("database_url=", lowered)
        self.assertNotIn("app_secret=", lowered)

    def test_bridge_preflight_emits_only_allowlisted_states(self) -> None:
        self.assertIn("S4_PREFLIGHT_CONTRACT = 's4-bridge-readiness-v1'", BRIDGE)
        for state in (
            "runtime_unavailable",
            "config_missing",
            "schema_missing",
            "identity_unavailable",
            "ready_for_web_probe",
        ):
            self.assertIn(f"'{state}'", BRIDGE)

        self.assertIn("'/s4/_bridge-readiness'", BRIDGE)
        self.assertIn("createSchemaManager()", BRIDGE)
        self.assertIn("gf_identity_users", BRIDGE)
        self.assertIn("gf_identity_memberships", BRIDGE)
        self.assertIn("actor.is_active = 1", BRIDGE)
        self.assertIn("['email' => S4_SYNTHETIC_IDENTITY]", BRIDGE)
        self.assertIn("JSON_UNESCAPED_SLASHES", BRIDGE)

        for forbidden in (
            "getMessage()",
            "getTrace",
            "DATABASE_URL' =>",
            "APP_SECRET' =>",
            "password_hash",
        ):
            self.assertNotIn(forbidden, BRIDGE)

    def test_negative_paths_never_reach_provider_or_leak_runtime_details(self) -> None:
        prefix_guard = BRIDGE.index("$path !== '/s4'")
        kernel_boot = BRIDGE.index("new Kernel(")
        self.assertLess(prefix_guard, kernel_boot)
        self.assertIn("http_response_code(404)", BRIDGE)
        self.assertIn("!is_file($bootstrap) || !is_file($autoload)", BRIDGE)
        self.assertIn("catch (Throwable)", BRIDGE)

        lowered = BRIDGE.lower()
        self.assertNotIn("facebook", lowered)
        self.assertNotIn("publish", lowered)
        self.assertNotIn("access_token", lowered)
        self.assertNotIn("cookie", lowered)
        self.assertNotIn("authorization", lowered)

        smoke_s4 = SMOKE[
            SMOKE.index("check_s4_media_web_runtime_readiness() {"):
            SMOKE.index("# One anonymous GET")
        ]
        self.assertNotIn('--cookie "$cookie_jar"', smoke_s4)
        self.assertNotIn('--cookie-jar "$cookie_jar"', smoke_s4)

    def test_production_smoke_targets_prefixed_symfony_runtime_and_remains_fail_closed(self) -> None:
        self.assertIn("check_s4_media_web_runtime_readiness() {", SMOKE)
        self.assertIn('s4_cookie_jar="$workdir/s4-cookies.txt"', SMOKE)
        self.assertIn("S4_BRIDGE_STATE=%s", SMOKE)
        self.assertIn("S4_AUTH_STATE=ready", SMOKE)
        self.assertIn('--cookie "$s4_cookie_jar" --cookie-jar "$s4_cookie_jar"', SMOKE)
        self.assertIn('--data-urlencode "_csrf_token@$s4_login_csrf_file"', SMOKE)
        self.assertIn('--data-urlencode "organization_id@$s4_organization_id_file"', SMOKE)
        for route in (
            "/s4/_bridge-readiness",
            "/s4/login",
            "/s4/organizations",
            "/s4/organizations/select",
            "/s4/api/admin/schedules/media-readiness",
        ):
            self.assertIn(route, SMOKE)
        self.assertIn("check_media_web_runtime_readiness s4", SMOKE)
        self.assertIn("Symfony /s4", WORKFLOW)

    def test_v0196_manifests_and_readme_are_synchronized(self) -> None:
        self.assertIn("'number' => '0.1.196'", VERSION)
        self.assertEqual("0.1.196", PACKAGE["version"])
        self.assertEqual("0.1.196", LOCK["version"])
        self.assertEqual("0.1.196", LOCK["packages"][""]["version"])
        self.assertIn("V0.1.196", README)


if __name__ == "__main__":
    unittest.main()
