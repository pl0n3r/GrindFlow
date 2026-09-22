"""Fail-closed contracts for source-only writer ownership proposals."""
from __future__ import annotations

import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


def load(path: str, name: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load source-only contract")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CUTOVER = load("scripts/cutover-ownership-plan.py", "cutover_ownership")
INVENTORY = load("scripts/data-schema-inventory.py", "schema_inventory")


class CutoverOwnershipPlanTest(unittest.TestCase):
    """The report is a proposal, never an authorization or a database operation."""

    def source(self):
        return INVENTORY.build_inventory()

    def plan(self, module="identity"):
        grouping = CUTOVER.MODULE_TABLES[module]
        return {
            "contract": CUTOVER.PLAN_CONTRACT,
            "module": module,
            "mode": "planning_only",
            "source_only": True,
            "database_contacted": False,
            "production_authorized": False,
            "current_writer": "laravel",
            "proposed_writer": "symfony",
            "rollback_writer": "laravel",
            "laravel_tables": list(grouping["laravel"]),
            "symfony_tables": list(grouping["symfony"]),
            "single_writer_required": True,
            "readiness": {key: "pending" for key in CUTOVER.PRECONDITIONS},
        }

    def assert_rejected(self, pattern, source=None, plan=None):
        source_value = self.source() if source is None else source
        plan_value = self.plan() if plan is None else plan
        with self.assertRaisesRegex(ValueError, pattern):
            CUTOVER.build_report(source_value, plan_value)

    def test_identity_uses_actual_repository_inventory(self):
        report = CUTOVER.build_report(self.source(), self.plan())
        self.assertEqual("identity", report["module"])
        self.assertFalse(report["database_contacted"])
        self.assertFalse(report["cutover_authorized"])
        self.assertEqual(list(CUTOVER.PRECONDITIONS), report["pending_preconditions"])
        self.assertEqual("laravel", report["current_writer"])
        self.assertEqual(64, len(report["source_inventory_sha256"]))
        self.assertEqual(
            set(row["table"] for row in self.source()["laravel"])
            - set(CUTOVER.MODULE_TABLES["identity"]["laravel"]),
            set(report["outside_this_proposal"]["laravel"]),
        )

    def test_report_fingerprint_is_stable_across_key_order(self):
        source = self.source()
        first = CUTOVER.build_report(source, self.plan())
        reordered = dict(reversed(list(source.items())))
        second = CUTOVER.build_report(reordered, self.plan())
        self.assertEqual(
            first["source_inventory_sha256"], second["source_inventory_sha256"],
        )
        self.assertRegex(first["source_inventory_sha256"], r"^[0-9a-f]{64}$")

    def test_vault_uses_actual_repository_inventory(self):
        report = CUTOVER.build_report(self.source(), self.plan("vault"))
        self.assertEqual(
            ["gf_vault_assets"],
            report["table_ownership"]["symfony"],
        )
        self.assertEqual("laravel", report["rollback_writer"])

    def test_order_of_table_lists_is_not_significant(self):
        plan = self.plan()
        plan["laravel_tables"].reverse()
        plan["symfony_tables"].reverse()
        self.assertEqual("identity", CUTOVER.build_report(self.source(), plan)["module"])

    def test_missing_source_table_is_a_failure(self):
        source = self.source()
        source["symfony"] = [
            row for row in source["symfony"] if row["table"] != "gf_identity_users"
        ]
        self.assert_rejected("checked-in migrations", source=source)

    def test_forged_source_migration_provenance_fails(self):
        source = self.source()
        source["laravel"][0]["migration"] = "invented.php"
        self.assert_rejected("checked-in migrations", source=source)

    def test_module_catalog_rejects_overlapping_table_ownership(self):
        original = copy.deepcopy(CUTOVER.MODULE_TABLES)
        source = self.source()
        plan = self.plan()
        try:
            CUTOVER.MODULE_TABLES["vault"]["laravel"] = (
                "media_assets",
                "users",
            )
            self.assert_rejected("multiple modules", source=source, plan=plan)
        finally:
            CUTOVER.MODULE_TABLES.clear()
            CUTOVER.MODULE_TABLES.update(original)

    def test_module_catalog_rejects_stale_table_mapping(self):
        original = copy.deepcopy(CUTOVER.MODULE_TABLES)
        source = self.source()
        plan = self.plan()
        try:
            CUTOVER.MODULE_TABLES["vault"]["symfony"] = ("gf_missing_table",)
            self.assert_rejected("missing from migrations", source=source, plan=plan)
        finally:
            CUTOVER.MODULE_TABLES.clear()
            CUTOVER.MODULE_TABLES.update(original)

    def test_incomplete_module_ownership_fails(self):
        plan = self.plan()
        plan["laravel_tables"].pop()
        self.assert_rejected("coverage", plan=plan)

    def test_cross_module_table_fails(self):
        plan = self.plan()
        plan["symfony_tables"][0] = "gf_vault_assets"
        self.assert_rejected("coverage", plan=plan)

    def test_duplicate_planned_table_fails(self):
        plan = self.plan()
        plan["laravel_tables"].append(plan["laravel_tables"][0])
        self.assert_rejected("duplicate", plan=plan)

    def test_dirty_source_or_unprefixed_table_fails(self):
        dirty = self.source()
        dirty["checks"]["table_name_collisions"] = ["users"]
        self.assert_rejected("not clean", source=dirty)

        unprefixed = self.source()
        unprefixed["symfony"][0]["table"] = "bad_table"
        self.assert_rejected("gf_ prefix", source=unprefixed)

    def test_wrong_writer_and_missing_rollback_fail(self):
        wrong_writer = self.plan()
        wrong_writer["current_writer"] = "symfony"
        self.assert_rejected("writer transition", plan=wrong_writer)

        wrong_rollback = self.plan()
        wrong_rollback["rollback_writer"] = "symfony"
        self.assert_rejected("rollback", plan=wrong_rollback)

    def test_production_authorization_cannot_be_embedded(self):
        plan = self.plan()
        plan["production_authorized"] = True
        self.assert_rejected("non-operational", plan=plan)

    def test_boolean_contract_rejects_integer_aliases(self):
        source_only = self.plan()
        source_only["source_only"] = 1
        self.assert_rejected("non-operational", plan=source_only)

        production_authorized = self.plan()
        production_authorized["production_authorized"] = 0
        self.assert_rejected("non-operational", plan=production_authorized)

    def test_all_readiness_is_pending_even_if_user_claims_green(self):
        plan = self.plan()
        plan["readiness"]["restored_database_and_blobs"] = "passed"
        self.assert_rejected("cannot certify", plan=plan)

    def test_extra_fields_and_missing_preconditions_fail(self):
        extra = self.plan()
        extra["production_database_url"] = "private"
        self.assert_rejected("exactly", plan=extra)

        missing = self.plan()
        missing["readiness"].pop("owner_authorization")
        self.assert_rejected("preconditions", plan=missing)

    def test_unsupported_module_fails_closed(self):
        plan = self.plan()
        plan["module"] = "finance"
        self.assert_rejected("unsupported module", plan=plan)

    def test_source_duplicate_or_fake_provenance_fails(self):
        duplicate = self.source()
        duplicate["laravel"].append(copy.deepcopy(duplicate["laravel"][0]))
        self.assert_rejected("duplicate source", source=duplicate)

        fake = self.source()
        fake["database_contacted"] = True
        self.assert_rejected("provenance", source=fake)

    def test_cli_is_offline_under_audit_barrier(self):
        payload = json.dumps({"source": self.source(), "plan": self.plan()})
        cmd = [sys.executable, str(ROOT / "scripts/cutover-ownership-plan.py"), "--json"]
        with tempfile.TemporaryDirectory() as directory:
            guard = Path(directory) / "sitecustomize.py"
            guard.write_text(
                "import sys\n"
                "def _block_external(event, args):\n"
                "    blocked = {\n"
                "        'socket.connect', 'subprocess.Popen', 'os.system',\n"
                "        'os.posix_spawn', 'os.posix_spawnp',\n"
                "    }\n"
                "    if event in blocked or event.startswith('os.spawn'):\n"
                "        raise RuntimeError('external contact blocked by audit hook')\n"
                "sys.addaudithook(_block_external)\n",
                encoding="utf-8",
            )
            env = dict(os.environ)
            env["PYTHONPATH"] = (
                str(directory)
                + (os.pathsep + env["PYTHONPATH"] if env.get("PYTHONPATH") else "")
            )
            result = subprocess.run(
                cmd,
                input=payload,
                text=True,
                encoding="utf-8",
                capture_output=True,
                env=env,
                check=False,
            )
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse(json.loads(result.stdout)["database_contacted"])

    def test_cli_does_not_echo_untrusted_input_or_environment(self):
        payload = json.dumps({"source": self.source(), "plan": self.plan()})
        env = dict(os.environ, DATABASE_URL="secret-injected-credential")
        cmd = [sys.executable, str(ROOT / "scripts/cutover-ownership-plan.py"), "--json"]
        ok = subprocess.run(
            cmd,
            input=payload,
            text=True,
            encoding="utf-8",
            capture_output=True,
            env=env,
            check=False,
        )
        self.assertEqual(0, ok.returncode, ok.stderr)
        self.assertNotIn("secret-injected-credential", ok.stdout)
        bad = subprocess.run(
            cmd,
            input=payload + "secret-injected-credential",
            text=True,
            encoding="utf-8",
            capture_output=True,
            env=env,
            check=False,
        )
        self.assertEqual(2, bad.returncode)
        self.assertEqual("", bad.stdout)
        self.assertNotIn("secret-injected-credential", bad.stderr)

    def test_source_inventory_json_is_one_document(self):
        cmd = [sys.executable, str(ROOT / "scripts/data-schema-inventory.py"), "--json"]
        r = subprocess.run(cmd, text=True, capture_output=True, check=False)
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertEqual("", r.stderr)
        self.assertEqual(self.source(), json.loads(r.stdout))
        self.assertNotIn("source schema guard: OK", r.stdout)

    def test_template_round_trip_stays_pending_and_offline(self):
        cmd = [sys.executable, str(ROOT / "scripts/cutover-ownership-plan.py")]
        for module in ("identity", "vault"):
            generated = subprocess.run(
                [*cmd, "--template", module],
                text=True, capture_output=True, check=False,
            )
            self.assertEqual(0, generated.returncode, generated.stderr)
            draft = json.loads(generated.stdout)
            self.assertFalse(draft["plan"]["production_authorized"])
            self.assertFalse(draft["source"]["database_contacted"])
            checked = subprocess.run(
                [*cmd, "--json"],
                input=generated.stdout, text=True, capture_output=True, check=False,
            )
            self.assertEqual(0, checked.returncode, checked.stderr)
            self.assertFalse(json.loads(checked.stdout)["cutover_authorized"])

    def test_large_or_malformed_envelope_is_rejected(self):
        cmd = [sys.executable, str(ROOT / "scripts/cutover-ownership-plan.py")]
        for payload in ("x" * (CUTOVER.MAX_STDIN_BYTES + 1), '{"source":[]}', "[]"):
            r = subprocess.run(cmd, input=payload, text=True, capture_output=True, check=False)
            self.assertEqual(2, r.returncode)
            self.assertEqual("", r.stdout)

    def test_input_limit_is_measured_in_bytes(self):
        cmd = [sys.executable, str(ROOT / "scripts/cutover-ownership-plan.py")]
        payload = "á" * ((CUTOVER.MAX_STDIN_BYTES // 2) + 1)
        r = subprocess.run(
            cmd,
            input=payload,
            text=True,
            encoding="utf-8",
            capture_output=True,
            check=False,
        )
        self.assertEqual(2, r.returncode)
        self.assertEqual("", r.stdout)
        self.assertIn("validation failed", r.stderr)

    def test_cli_rejects_utf16_and_utf32_json(self):
        cmd = [sys.executable, str(ROOT / "scripts/cutover-ownership-plan.py"), "--json"]
        payload = json.dumps({"source": self.source(), "plan": self.plan()})
        for encoding in ("utf-16", "utf-32"):
            result = subprocess.run(
                cmd,
                input=payload.encode(encoding),
                capture_output=True,
                check=False,
            )
            self.assertEqual(2, result.returncode, encoding)
            self.assertEqual(b"", result.stdout, encoding)
            self.assertIn(b"validation failed", result.stderr, encoding)


if __name__ == "__main__":
    unittest.main()
