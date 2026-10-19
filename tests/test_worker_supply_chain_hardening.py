import hashlib
import json
import re
import subprocess
import sys
import unittest
import urllib.parse
import urllib.request
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]

# Production defaults remain the Docker Official ECR Public bases.
# Mirror usage is restricted to two canonical Docker Official library images.
_OFFICIAL_BASES = (
    ("python", "3.11-slim", "PYTHON_BASE_IMAGE", "workers/Dockerfile", "grindflow-worker-python-ci"),
    ("node", "22-alpine", "NODE_BASE_IMAGE", "workers/node.Dockerfile", "grindflow-worker-node-ci"),
)
_MANIFEST_MIMES = (
    "application/vnd.oci.image.index.v1+json",
    "application/vnd.docker.distribution.manifest.list.v2+json",
    "application/vnd.oci.image.manifest.v1+json",
    "application/vnd.docker.distribution.manifest.v2+json",
)
_SHA256 = re.compile(r"[0-9a-f]{64}\Z")


class RegistryVerificationError(RuntimeError):
    """Missing or contradictory provenance must stop the worker CI build."""


def _docker_hub_token(image: str) -> str:
    """Read-only token for Docker Official's canonical library namespace."""
    query = urllib.parse.urlencode({
        "service": "registry.docker.io", "scope": f"repository:library/{image}:pull",
    })
    with urllib.request.urlopen("https://auth.docker.io/token?" + query, timeout=20) as response:
        token = json.load(response).get("token")
    if not isinstance(token, str) or not token:
        raise RegistryVerificationError("No official-registry bearer token")
    return token


def _manifest_digest(url: str, token: str | None = None) -> str:
    """Verify the complete OCI manifest digest rather than trusting a mutable tag."""
    headers = {"Accept": ", ".join(_MANIFEST_MIMES)}
    if token is not None:
        headers["Authorization"] = "Bearer " + token
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=30) as response:
        mime = response.headers.get("Content-Type", "").split(";", 1)[0].strip()
        if mime not in _MANIFEST_MIMES:
            raise RegistryVerificationError("Unexpected OCI/Docker manifest content type")
        data = response.read(2_000_001)
        declared = response.headers.get("Docker-Content-Digest")
    if not data or len(data) > 2_000_000:
        raise RegistryVerificationError("Registry manifest is empty or oversized")
    try:
        manifest = json.loads(data)
    except (ValueError, UnicodeDecodeError) as error:
        raise RegistryVerificationError("Registry manifest is not JSON") from error
    if not isinstance(manifest, dict) or manifest.get("schemaVersion") != 2:
        raise RegistryVerificationError("Unsupported registry manifest schema")
    if manifest.get("mediaType", mime) != mime:
        raise RegistryVerificationError("Registry manifest media type mismatch")
    digest = hashlib.sha256(data).hexdigest()
    if declared is not None and declared.lower() != "sha256:" + digest:
        raise RegistryVerificationError("Manifest digest header mismatch")
    return digest


def _verified_official_mirror_digest(image: str, tag: str) -> str:
    """Use mirror only if its manifest is byte-identical to Docker Official."""
    if (image, tag) not in {(x[0], x[1]) for x in _OFFICIAL_BASES}:
        raise RegistryVerificationError("Unapproved image/tag")
    token = _docker_hub_token(image)
    upstream = _manifest_digest(
        f"https://registry-1.docker.io/v2/library/{image}/manifests/{tag}", token
    )
    mirror = _manifest_digest(f"https://mirror.gcr.io/v2/library/{image}/manifests/{tag}")
    if upstream != mirror or not _SHA256.fullmatch(upstream):
        raise RegistryVerificationError("Docker Official and mirror manifest digests differ")
    return upstream


def _is_ecr_quota_failure(log: str) -> bool:
    """Only recognized 429 registry throttling allows an alternate source."""
    log = log.lower()
    return any(signal in log for signal in (
        "429 too many requests", "http 429", "status code: 429",
        "429: too many requests", "data limit exceeded", "toomanyrequests",
    ))


