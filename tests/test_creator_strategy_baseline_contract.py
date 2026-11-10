#!/usr/bin/env python3
"""Aceptación ejecutable del primer leaf de Strategy Engine (#1016)."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "symfony/src/Strategy/CreatorStrategyBaselineService.php"
TEST = ROOT / "symfony/tests/php/CreatorStrategyBaselineServiceTest.php"


def run_php(code: str) -> dict:
    completed = subprocess.run(
        ["php", "-r", f"require {json.dumps(str(SERVICE))}; {code}"],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=True,
    )
    return json.loads(completed.stdout)


class CreatorStrategyBaselineContractTests(unittest.TestCase):
    def test_tenant_isolation_and_pure_input(self) -> None:
        result = run_php(r'''
            $service = new \GrindFlow\Strategy\CreatorStrategyBaselineService();
            $owned = ['id'=>'1','tenant_id'=>'a','network'=>'instagram','format'=>'short_video','impressions'=>100,'interactions'=>5,'private_note'=>'SECRET'];
            $foreign = ['id'=>'2','tenant_id'=>'b','network'=>'x','format'=>'image','impressions'=>900,'interactions'=>250,'private_note'=>'FOREIGN'];
            $rows = [$owned, $foreign];
            $before = serialize($rows);
            $result = $service->summarize('a', $rows);
            echo json_encode(['baseline'=>$result,'same'=>$before===serialize($rows)]);
        ''')
        self.assertTrue(result["same"])
        self.assertEqual(1, result["baseline"]["sample_count"])
        self.assertEqual("instagram", result["baseline"]["baseline"][0]["network"])
        self.assertNotIn("FOREIGN", json.dumps(result))
        self.assertNotIn("SECRET", json.dumps(result))
        self.assertIn("testTenantIsolationAndPureInput", TEST.read_text())

    def test_grouping_by_network_and_cold_start(self) -> None:
        result = run_php(r'''
            $service = new \GrindFlow\Strategy\CreatorStrategyBaselineService();
            $post = ['id'=>'1','tenant_id'=>'a','network'=>'x','format'=>'short_video','impressions'=>100,'interactions'=>10];
            $dup = $post;
            $other = ['id'=>'2','tenant_id'=>'a','network'=>'instagram','format'=>'image','impressions'=>200,'interactions'=>4];
            $normal = $service->summarize('a', [$post,$dup,$other]);
            $empty = $service->summarize('a', []);
            echo json_encode(['normal'=>$normal,'empty'=>$empty]);
        ''')
        self.assertEqual(2, result["normal"]["sample_count"])
        self.assertEqual(["instagram", "x"], [x["network"] for x in result["normal"]["baseline"]])
        self.assertTrue(result["empty"]["cold_start"])
        self.assertEqual("no_data", result["empty"]["confidence"])
        self.assertIn("testDeterministicGroupingAndDuplicateConflicts", TEST.read_text())

    def test_confidence_evidence_and_non_authorization(self) -> None:
        result = run_php(r'''
            $service = new \GrindFlow\Strategy\CreatorStrategyBaselineService();
            $rows = [];
            for ($i=0; $i<30; ++$i) {
                $rows[] = ['id'=>(string)$i,'tenant_id'=>'a','network'=>'youtube','format'=>'video','impressions'=>100,'interactions'=>5];
            }
            $nine = $service->summarize('a', array_slice($rows,0,9));
            $ten = $service->summarize('a', array_slice($rows,0,10));
            $thirty = $service->summarize('a', $rows);
            echo json_encode([$nine,$ten,$thirty]);
        ''')
        self.assertEqual(["limited", "tentative", "developing"], [x["confidence"] for x in result])
        for item in result:
            self.assertFalse(item["publication_authorized"])
            self.assertEqual("creator_own_records", item["evidence_source"])
            self.assertIn("orientativos", item["confidence_explanation"])
        self.assertIn("testColdStartConfidenceAndNoPublication", TEST.read_text())


if __name__ == "__main__":
    unittest.main()
