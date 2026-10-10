"""Executable, dependency-free contract bridge for pure Symfony horizon rules."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCES = [
    ROOT / "symfony/src/Scheduling/Horizon/GapDetector.php",
    ROOT / "symfony/src/Scheduling/Horizon/HorizonReplenisher.php",
]


def execute(method: str, data: dict) -> dict:
    requires = "\n".join(f"require {json.dumps(str(path))};" for path in SOURCES)
    program = (
        requires
        + "\n$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);"
        + "\n$common=[$input['tenant'], $input['timezone'], $input['date'], "
        + "$input['days'], $input['capacity'], $input['scheduled'] ?? []];"
        + ("\n$output=(new \\GrindFlow\\Scheduling\\Horizon\\GapDetector())->detect("
           "...$common, ...[$input['blocked_dates'] ?? [], $input['blocked_slots'] ?? [], $input['permitted'] ?? true]);"
           if method == "detect" else
           "\n$output=(new \\GrindFlow\\Scheduling\\Horizon\\HorizonReplenisher())->plan("
           "...$common, ...[$input['assets'] ?? [], $input['mode'] ?? 'manual', "
           "$input['blocked_dates'] ?? [], $input['blocked_slots'] ?? [], $input['permitted'] ?? true]);")
        + "\necho json_encode($output,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);"
    )
    completed = subprocess.run(
        ["php", "-r", program], input=json.dumps(data),
        text=True, capture_output=True, check=True, cwd=ROOT,
    )
    return json.loads(completed.stdout)


def fixture(**changes: object) -> dict:
    data = {
        "tenant": "studio-a", "timezone": "America/Bogota", "date": "2026-10-12",
        "days": 3, "capacity": 1, "scheduled": [], "assets": [], "mode": "assisted",
    }
    return data | changes


class SchedulingHorizonContractTests(unittest.TestCase):
    def test_detects_gaps_within_horizon(self) -> None:
        result = execute("detect", fixture(
            scheduled=[
                {"tenant_id": "studio-a", "date": "2026-10-12", "slot": 1},
                {"tenant_id": "studio-b", "date": "2026-10-14", "slot": 1},
            ],
            blocked_dates=["2026-10-13"],
        ))
        self.assertEqual(result["status"], "ok")
        self.assertEqual(result["required"], 2)
        self.assertEqual(result["scheduled"], 1)
        self.assertEqual(result["gaps"], [{"date": "2026-10-14", "slot": 1}])
        # IANA calendar arithmetic must survive a shortened DST day.
        dst = execute("detect", fixture(timezone="America/New_York", date="2026-03-07", days=4))
        self.assertEqual([s["date"] for s in dst["gaps"]],
                         ["2026-03-07", "2026-03-08", "2026-03-09", "2026-03-10"])
        self.assertEqual(execute("detect", fixture(permitted=False))["status"], "rejected")
        self.assertEqual(execute("detect", fixture(scheduled=[
            {"tenant_id": "studio-a", "date": "2026-10-12", "slot": 1, "status": "ambiguous"}
        ]))["reason"], "uncertain_schedule")

    def test_counts_missing_slots_for_standard_and_custom_horizons(self) -> None:
        for horizon in (3, 7, 14, 30, 11):
            with self.subTest(days=horizon):
                data = fixture(days=horizon, scheduled=[
                    {"tenant_id": "studio-a", "date": "2026-10-12", "slot": 1},
                    {"tenant_id": "studio-a", "date": "2026-10-12", "slot": 1},
                ])
                result = execute("detect", data)
                self.assertEqual(result["required"], horizon)
                self.assertEqual(result["scheduled"], 1)
                self.assertEqual(len(result["gaps"]), horizon - 1)
        self.assertEqual(execute("detect", fixture(days=366))["status"], "rejected")
        self.assertEqual(execute("detect", fixture(date="2026-02-30"))["status"], "rejected")

    def test_manual_assisted_and_pilot_modes_do_not_expand_authority(self) -> None:
        assets = [
            {"id": "asset-a", "tenant_id": "studio-a", "eligible": True},
            {"id": "asset-b", "tenant_id": "studio-a", "eligible": True},
            {"id": "foreign", "tenant_id": "studio-b", "eligible": True},
        ]
        base = fixture(days=2, assets=assets)
        manual = execute("plan", base | {"mode": "manual"})
        assisted = execute("plan", base | {"mode": "assisted"})
        pilot = execute("plan", base | {"mode": "pilot"})
        self.assertEqual(manual["action"], "notify")
        self.assertEqual(manual["proposals"], [])
        self.assertEqual(assisted["action"], "suggest")
        self.assertEqual(pilot["action"], "pilot_preview")
        for result in (assisted, pilot):
            self.assertEqual([p["asset_id"] for p in result["proposals"]], ["asset-a", "asset-b"])
            self.assertEqual(result["unfilled_count"], 0)
        for path in SOURCES:
            source = path.read_text(encoding="utf-8").lower()
            for forbidden in ("curl_", "file_put_contents", "pdo(", "fetch(", "http://", "https://"):
                self.assertNotIn(forbidden, source)

    def test_no_eligible_content_never_fabricates_fill(self) -> None:
        for assets in ([], [{"id": "x", "tenant_id": "studio-b", "eligible": True}],
                       [{"id": "x", "tenant_id": "studio-a", "eligible": False}]):
            with self.subTest(assets=assets):
                result = execute("plan", fixture(days=2, assets=assets))
                self.assertEqual(result["status"], "rejected")
                self.assertEqual(result["reason"], "no_eligible_content")
                self.assertEqual(result["proposals"], [])
        only = [{"id": "asset-1", "tenant_id": "studio-a", "eligible": True}] * 2
        result = execute("plan", fixture(days=3, assets=only))
        self.assertEqual(len(result["proposals"]), 1)
        self.assertEqual(result["unfilled_count"], 2)
        self.assertEqual(execute("plan", fixture(permitted=False, assets=only))["status"], "rejected")
        self.assertEqual(execute("plan", fixture(mode="unsafe", assets=only))["status"], "rejected")


if __name__ == "__main__":
    unittest.main()
