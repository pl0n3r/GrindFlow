import importlib.util
import os
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "grindflow_factory_adapter", ROOT / "ops/factory/adapter.py"
)
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)


class FactoryDeployAdaptersTests(unittest.TestCase):
    def test_adapter_boundaries_and_paths(self):
        for name in ("build", "backup", "migrate", "deploy", "rollback"):
            path = ROOT / "ops/factory" / name
            self.assertTrue(path.is_file(), name)
            self.assertFalse(path.is_symlink(), name)
            self.assertTrue(os.access(path, os.X_OK), name)
        with patch.dict(
            os.environ,
            {"HOSTINGER_RELEASE_ROOT": "/safe/grindflow", "GITHUB_SHA": "a" * 40},
            clear=False,
        ):
            self.assertEqual(mod.release_root(), "/safe/grindflow")
            self.assertEqual(mod.sha(), "a" * 40)
        for bad in ("/", "relative", "/safe/../escape", "/safe//double"):
            with self.subTest(path=bad), patch.dict(
                os.environ, {"HOSTINGER_RELEASE_ROOT": bad}, clear=False
            ):
                with self.assertRaises(mod.AdapterError):
                    mod.release_root()

    def test_backup_refuses_database_migrations_before_remote_write(self):
        with patch.dict(
            os.environ,
            {
                "MIGRATION_MODE": "additive",
                "HOSTINGER_RELEASE_ROOT": "/safe/grindflow",
                "GITHUB_SHA": "b" * 40,
            },
            clear=False,
        ), patch.object(mod, "ssh_material") as ssh:
            with self.assertRaises(mod.AdapterError):
                mod.backup()
        ssh.assert_not_called()
        with self.assertRaises(mod.AdapterError):
            mod.migrate()

    def test_caller_is_manual_parallel_and_fail_closed(self):
        workflow = (ROOT / ".github/workflows/deploy-factory.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("FACTORY_DEPLOY_ENABLED == 'true'", workflow)
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/deploy.yml@v1", workflow
        )
        self.assertIn("migration_mode: none", workflow)
        self.assertIn("version_key: number", workflow)
        self.assertIn("transport: hostinger-ssh", workflow)
        self.assertIn("HOSTINGER_KNOWN_HOSTS", workflow)
        self.assertNotIn("live_migration_approved: true", workflow)

    def test_deploy_reuses_existing_preparation_and_exact_release(self):
        source = (ROOT / "ops/factory/adapter.py").read_text(encoding="utf-8")
        for expected in (
            "scripts/deploy-hostinger.sh",
            'releases/{commit}',
            ".predeploy-{commit}",
            ".release-sha",
            "shared/.env",
            "shared/storage",
            "current.next",
            ".previous",
            "StrictHostKeyChecking=yes",
        ):
            self.assertIn(expected, source)
        for excluded in (
            "--exclude=.git/",
            "--exclude=.env",
            "--exclude=.env.*",
            "--exclude=vendor/",
            "--exclude=storage/",
        ):
            self.assertIn(excluded, source)
        self.assertNotIn("StrictHostKeyChecking=no", source)
        self.assertNotIn("migrate:fresh", source)
        self.assertNotIn("artisan migrate --force", source)

    def test_deploy_retry_accepts_only_exact_storage_symlink(self):
        source = (ROOT / "ops/factory/adapter.py").read_text(encoding="utf-8")
        self.assertIn(
            '[ -L "$rel/storage" ] && [ "$(readlink "$rel/storage")" = "$shared/storage" ]',
            source,
        )
        self.assertIn(
            '[ ! -e "$rel/storage" ] || exit 56; ln -s "$shared/storage" "$rel/storage"',
            source,
        )
        self.assertNotIn(
            '[ ! -e "$rel/storage" ] || exit 56; ln -s "$shared/storage" "$rel/storage"; '
            'cd "$rel"',
            source,
        )

    def test_rollback_is_artifact_only(self):
        source = (ROOT / "ops/factory/adapter.py").read_text(encoding="utf-8")
        start = source.index("def rollback()")
        end = source.index("\n\nSTAGES =", start)
        rollback = source[start:end]
        self.assertIn(".previous", rollback)
        self.assertIn("current.next", rollback)
        self.assertNotIn("database", rollback.lower())
        self.assertNotIn("migrate", rollback.lower())


if __name__ == "__main__":
    unittest.main()
