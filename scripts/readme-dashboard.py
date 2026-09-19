#!/usr/bin/env python3
"""Validate GrindFlow README dashboard facts against the exact Git diff."""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
README_PATH = ROOT / "README.md"
SCOPE_PATH = ROOT / "scripts" / "ci-scope.sh"
SHA_PATTERN = re.compile(r"[0-9a-f]{40}")


def fail(message: str) -> None:
    print(f"README DASHBOARD CHECK FAILED: {message}", file=sys.stderr)
    raise SystemExit(1)


def validated_sha(value: str, label: str) -> str:
    normalized = value.strip().lower()
    if SHA_PATTERN.fullmatch(normalized) is None:
        fail(f"{label} must be a full 40-character hexadecimal commit SHA")
    return normalized


def changed_files(base: str, head: str) -> list[str]:
    result = subprocess.run(
        ["git", "diff", "--name-only", base, head, "--"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return sorted(line.strip() for line in result.stdout.splitlines() if line.strip())


def diff_metrics(base: str, head: str) -> tuple[int, int]:
    result = subprocess.run(
        ["git", "diff", "--numstat", base, head, "--"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    additions = 0
    deletions = 0
    for line in result.stdout.splitlines():
        parts = line.split("\t", 2)
        if len(parts) < 3:
            continue
        added, deleted, _ = parts
        additions += int(added) if added.isdigit() else 0
        deletions += int(deleted) if deleted.isdigit() else 0
    return additions, deletions


def ci_scope(files: list[str]) -> dict[str, str]:
    result = subprocess.run(
        ["bash", str(SCOPE_PATH), "pull_request"],
        cwd=ROOT,
        input="\n".join(files),
        check=True,
        text=True,
        capture_output=True,
    )
    values: dict[str, str] = {}
    for line in result.stdout.splitlines():
        if "=" in line:
            key, value = line.split("=", 1)
            values[key] = value
    return values


def gate_plan(scope: dict[str, str]) -> str:
    selected = ["preflight", "fast[contracts]"]
    selected.extend(
        label
        for key, label in (
            ("run_php_quality", "php-quality"),
            ("run_tests", "PHPUnit"),
            ("run_database", "MariaDB"),
            ("run_browser", "browser"),
            ("run_legacy", "legacy"),
        )
        if scope.get(key) == "true"
    )
    return " · ".join(selected)


def section(readme: str, heading: str) -> str:
    _, found, remainder = readme.partition(heading)
    if not found:
        return ""
    body, _, _ = remainder.partition("\n## ")
    return body


def require_markers(readme: str) -> None:
    markers = [
        "# GrindFlow — Último deploy",
        "## Progress convention",
        "✅ ~~Completado~~",
        "🚧 Pendiente",
        "Version",
        "https://github.com/drpipe1098-commits/GrindFlow/issues/88",
        "## Estado del deploy",
        "## Huella del cambio",
        "## Calidad y entrega",
        "## Flujo de entrega",
        "## Qué se hizo",
        "## Archivos modificados en este deploy",
        "## Validación",
        "## Qué sigue",
        "## Panorama general pendiente",
        "actions/workflows/grindflow-ci.yml/badge.svg",
        "sonarcloud.io/api/project_badges/measure",
        "actions/workflows/production-smoke.yml/badge.svg",
        "mermaid",
        "PR + snapshot exacto",
        "CodeRabbit",
        "CI del SHA exacto de main",
        "solo el deploy actual",
    ]
    missing = [marker for marker in markers if marker not in readme]
    if missing:
        fail("missing dashboard marker(s): " + ", ".join(missing))
    if len(readme.encode("utf-8")) >= 8000:
        fail("README must stay below 8 KB")


def validate_delta(readme: str, files: list[str], additions: int, deletions: int) -> None:
    net = additions - deletions
    expected = f"| **{len(files)}** | **+{additions}** | **−{deletions}** | **{net:+d}** |"
    delta = section(readme, "## Huella del cambio")
    if "<!-- grindflow:git-delta -->" not in delta or expected not in delta:
        fail(f"Git delta is stale; expected: {expected}")


def validate_gate_plan(readme: str, scope: dict[str, str]) -> None:
    expected = f"**{gate_plan(scope)}**"
    quality = section(readme, "## Calidad y entrega")
    if "<!-- grindflow:gate-plan -->" not in quality or expected not in quality:
        fail(f"gate plan is stale; expected: {expected}")


def validate_changed_files(readme: str, files: list[str]) -> None:
    changed = section(readme, "## Archivos modificados en este deploy")
    listed = sorted(re.findall(r"^- \`([^\`]+)\`(?:\s+—.*)?$", changed, flags=re.M))
    if files != listed:
        fail("changed-file list is stale; expected " + ", ".join(files))


def validate_roadmap(readme: str) -> None:
    lanes = ("**NOW**", "**NEXT**", "**LATER**", "**BLOCKED / EXTERNAL**")
    missing = [lane for lane in lanes if lane not in readme]
    if missing:
        fail("missing roadmap lane(s): " + ", ".join(missing))

    convention = section(readme, "## Progress convention")
    if "✅ ~~Completado~~" not in convention or "🚧 Pendiente" not in convention:
        fail("canonical progress convention missing or not documented")

    roadmap = section(readme, "## Qué sigue") + section(readme, "## Panorama general pendiente")
    if "https://github.com/drpipe1098-commits/GrindFlow/issues/88" not in roadmap:
        fail("roadmap must link to canonical issue #88")

    for row in roadmap.splitlines():
        if not re.search(r"\*\*(?:DONE|NOW|NEXT|LATER|BLOCKED / EXTERNAL)\*\*", row):
            continue
        if "✅" not in row and "🚧" not in row:
            fail("roadmap row missing completed/pending symbol")
        if "✅" in row and "~~" not in row:
            fail("completed work must be struck through")
        if "🚧" in row and "~~" in row:
            fail("pending work must remain unstruck")


def validate_version(readme: str) -> None:
    content = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
    version = re.findall(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", content)
    if len(version) != 1 or f"v{version[0]}" not in section(readme, "## Estado del deploy"):
        fail("README must display exact committed GrindFlow product version")


def validate(base: str, head: str) -> None:
    files = changed_files(base, head)
    additions, deletions = diff_metrics(base, head)
    scope = ci_scope(files)
    readme = README_PATH.read_text(encoding="utf-8")
    require_markers(readme)
    validate_delta(readme, files, additions, deletions)
    validate_gate_plan(readme, scope)
    validate_changed_files(readme, files)
    validate_roadmap(readme)
    validate_version(readme)
    print(
        "README dashboard matches exact diff: "
        f"{len(files)} files, +{additions}/-{deletions}, "
        f"net {additions - deletions:+d}; gates={gate_plan(scope)}"
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    args = parser.parse_args()

    base = validated_sha(args.base, "base")
    head = validated_sha(args.head, "head")

    if args.check:
        validate(base, head)
        return

    files = changed_files(base, head)
    additions, deletions = diff_metrics(base, head)
    print(f"files={len(files)}")
    print(f"insertions={additions}")
    print(f"deletions={deletions}")
    print(f"net={additions - deletions:+d}")
    print(f"gates={gate_plan(ci_scope(files))}")


if __name__ == "__main__":
    main()
