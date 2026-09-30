import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class WorkerSupplyChainHardeningTests(unittest.TestCase):
    def test_worker_installers_are_hardened(self) -> None:
        docker = (ROOT / "workers" / "Dockerfile").read_text()
        requirements = (ROOT / "workers" / "requirements.txt").read_text().splitlines()
        node_docker = (ROOT / "workers" / "node.Dockerfile").read_text()
        self.assertIn("--only-binary=:all:", docker)
        packages = [line for line in requirements if line and not line.startswith("#")]
        self.assertTrue(packages)
        self.assertTrue(all("==" in line for line in packages))
        self.assertIn("npm ci --ignore-scripts", node_docker)

    def test_node_worker_does_not_use_npx_at_runtime(self) -> None:
        node_docker = (ROOT / "workers" / "node.Dockerfile").read_text()
        compose = (ROOT / "docker-compose.yml").read_text()
        package = (ROOT / "package.json").read_text()
        smoke = (ROOT / "scripts" / "smoke-node-worker.sh").read_text()

        self.assertNotRegex(node_docker, r'CMD \["npx"')
        self.assertIn('"./node_modules/.bin/tsx"', node_docker)
        self.assertNotIn(" npx tsx ", compose)
        self.assertGreaterEqual(compose.count("./node_modules/.bin/tsx"), 2)
        self.assertIn("bash scripts/smoke-node-worker.sh", package)
        self.assertNotIn("--entrypoint", smoke)
        self.assertIn('"$image" >/dev/null', smoke)
        self.assertIn("docker network create --internal", smoke)
        self.assertIn("--network-alias supabase", smoke)

    def test_security_findings_do_not_regress_to_path_or_literal_bidi(self) -> None:
        rls = (ROOT / "scripts" / "run-rls-tests.mjs").read_text()
        normalize = (ROOT / "src" / "lib" / "captions" / "normalize.ts").read_text()

        self.assertIn("RLS_TEST_PSQL ?? '/usr/bin/psql'", rls)
        self.assertNotIn("execFileSync('psql'", rls)
        self.assertNotIn("\n    'psql',", rls)
        self.assertIn(r"\u200b-\u200f", normalize)
        self.assertFalse(any(0x202A <= ord(ch) <= 0x202E for ch in normalize))


if __name__ == "__main__":
    unittest.main()
