"""Fail-closed contracts for source-only writer ownership proposals."""
from __future__ import annotations

import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
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

    def test_identity_uses_actual_repository_inventory(self):
        report = CUTOVER.build_report(self.source(), self.plan())
        self.assertEqual("identity", report["module"])
        self.assertFalse(report["database_contacted"])
        self.assertFalse(report["cutover_authorized"])
        self.assertEqual(list(CUTOVER.PRECONDITIONS), report["pending_preconditions"])
        self.assertEqual("laravel", report["current_writer"])

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
        with self.assertRaisesRegex(ValueError, "source migrations"):
            CUTOVER.build_report(source, self.plan())

    def test_incomplete_module_ownership_fails(self):
        plan = self.plan()
        plan["laravel_tables"].pop()
        with self.assertRaisesRegex(ValueError, "coverage"):
            CUTOVER.build_report(self.source(), plan)

    def test_cross_module_table_fails(self):
        plan = self.plan()
        plan["symfony_tables"][0] = "gf_vault_assets"
        with self.assertRaisesRegex(ValueError, "coverage"):
            CUTOVER.build_report(self.source(), plan)

    def test_duplicate_planned_table_fails(self):
        plan = self.plan()
        plan["laravel_tables"].append(plan["laravel_tables"][0])
        with self.assertRaisesRegex(ValueError, "duplicate"):
            CUTOVER.build_report(self.source(), plan)

    def test_dirty_source_or_unprefixed_table_fails(self):
        source = self.source()
        source["checks"]["table_name_collisions"] = ["users"]
        with self.assertRaisesRegex(ValueError, "not clean"):
            CUTOVER.build_report(source, self.plan())
        source = self.source()
        source["symfony"][0]["table"] = "bad_table"
        with self.assertRaisesRegex(ValueError, "gf_ prefix"):
            CUTOVER.build_report(source, self.plan())

    def test_wrong_writer_and_missing_rollback_fail(self):
        plan = self.plan()
        plan["current_writer"] = "symfony"
        with self.assertRaisesRegex(ValueError, "writer transition"):
            CUTOVER.build_report(self.source(), plan)
        plan = self.plan()
        plan["rollback_writer"] = "symfony"
        with self.assertRaisesRegex(ValueError, "rollback"):
            CUTOVER.build_report(self.source(), plan)

    def test_production_authorization_cannot_be_embedded(self):
        plan = self.plan()
        plan["production_authorized"] = True
        with self.assertRaisesRegex(ValueError, "non-operational"):
            CUTOVER.build_report(self.source(), plan)

    def test_all_readiness_is_pending_even_if_user_claims_green(self):
        plan = self.plan()
        plan["readiness"]["restored_database_and_blobs"] = "passed"
        with self.assertRaisesRegex(ValueError, "cannot certify"):
            CUTOVER.build_report(self.source(), plan)

    def test_extra_fields_and_missing_preconditions_fail(self):
        plan = self.plan()
        plan["production_database_url"] = "private"
        with self.assertRaisesRegex(ValueError, "exactly"):
            CUTOVER.build_report(self.source(), plan)
        plan = self.plan()
        plan["readiness"].pop("owner_authorization")
        with self.assertRaisesRegex(ValueError, "preconditions"):
            CUTOVER.build_report(self.source(), plan)

    def test_unsupported_module_fails_closed(self):
        plan = self.plan()
        plan["module"] = "finance"
        with self.assertRaisesRegex(ValueError, "unsupported module"):
            CUTOVER.build_report(self.source(), plan)

    def test_source_duplicate_or_fake_provenance_fails(self):
        source = self.source()
        source["laravel"].append(copy.deepcopy(source["laravel"][0]))
        with self.assertRaisesRegex(ValueError, "duplicate source"):
            CUTOVER.build_report(source, self.plan())
        source = self.source()
        source["database_contacted"] = True
        with self.assertRaisesRegex(ValueError, "provenance"):
            CUTOVER.build_report(source, self.plan())

    def test_cli_is_offline_and_does_not_echo_untrusted_input(self):
        payload = json.dumps({"source": self.source(), "plan": self.plan()})
        env = dict(os.environ, DATABASE_URL="secret-injected-credential")
        cmd = [sys.executable, str(ROOT / "scripts/cutover-ownership-plan.py"), "--json"]
        ok = subprocess.run(cmd, input=payload, text=True, capture_output=True, env=env, check=False)
        self.assertEqual(0, ok.returncode, ok.stderr)
        report = json.loads(ok.stdout)
        self.assertFalse(report["database_contacted"])
        self.assertNotIn("secret-injected-credential", ok.stdout)
        bad = subprocess.run(
            cmd, input=payload + "secret-injected-credential", text=True,
            capture_output=True, env=env, check=False,
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


if __name__ == "__main__":
    unittest.main()