def _build_ci_worker(image: str, tag: str, argument: str, dockerfile: str, target: str) -> None:
    """Build with ECR first; retry a 429 using an independently checked digest."""
    original = ["docker", "build", "-f", dockerfile, "-t", target, "."]
    first = subprocess.run(original, cwd=ROOT, capture_output=True, text=True, check=False)
    print(first.stdout, end="")
    print(first.stderr, file=sys.stderr, end="")
    if first.returncode == 0:
        return
    if not _is_ecr_quota_failure(first.stdout + "\n" + first.stderr):
        raise RegistryVerificationError("Build failed for reasons other than ECR throttling")
    digest = _verified_official_mirror_digest(image, tag)
    pinned = f"mirror.gcr.io/library/{image}@sha256:{digest}"
    alternate = [
        "docker", "build", "--build-arg", f"{argument}={pinned}",
        "-f", dockerfile, "-t", target, ".",
    ]
    print(f"ECR 429 detected: verified official mirror digest for {image}", flush=True)
    fallback = subprocess.run(alternate, cwd=ROOT, capture_output=True, text=True, check=False)
    print(fallback.stdout, end="")
    print(fallback.stderr, file=sys.stderr, end="")
    if fallback.returncode != 0:
        raise RegistryVerificationError("Digest-pinned mirror build failed")


def _build_ci_workers() -> None:
    """Never skip either required worker image build."""
    for params in _OFFICIAL_BASES:
        _build_ci_worker(*params)




class WorkerSupplyChainHardeningTests(unittest.TestCase):
    def test_worker_base_registry_uses_verified_docker_official_ecr_mirror(self) -> None:
        """El registry alternativo es Docker Official en ECR Public, sin cambiar variantes ni installer."""
        python = (ROOT / "workers" / "Dockerfile").read_text(encoding="utf-8")
        node = (ROOT / "workers" / "node.Dockerfile").read_text(encoding="utf-8")
        bases = lambda content: [line.strip() for line in content.splitlines()
                                 if line.strip().startswith("FROM ")]
        self.assertIn("ARG PYTHON_BASE_IMAGE=public.ecr.aws/docker/library/python:3.11-slim", python)
        self.assertIn("ARG NODE_BASE_IMAGE=public.ecr.aws/docker/library/node:22-alpine", node)
        self.assertEqual(bases(python), ["FROM ${PYTHON_BASE_IMAGE}"])
        self.assertEqual(bases(node), ["FROM ${NODE_BASE_IMAGE}"])
        self.assertIn("pip install --no-cache-dir --require-hashes --only-binary=:all:", python)
        self.assertIn("npm ci --ignore-scripts", node)
        self.assertIn("USER worker", python)
        self.assertIn("USER node", node)
        self.assertNotIn("docker.io/", python + node)


    def test_worker_ci_registry_fallback_is_verified(self) -> None:
        """429 uses a manifest-identical mirror and a digest, never a mutable tag."""
        throttled = subprocess.CompletedProcess(
            [], 1, stdout="", stderr="429 Too Many Requests: Data limit exceeded",
        )
        passed = subprocess.CompletedProcess([], 0, stdout="", stderr="")
        with patch("subprocess.run", side_effect=[throttled, passed]) as run, patch(
            __name__ + "._verified_official_mirror_digest", return_value="a" * 64
        ) as verify:
            _build_ci_worker(*_OFFICIAL_BASES[0])
        verify.assert_called_once_with("python", "3.11-slim")
        self.assertEqual(run.call_count, 2)
        call = run.call_args_list[1].args[0]
        self.assertEqual(
            call[call.index("--build-arg") + 1],
            "PYTHON_BASE_IMAGE=mirror.gcr.io/library/python@sha256:" + "a" * 64,
        )
        self.assertNotIn("docker.io/", " ".join(call))

    def test_worker_registry_mirror_digest_mismatch_is_denied(self) -> None:
        """If Docker Official and mirror differ, no fallback image is accepted."""
        with patch(__name__ + "._docker_hub_token", return_value="private"), patch(
            __name__ + "._manifest_digest", side_effect=["a" * 64, "b" * 64]
        ):
            with self.assertRaisesRegex(RegistryVerificationError, "digests differ"):
                _verified_official_mirror_digest("python", "3.11-slim")
        with self.assertRaisesRegex(RegistryVerificationError, "Unapproved"):
            _verified_official_mirror_digest("unknown", "latest")

    def test_worker_registry_fallback_does_not_hide_non_registry_failures(self) -> None:
        """Actual build/test failures stay red without an alternate image pull."""
        failed = subprocess.CompletedProcess([], 1, stdout="pip install failed", stderr="")
        with patch("subprocess.run", return_value=failed) as run, patch(
            __name__ + "._verified_official_mirror_digest"
        ) as verify:
            with self.assertRaisesRegex(RegistryVerificationError, "other than ECR"):
                _build_ci_worker(*_OFFICIAL_BASES[0])
        run.assert_called_once()
        verify.assert_not_called()

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
    if sys.argv[1:] == ["--build-ci-workers"]:
        try:
            _build_ci_workers()
        except (RegistryVerificationError, OSError, ValueError) as error:
            print(f"FAIL-CLOSED: {error}", file=sys.stderr)
            raise SystemExit(1) from error
    else:
        unittest.main()
