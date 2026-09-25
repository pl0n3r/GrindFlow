import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/production-deploy-observer.yml").read_text(encoding="utf-8")


class ProductionDeployObserverContractTests(unittest.TestCase):
    def test_observer_uses_exact_health_identity(self):
        self.assertIn('/health?probe=', WORKFLOW)
        self.assertNotIn('/_deployment', WORKFLOW)
        self.assertIn('.status == "ok"', WORKFLOW)
        self.assertIn('.version == $version', WORKFLOW)
        self.assertIn('.exact == true', WORKFLOW)
        self.assertIn('.commit == $sha', WORKFLOW)

    def test_observer_is_read_only_bounded_and_pinned(self):
        self.assertIn("permissions:\n  contents: read", WORKFLOW)
        self.assertIn("timeout-minutes: 10", WORKFLOW)
        self.assertIn("seq 1 30", WORKFLOW)
        self.assertIn("actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1", WORKFLOW)
        for mutating in ("--request POST", "--request PUT", "--request PATCH", "--request DELETE"):
            self.assertNotIn(mutating, WORKFLOW)


if __name__ == "__main__":
    unittest.main()
