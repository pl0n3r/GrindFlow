import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class WorkerSupplyChainHardeningTests(unittest.TestCase):
    def test_worker_base_registry_uses_verified_docker_official_ecr_mirror(self) -> None:
        """El registry alternativo es Docker Official en ECR Public, sin cambiar variantes ni installer."""
        python = (ROOT / "workers" / "Dockerfile").read_text(encoding="utf-8")
        node = (ROOT / "workers" / "node.Dockerfile").read_text(encoding="utf-8")
        bases = lambda content: [line.strip() for line in content.splitlines()
                                 if line.strip().startswith("FROM ")]
        self.assertEqual(bases(python), ["FROM public.ecr.aws/docker/library/python:3.11-slim"])
        self.assertEqual(bases(node), ["FROM public.ecr.aws/docker/library/node:22-alpine"])
        self.assertIn("pip install --no-cache-dir --require-hashes --only-binary=:all:", python)
        self.assertIn("npm ci --ignore-scripts", node)
        self.assertIn("USER worker", python)
        self.assertIn("USER node", node)
        self.assertNotIn("docker.io/", python + node)

    def test_worker_installers_are_hardened(self) -> None:
        docker = (ROOT / "workers" / "Dockerfile").read_text()
        requirements = (ROOT / "workers" / "requirements.txt").read_text().splitlines()
        node_docker = (ROOT / "workers" / "node.Dockerfile").read_text()
        self.assertIn("--require-hashes --only-binary=:all:", docker)
        self.assertIn("-r requirements.lock", docker)
        packages = [line for line in requirements if line and not line.startswith("#")]
        self.assertTrue(packages)
        self.assertTrue(all("==" in line for line in packages))
        self.assertIn("npm ci --ignore-scripts", node_docker)

    def test_python_worker_installs_hashed_transitive_lock(self) -> None:
        docker = (ROOT / "workers" / "Dockerfile").read_text()
        self.assertIn("COPY workers/requirements.lock ./requirements.lock", docker)
        self.assertIn(
            "pip install --no-cache-dir --require-hashes --only-binary=:all: -r requirements.lock",
            docker,
        )
        self.assertNotIn("-r requirements.txt", docker)

    def test_python_worker_lock_is_fully_pinned_and_hashed(self) -> None:
        lock = (ROOT / "workers" / "requirements.lock").read_text()
        logical = []
        current = ""
        for raw in lock.splitlines():
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            current += (" " if current else "") + line.rstrip("\\").strip()
            if not line.endswith("\\"):
                logical.append(current)
                current = ""
        self.assertFalse(current)
        self.assertGreaterEqual(len(logical), 10)
        for requirement in logical:
            self.assertRegex(requirement, r"^[A-Za-z0-9_.-]+(?:\[binary\])?==[^ ]+ ")
            self.assertRegex(requirement, r"--hash=sha256:[0-9a-f]{64}(?: |$)")
        names = {re.split(r"\[|==", requirement, maxsplit=1)[0].lower() for requirement in logical}
        self.assertTrue(
            {"boto3", "botocore", "s3transfer", "jmespath", "python-dateutil",
             "urllib3", "six", "psycopg", "psycopg-binary", "typing-extensions",
             "pillow"}.issubset(names)
        )

    def test_python_worker_install_contract_fails_closed(self) -> None:
        docker = (ROOT / "workers" / "Dockerfile").read_text()
        lock = (ROOT / "workers" / "requirements.lock").read_text()
        self.assertNotIn("requirements.txt ./requirements.txt", docker)
        self.assertNotIn("pip install -r", docker)
        self.assertNotRegex(lock, r"(?m)^[A-Za-z0-9_.-]+(?:\[binary\])?(?:>=|~=|>|<)")
        self.assertNotIn("--index-url", lock)
        self.assertNotIn("--extra-index-url", lock)

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
        worker_run = smoke.rsplit("docker run -d \\\n", 1)[-1].split("for _ in", 1)[0]
        self.assertNotIn("--entrypoint", worker_run)
        self.assertIn('"$image" >/dev/null', worker_run)
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
