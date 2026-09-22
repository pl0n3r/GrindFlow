#!/usr/bin/env python3
"""Generate and validate GrindFlow README dashboard facts from the exact Git diff."""

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
DELTA_ROW_PATTERN = re.compile(
    r"^\| \*\*\d+\*\* \| \*\*\+\d+\*\* \| \*\*−\d+\*\* \| \*\*[+-]\d+\*\* \|$",
    flags=re.M,
)
GATE_ROW_PATTERN = re.compile(r"^\| Gates seleccionados \| \*\*.*\*\* \|$", flags=re.M)
FILE_ROW_PATTERN = re.compile(r"^- `([^`]+)`(?:\s+—.*)?$", flags=re.M)


def fail(message: str) -> None:
    print(f"README DASHBOARD CHECK FAILED: {message}", file=sys.stderr)
    raise SystemExit(1)


def validated_sha(value: str, label: str) -> str:
    normalized = value.strip().lower()
    if SHA_PATTERN.fullmatch(normalized) is None:
        fail(f"{label} must be a full 40-character hexadecimal commit SHA")
    return normalized


def git_diff(args: list[str], base: str, head: str | None) -> subprocess.CompletedProcess[str]:
    command = ["git", "diff", *args, base]
    if head is not None:
        command.append(head)
    command.append("--")
    return subprocess.run(command, cwd=ROOT, check=True, text=True, capture_output=True)


def changed_files(base: str, head: str | None) -> list[str]:
    result = git_diff(["--name-only"], base, head)
    return sorted(line.strip() for line in result.stdout.splitlines() if line.strip())


def diff_metrics(base: str, head: str | None) -> tuple[int, int]:
    result = git_diff(["--numstat"], base, head)
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
            ("run_realstack", "real-stack"),
            ("run_legacy", "legacy"),
            ("run_symfony", "symfony-preview"),
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


def delta_row(files: list[str], additions: int, deletions: int) -> str:
    return f"| **{len(files)}** | **+{additions}** | **−{deletions}** | **{additions - deletions:+d}** |"


def generated_file_rows(files: list[str]) -> str:
    return "\n".join(f"- `{path}`" for path in files)


def replace_once(pattern: re.Pattern[str], content: str, replacement: str, label: str) -> str:
    updated, count = pattern.subn(lambda _: replacement, content, count=1)
    if count != 1:
        fail(f"cannot regenerate {label}; expected exactly one writable row")
    return updated


def update_changed_files(readme: str, files: list[str]) -> str:
    heading = "## Archivos modificados en este deploy"
    start = readme.find(heading)
    if start < 0:
        fail("cannot regenerate changed files; section is missing")
    end = readme.find("\n## ", start + len(heading))
    if end < 0:
        fail("cannot regenerate changed files; following section is missing")

    block = readme[start:end]
    rows = FILE_ROW_PATTERN.findall(block)
    if not rows:
        fail("cannot regenerate changed files; existing generated rows are missing")
    first_row = block.find("- `")
    if first_row < 0:
        fail("cannot regenerate changed files; generated row boundary is missing")

    prefix = block[:first_row]
    return readme[:start] + prefix + generated_file_rows(files) + "\n" + readme[end:]


def generated_readme(readme: str, files: list[str], additions: int, deletions: int, scope: dict[str, str]) -> str:
    updated = replace_once(DELTA_ROW_PATTERN, readme, delta_row(files, additions, deletions), "Git delta")
    updated = replace_once(
        GATE_ROW_PATTERN,
        updated,
        f"| Gates seleccionados | **{gate_plan(scope)}** |",
        "gate plan",
    )
    return update_changed_files(updated, files)


