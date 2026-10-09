from __future__ import annotations

import copy
import importlib.util
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SCRIPT_PATH = ROOT / "scripts" / "operations-health.py"
WORKFLOW_PATH = ROOT / ".github" / "workflows" / "operations-health.yml"
RUNBOOK_PATH = ROOT / "docs" / "PRODUCTION-OPERATIONS.md"

SPEC = importlib.util.spec_from_file_location("operations_health", SCRIPT_PATH)
assert SPEC is not None and SPEC.loader is not None
OPERATIONS = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(OPERATIONS)


def signal(source: str, *, sha: str = "a" * 40, conclusion: str = "success") -> dict[str, object]:
    run_id = {
        "ci_health": 101,
        "deploy_observer": 102,
        "production_smoke": 103,
    }[source]
    return {
        "source": source,
        "status": "completed",
        "conclusion": conclusion,
        "head_sha": sha,
        "run_id": run_id,
        "url": f"https://github.com/pl0n3r/GrindFlow/actions/runs/{run_id}",
        "updated_at": "2026-10-06T18:00:00Z",
    }


def payload() -> dict[str, object]:
    return {
        "version": 1,
        "signals": [
            signal("production_smoke"),
            signal("ci_health"),
            signal("deploy_observer"),
        ],
    }


class OperationsHealthContractTests(unittest.TestCase):
    MAIN_SHA = "a" * 40

    def test_sources_have_severity_state_and_freshness(self) -> None:
        result = OPERATIONS.evaluate(payload(), main_sha=self.MAIN_SHA)
        self.assertEqual("HEALTHY", result["state"])
        self.assertEqual(
            ["ci_health", "deploy_observer", "production_smoke"],
            [row["source"] for row in result["signals"]],
        )
        self.assertEqual(
            {"S3", "S2"},
            {row["severity"] for row in result["signals"]},
        )
        for row in result["signals"]:
            self.assertEqual("HEALTHY", row["state"])
            self.assertEqual("CURRENT", row["freshness"])

        stale = payload()
        stale["signals"][0]["head_sha"] = "b" * 40
        stale_result = OPERATIONS.evaluate(stale, main_sha=self.MAIN_SHA)
        smoke = next(row for row in stale_result["signals"] if row["source"] == "production_smoke")
        self.assertEqual(("UNKNOWN", "STALE"), (smoke["state"], smoke["freshness"]))
        self.assertEqual("UNKNOWN", stale_result["state"])

        stale_failure = payload()
        stale_failure["signals"][0]["head_sha"] = "b" * 40
        stale_failure["signals"][0]["conclusion"] = "failure"
        stale_failure_result = OPERATIONS.evaluate(stale_failure, main_sha=self.MAIN_SHA)
        stale_smoke = next(
            row for row in stale_failure_result["signals"]
            if row["source"] == "production_smoke"
        )
        self.assertEqual(("UNKNOWN", "STALE"), (stale_smoke["state"], stale_smoke["freshness"]))
        self.assertEqual("head_sha_mismatch_terminal_non_success", stale_smoke["reason"])

        missing = payload()
        missing["signals"][1] = {
            "source": "ci_health",
            "status": "missing",
            "conclusion": None,
            "head_sha": None,
            "run_id": None,
            "url": None,
            "updated_at": None,
        }
        missing_result = OPERATIONS.evaluate(missing, main_sha=self.MAIN_SHA)
        ci = next(row for row in missing_result["signals"] if row["source"] == "ci_health")
        self.assertEqual(("UNKNOWN", "UNKNOWN"), (ci["state"], ci["freshness"]))

        invalid = payload()
        invalid["signals"][0]["unexpected"] = True
        with self.assertRaises(OPERATIONS.OperationsHealthError):
            OPERATIONS.evaluate(invalid, main_sha=self.MAIN_SHA)

    def test_alert_is_deduplicated_and_sanitized(self) -> None:
        first = OPERATIONS.evaluate(payload(), main_sha=self.MAIN_SHA)
        reordered = payload()
        reordered["signals"].reverse()
        second = OPERATIONS.evaluate(reordered, main_sha=self.MAIN_SHA)
        self.assertEqual(first["fingerprint"], second["fingerprint"])
        self.assertEqual(first["alert"], second["alert"])
        self.assertEqual("[AUTO] GrindFlow Operations Status", first["alert"]["title"])

        degraded = payload()
        degraded["signals"][2]["conclusion"] = "failure"
        result = OPERATIONS.evaluate(degraded, main_sha=self.MAIN_SHA)
        self.assertEqual("DEGRADED", result["state"])
        body = result["alert"]["body"]
        self.assertIn("deploy_observer", body)
        self.assertIn("S2", body)
        self.assertIn("CURRENT", body)
        self.assertIn("actions/runs/102", body)

        secret = "Bearer super-secret-value"
        tainted = payload()
        tainted["signals"][0]["response_body"] = secret
        with self.assertRaises(OPERATIONS.OperationsHealthError) as ctx:
            OPERATIONS.evaluate(tainted, main_sha=self.MAIN_SHA)
        self.assertNotIn(secret, str(ctx.exception))
        self.assertNotIn(secret, body)

        workflow = WORKFLOW_PATH.read_text(encoding="utf-8")
        self.assertIn('state=all&per_page=100', workflow)
        self.assertIn("--paginate", workflow)
        self.assertNotIn('--search "$ALERT_TITLE in:title"', workflow)
        self.assertIn("count <= 1", workflow)
        self.assertIn("gh issue reopen", workflow)
        self.assertNotIn("gh issue comment", workflow)

    def test_runbook_covers_health_publication_storage_incidents_and_recovery(self) -> None:
        text = RUNBOOK_PATH.read_text(encoding="utf-8")
        for heading in (
            "## Health, deploy y Production Smoke",
            "## Publicación y Distribution",
            "## Storage, Media y Vault",
            "## Recovery",
            "## Handoff de incidente",
            "## Mantenimiento programado",
        ):
            self.assertIn(heading, text)
        for source in (
            ".github/workflows/production-deploy-observer.yml",
            ".github/workflows/production-smoke.yml",
            "docs/AGENT-DISTRIBUTION.md",
            "docs/AGENT-VAULT-UPLOADS.md",
            "docs/AGENT-MEDIA-CONNECTIONS.md",
            "docs/PRODUCTION-RECOVERY.md",
            "Issue #311",
        ):
            self.assertIn(source, text)
        self.assertIn("única fuente operativa", text)

    def test_maintenance_is_manual_reversible_and_non_destructive(self) -> None:
        text = RUNBOOK_PATH.read_text(encoding="utf-8")
        self.assertIn("manual, reversible y explícito", text)
        for forbidden in (
            "migraciones o SQL destructivo",
            "restore productivo",
            "SSH",
            "chmod o chown",
            "rotación/cambio de secretos",
            "bulk writes",
            "compra/gasto",
            "go-live",
        ):
            self.assertIn(forbidden, text)
        self.assertIn("no sustituye una autorización operacional específica", text)

    def test_workflow_is_read_only_against_production(self) -> None:
        workflow = WORKFLOW_PATH.read_text(encoding="utf-8")
        self.assertIn("actions: read", workflow)
        self.assertIn("contents: read", workflow)
        self.assertIn("issues: write", workflow)
        self.assertIn("observe-read-only", workflow)
        self.assertIn("reconcile-single-alert", workflow)
        observe = workflow.split("reconcile-alert:", 1)[0]
        self.assertNotIn("issues: write", observe)
        self.assertIn("Validate event and exact main before checkout", workflow)
        self.assertIn("schedule|workflow_dispatch", workflow)
        self.assertIn("scripts/operations-health.py evaluate", workflow)
        self.assertIn("gh api", workflow)
        self.assertIn("Unable to query workflow", workflow)
        self.assertIn("GH_REPO:", workflow)
        self.assertIn("REPOSITORY:", workflow)
        self.assertIn("gh issue edit", workflow)
        self.assertIn("gh issue close", workflow)
        self.assertIn("RUNNER_TEMP", workflow)
        self.assertIn("umask 077", workflow)
        self.assertNotIn("/tmp/grindflow-", workflow)

        script = SCRIPT_PATH.read_text(encoding="utf-8")
        self.assertIn("sys.stdin.read()", script)
        self.assertNotIn("--input", script)
        self.assertNotIn("--output", script)

        for forbidden in (
            "curl ",
            "ssh ",
            "artisan migrate",
            "migrate --force",
            "chmod ",
            "chown ",
            "mysql ",
            "mysqldump ",
        ):
            self.assertNotIn(forbidden, workflow)

    def test_operational_handoff_uses_allowlisted_safe_evidence(self) -> None:
        result = OPERATIONS.evaluate(payload(), main_sha=self.MAIN_SHA)
        body = result["alert"]["body"]
        for expected in (
            "Source",
            "Severity",
            "State",
            "Freshness",
            self.MAIN_SHA,
            "actions/runs/101",
            "actions/runs/102",
            "actions/runs/103",
            "docs/PRODUCTION-OPERATIONS.md",
        ):
            self.assertIn(expected, body)

        lowered = body.lower()
        for forbidden in (
            "authorization:",
            "bearer ",
            "cookie=",
            "/home/",
            ".env=",
            "request body:",
            "response body:",
        ):
            self.assertNotIn(forbidden, lowered)


if __name__ == "__main__":
    unittest.main()
