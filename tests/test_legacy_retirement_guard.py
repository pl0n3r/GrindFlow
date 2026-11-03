"""GF350: offline, fail-closed legacy retirement inventory contract (#682).

No network, credentials, external services, deletion, production or fixtures
from real users. Negative tests operate only on disposable temporary trees.
"""
from __future__ import annotations

import re
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INVENTORY = Path("docs/LEGACY-DEPENDENCY-INVENTORY.md")
REQUIRED_KINDS = {"laravel", "nextjs", "worker", "supabase"}
MINIMUM_ENTRIES = 26

ROW = re.compile(
    r"^\\| (?P<path>[A-Za-z0-9_./-]+) \\| "
    r"(?P<kind>laravel|nextjs|worker|supabase) \\| "
    r"(?P<parity>[A-Z]+) \\| "
    r"(?P<retirement>[A-Z]+) \\| "
    r"(?P<evidence>[^|]+) \\|$"
)


def read_inventory(root: Path) -> list[dict[str, str]]:
    text = (root / INVENTORY).read_text(encoding="utf-8")
    entries: list[dict[str, str]] = []
    for line in text.splitlines():
        match = ROW.fullmatch(line)
        if match:
            entries.append({k: v.strip() for k, v in match.groupdict().items()})
    if len(entries) < MINIMUM_ENTRIES:
        raise AssertionError("legacy inventory missing protected entries")
    paths = [item["path"] for item in entries]
    if len(set(paths)) != len(paths):
        raise AssertionError("duplicate legacy dependency")
    if set(item["kind"] for item in entries) != REQUIRED_KINDS:
        raise AssertionError("legacy inventory lost a runtime category")
    return entries


def assert_legacy_retirement_guard(root: Path) -> None:
    entries = read_inventory(root)
    for entry in entries:
        path = Path(entry["path"])
        if path.is_absolute() or ".." in path.parts or not (root / path).is_file():
            raise AssertionError(f"missing protected legacy dependency: {path}")
        if entry["parity"] != "UNKNOWN":
            raise AssertionError(f"legacy parity requires independent evidence: {path}")
        if entry["retirement"] != "NO" or entry["evidence"] != "-":
            raise AssertionError(f"legacy retirement authorization not established: {path}")
    routes = (root / "routes/web.php").read_text(encoding="utf-8")
    if "Route::get('/login'" not in routes:
        raise AssertionError("legacy login route removed without proven parity")
    if "bucle principal del worker" not in (root / "workers/main.py").read_text(
        encoding="utf-8"
    ).lower():
        raise AssertionError("legacy worker entrypoint unexpectedly replaced")


class LegacyRetirementGuardTest(unittest.TestCase):
    def test_checked_out_repository_preserves_unverified_dependencies(self) -> None:
        assert_legacy_retirement_guard(ROOT)

    def test_missing_worker_is_a_regression(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = self._fixture(Path(tmp))
            (root / "workers/main.py").unlink()
            with self.assertRaisesRegex(AssertionError, "missing protected legacy dependency"):
                assert_legacy_retirement_guard(root)

    def test_missing_laravel_login_route_is_a_regression(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = self._fixture(Path(tmp))
            (root / "routes/web.php").write_text("<?php // removed route", encoding="utf-8")
            with self.assertRaisesRegex(AssertionError, "legacy login route removed"):
                assert_legacy_retirement_guard(root)

    def test_unverified_parity_upgrade_is_a_regression(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = self._fixture(Path(tmp))
            document = root / INVENTORY
            document.write_text(
                document.read_text(encoding="utf-8").replace(
                    "| UNKNOWN | NO | - |", "| VERIFIED | NO | - |", 1
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(AssertionError, "legacy inventory missing protected entries"):
                assert_legacy_retirement_guard(root)

    def test_unproven_retirement_authorization_is_a_regression(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = self._fixture(Path(tmp))
            document = root / INVENTORY
            document.write_text(
                document.read_text(encoding="utf-8").replace(
                    "| UNKNOWN | NO | - |", "| UNKNOWN | YES | - |", 1
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(AssertionError, "legacy inventory missing protected entries"):
                assert_legacy_retirement_guard(root)

    def test_duplicate_entry_is_a_regression(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = self._fixture(Path(tmp))
            document = root / INVENTORY
            text = document.read_text(encoding="utf-8")
            row = next(line for line in text.splitlines() if ROW.fullmatch(line))
            document.write_text(text + "\\n" + row + "\\n", encoding="utf-8")
            with self.assertRaisesRegex(AssertionError, "duplicate legacy dependency"):
                assert_legacy_retirement_guard(root)

    def _fixture(self, root: Path) -> Path:
        document = root / INVENTORY
        document.parent.mkdir(parents=True, exist_ok=True)
        document.write_text((ROOT / INVENTORY).read_text(encoding="utf-8"), encoding="utf-8")
        for entry in read_inventory(root):
            file = root / entry["path"]
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_text("fixture", encoding="utf-8")
        (root / "routes/web.php").write_text("Route::get('/login', []);", encoding="utf-8")
        (root / "workers/main.py").write_text("Bucle principal del worker", encoding="utf-8")
        return root


if __name__ == "__main__":
    unittest.main()