def require_markers(readme: str) -> None:
    markers = [
        "# GrindFlow — Último deploy",
        "## Progress convention",
        "✅ ~~Completado~~",
        "🚧 Pendiente",
        "Version",
        "https://github.com/pl0n3r/GrindFlow/issues/2",
        "## Estado del deploy",
        "## Huella del cambio",
        "<!-- grindflow:git-delta -->",
        "## Calidad y entrega",
        "<!-- grindflow:gate-plan -->",
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
    expected = delta_row(files, additions, deletions)
    delta = section(readme, "## Huella del cambio")
    if expected not in delta:
        fail(f"Git delta is stale; expected: {expected}. Run readme-dashboard.py --update.")


def validate_gate_plan(readme: str, scope: dict[str, str]) -> None:
    expected = f"**{gate_plan(scope)}**"
    quality = section(readme, "## Calidad y entrega")
    if expected not in quality:
        fail(f"gate plan is stale; expected: {expected}. Run readme-dashboard.py --update.")


def validate_changed_files(readme: str, files: list[str]) -> None:
    changed = section(readme, "## Archivos modificados en este deploy")
    listed = sorted(FILE_ROW_PATTERN.findall(changed))
    if files != listed:
        fail("changed-file list is stale. Run readme-dashboard.py --update.")


def validate_roadmap(readme: str) -> None:
    lanes = ("**NOW**", "**NEXT**", "**LATER**", "**BLOCKED / EXTERNAL**")
    missing = [lane for lane in lanes if lane not in readme]
    if missing:
        fail("missing roadmap lane(s): " + ", ".join(missing))

    convention = section(readme, "## Progress convention")
    if "✅ ~~Completado~~" not in convention or "🚧 Pendiente" not in convention:
        fail("canonical progress convention missing or not documented")

    roadmap = section(readme, "## Qué sigue") + section(readme, "## Panorama general pendiente")
    if "https://github.com/pl0n3r/GrindFlow/issues/2" not in roadmap:
        fail("roadmap must link to canonical issue #2")
    if re.search(r"(?:/issues/88\b|\[(?:roadmap|issue)[^\]]*#88\])", roadmap, flags=re.I):
        fail("legacy issue #88 cannot be an active roadmap destination")

    for row in roadmap.splitlines():
        if not re.search(r"\*\*(?:DONE|NOW|NEXT|LATER|BLOCKED / EXTERNAL)\*\*", row):
            continue
        if "✅" not in row and "🚧" not in row and "⛔" not in row:
            fail("roadmap row missing completed/pending/blocked symbol")
        if "✅" in row and "~~" not in row:
            fail("completed work must be struck through")
        if ("🚧" in row or "⛔" in row) and "~~" in row:
            fail("pending/blocked work must remain unstruck")


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


def assert_head(head: str) -> None:
    current = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    ).stdout.strip().lower()
    if current != head:
        fail(f"--update requires HEAD={head}, got {current}")


def update(base: str, head: str) -> None:
    """Regenerate dashboard rows from base plus the current working tree until stable."""
    assert_head(head)
    for _ in range(5):
        files = changed_files(base, None)
        additions, deletions = diff_metrics(base, None)
        scope = ci_scope(files)
        current = README_PATH.read_text(encoding="utf-8")
        require_markers(current)
        regenerated = generated_readme(current, files, additions, deletions, scope)
        if regenerated == current:
            print(
                "README dashboard already generated: "
                f"{len(files)} files, +{additions}/-{deletions}; gates={gate_plan(scope)}"
            )
            return
        README_PATH.write_text(regenerated, encoding="utf-8")

    fail("README dashboard did not stabilize after 5 regeneration passes")


def self_test() -> None:
    sample = """## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **1** | **+1** | **−1** | **+0** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **manual** |

## Archivos modificados en este deploy
Inventario de solo el deploy actual:
- `old.txt`

## Validación
ok
"""
    scope = {"run_tests": "true", "run_database": "true"}
    generated = generated_readme(sample, ["README.md", "app.php"], 12, 3, scope)
    assert "| **2** | **+12** | **−3** | **+9** |" in generated
    assert "**preflight · fast[contracts] · PHPUnit · MariaDB**" in generated
    assert "- `README.md`\n- `app.php`" in generated
    print("README dashboard self-test: OK")


def main() -> None:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--check", action="store_true")
    mode.add_argument("--update", action="store_true")
    mode.add_argument("--self-test", action="store_true")
    parser.add_argument("--base")
    parser.add_argument("--head")
    args = parser.parse_args()

    if args.self_test:
        self_test()
        return

    if not args.base or not args.head:
        fail("--base and --head are required unless --self-test is used")

    base = validated_sha(args.base, "base")
    head = validated_sha(args.head, "head")

    if args.update:
        update(base, head)
        return
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
