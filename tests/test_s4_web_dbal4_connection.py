from __future__ import annotations

import json
from pathlib import Path
import re
import subprocess
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[1]
BRIDGE = (ROOT / "public/s4.php").read_text(encoding="utf-8")
DIAGNOSTIC = (ROOT / "scripts/s4-bridge-diagnostic.php").read_text(encoding="utf-8")
PRODUCTION_SMOKE = (ROOT / "scripts/production-smoke.sh").read_text(encoding="utf-8")
SMOKE_WORKFLOW = (ROOT / ".github/workflows/production-smoke.yml").read_text(encoding="utf-8")
VERSION = (ROOT / "config/version.php").read_text(encoding="utf-8")
PACKAGE = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
LOCK = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))
README = (ROOT / "README.md").read_text(encoding="utf-8")


class S4WebDbal4ConnectionTests(unittest.TestCase):
    def test_web_bridge_uses_dsn_parser_and_never_legacy_url_parameter(self) -> None:
        self.assertIn("use Doctrine\\DBAL\\Tools\\DsnParser;", BRIDGE)
        self.assertIn("new DsnParser([", BRIDGE)
        self.assertIn("'mysql' => 'pdo_mysql'", BRIDGE)
        self.assertIn("'mariadb' => 'pdo_mysql'", BRIDGE)
        self.assertIn("DriverManager::getConnection($params)", BRIDGE)
        self.assertNotIn("DriverManager::getConnection(['url' => $databaseUrl])", BRIDGE)

    def test_percent_encoded_database_url_has_cli_web_parameter_parity(self) -> None:
        for source in (BRIDGE, DIAGNOSTIC):
            self.assertIn("new DsnParser([", source)
            self.assertIn("'mysql' => 'pdo_mysql'", source)
            self.assertIn("'mariadb' => 'pdo_mysql'", source)

        autoload = ROOT / "symfony/vendor/autoload.php"
        if not autoload.is_file():
            self.skipTest("Symfony vendor tree is not installed in this CI shard")

        php = textwrap.dedent(
            f"""\
            <?php
            require {json.dumps(str(autoload))};
            $url = 'mariadb://s4_user:p%40ss%3Aword@db.example.test:3306/grindflow?charset=utf8mb4';
            $params = (new \\Doctrine\\DBAL\\Tools\\DsnParser([
                'mysql' => 'pdo_mysql',
                'mariadb' => 'pdo_mysql',
            ]))->parse($url);
            echo json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            """
        )
        completed = subprocess.run(
            ["php"],
            input=php,
            text=True,
            capture_output=True,
            cwd=ROOT,
            timeout=15,
            check=False,
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        params = json.loads(completed.stdout)
        self.assertEqual("pdo_mysql", params["driver"])
        self.assertEqual("s4_user", params["user"])
        self.assertEqual("p@ss:word", params["password"])
        self.assertEqual("db.example.test", params["host"])
        self.assertEqual("grindflow", params["dbname"])
        self.assertEqual("utf8mb4", params["charset"])

    def test_readiness_query_remains_read_only_and_connection_is_closed(self) -> None:
        readiness = BRIDGE[BRIDGE.index("if ($path === '/s4/_bridge-readiness')"):]
        self.assertIn("SELECT COUNT(*)", readiness)
        self.assertIn("$connection->close();", readiness)
        self.assertIn("finally {", readiness)
        for forbidden in (
            "executeStatement(",
            "INSERT INTO",
            "UPDATE gf_",
            "DELETE FROM",
            "DROP TABLE",
            "TRUNCATE ",
            "CREATE TABLE",
            "ALTER TABLE",
        ):
            self.assertNotIn(forbidden, readiness)

    def test_parse_and_connection_failures_are_secret_free_and_fail_closed(self) -> None:
        self.assertIn("catch (Throwable) {\n        $databaseReady = false;", BRIDGE)
        self.assertIn("if (! $databaseReady || ! $schemaReady)", BRIDGE)
        self.assertIn("s4State('schema_missing', 503);", BRIDGE)
        for forbidden in (
            "getMessage()",
            "getTrace",
            "var_dump(",
            "print_r(",
            "echo $databaseUrl",
            "password",
        ):
            self.assertNotIn(forbidden, BRIDGE)

    def test_existing_bridge_contract_and_release_identity_are_preserved(self) -> None:
        for state in (
            "runtime_unavailable",
            "config_missing",
            "schema_missing",
            "identity_unavailable",
            "ready_for_web_probe",
        ):
            self.assertIn(f"'{state}'", BRIDGE)
        self.assertIn("S4_PREFLIGHT_CONTRACT = 's4-bridge-readiness-v1'", BRIDGE)
        self.assertIn("S4_SYNTHETIC_IDENTITY = 'e2e-oidc-smoke@grindflow.test'", BRIDGE)

        match = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION)
        self.assertIsNotNone(match)
        expected = match.group(1)
        self.assertGreaterEqual(tuple(map(int, expected.split("."))), (0, 1, 205))
        self.assertEqual(expected, PACKAGE["version"])
        self.assertEqual(expected, LOCK["version"])
        self.assertEqual(expected, LOCK["packages"][""]["version"])
        self.assertIn(f"V{expected}", README)

    def test_operational_closeout_requires_exact_deploy_bridge_and_smoke_evidence(self) -> None:
        self.assertIn("EXPECTED_SHA", PRODUCTION_SMOKE)
        self.assertIn("/s4/_bridge-readiness", PRODUCTION_SMOKE)
        self.assertIn("ready_for_web_probe", PRODUCTION_SMOKE)
        self.assertIn("S4_BRIDGE_STATE", PRODUCTION_SMOKE)
        self.assertIn("Production Smoke", SMOKE_WORKFLOW)
        self.assertNotIn("VALIDATED_IN_PRODUCTION", BRIDGE)

        start = PRODUCTION_SMOKE.index("check_s4_media_web_runtime_readiness()")
        end = PRODUCTION_SMOKE.index("# One anonymous GET", start)
        bridge_probe = PRODUCTION_SMOKE[start:end]
        self.assertEqual(3, bridge_probe.count("return 8"))
        self.assertIn(
            'check_s4_media_web_runtime_readiness || s4_bridge_status=$?',
            PRODUCTION_SMOKE,
        )
        self.assertIn(
            'if [[ "$s4_bridge_status" -ne 0 ]]; then',
            PRODUCTION_SMOKE,
        )
        self.assertIn(
            "8) printf 'ERROR: S4 bridge readiness is not HTTP 200 ready_for_web_probe",
            PRODUCTION_SMOKE,
        )


if __name__ == "__main__":
    unittest.main()
