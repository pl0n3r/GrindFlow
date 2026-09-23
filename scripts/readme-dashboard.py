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
CHANGED_FILES_MARKER = "<!-- grindflow:changed-files -->"
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
    selected = ["preflight", "fast[operational contracts + automation syntax + README dashboard]"]
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


def changed_files_block(readme: str) -> tuple[int, int]:
    """Locate the generated file list by its unique structural marker."""
    if readme.count(CHANGED_FILES_MARKER) != 1:
        fail("cannot locate changed files; expected exactly one structural marker")
    marker = readme.find(CHANGED_FILES_MARKER)
    end = readme.find("\n## ", marker + len(CHANGED_FILES_MARKER))
    if end < 0:
        fail("cannot locate changed files; following section is missing")
    return marker, end


def update_changed_files(readme: str, files: list[str]) -> str:
    marker, end = changed_files_block(readme)
    prefix_end = marker + len(CHANGED_FILES_MARKER)
    return (
        readme[:prefix_end]
        + "\n"
        + generated_file_rows(files)
        + "\n"
        + readme[end:]
    )


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
        CHANGED_FILES_MARKER,
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
    marker, end = changed_files_block(readme)
    changed = readme[marker:end]
    listed = sorted(FILE_ROW_PATTERN.findall(changed))
    if files != listed:
        fail("changed-file list is stale. Run readme-dashboard.py --update.")


def roadmap_error(readme: str) -> str | None:
    convention = section(readme, "## Progress convention")
    if "✅ ~~Completado~~" not in convention or "🚧 Pendiente" not in convention:
        return "canonical progress convention missing or not documented"

    next_section = section(readme, "## Qué sigue")
    issue_destinations = re.findall(
        r"https://github\.com/pl0n3r/GrindFlow/issues/(\d+)\b",
        next_section,
        flags=re.I,
    )
    if issue_destinations != ["2"]:
        return "Qué sigue must link only the canonical issue #2 exactly once"
    if re.search(r"\*\*(?:DONE|NOW|NEXT|LATER|BLOCKED / EXTERNAL)\*\*", next_section):
        return "Qué sigue must only link canonical issue #2; snapshot lanes belong in Panorama"

    panorama = section(readme, "## Panorama general pendiente")
    if not panorama:
        return "README must keep the current operational Panorama snapshot"
    if "**DONE**" in panorama:
        return "Panorama must not accumulate completed history; keep that in issue #2"

    lanes = ("**NOW**", "**NEXT**", "**BLOCKED / EXTERNAL**", "**LATER**")
    for lane in lanes:
        if panorama.count(lane) != 1:
            return f"Panorama must contain exactly one {lane} lane"

    for row in panorama.splitlines():
        matching = [lane for lane in lanes if lane in row]
        if not matching:
            continue
        if "🚧" not in row and "⛔" not in row:
            return "Panorama pending/blocked row missing status symbol"
        if "~~" in row:
            return "Panorama must not contain completed/struck-through history"

        payload = row
        for lane in matching:
            payload = payload.replace(lane, "")
        payload = payload.replace("🚧", "").replace("⛔", "")
        if not any(char.isalnum() for char in payload):
            return "Panorama lane must include current work or status content"

    return None


def validate_roadmap(readme: str) -> None:
    error = roadmap_error(readme)
    if error is not None:
        fail(error)


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


def current_head() -> str:
    return subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    ).stdout.strip().lower()


def head_is_ancestor(head: str, current: str) -> bool:
    result = subprocess.run(
        ["git", "merge-base", "--is-ancestor", head, current],
        cwd=ROOT,
        check=False,
        text=True,
        capture_output=True,
    )
    return result.returncode == 0


def regenerate_once(base: str, head: str | None) -> bool:
    files = changed_files(base, head)
    additions, deletions = diff_metrics(base, head)
    scope = ci_scope(files)
    current = README_PATH.read_text(encoding="utf-8")
    require_markers(current)
    regenerated = generated_readme(current, files, additions, deletions, scope)
    if regenerated == current:
        return False
    README_PATH.write_text(regenerated, encoding="utf-8")
    return True


def update(base: str, head: str) -> None:
    """Regenerate exact dashboard facts locally or from GitHub's synthetic PR merge."""
    current = current_head()
    if current != head:
        if not head_is_ancestor(head, current):
            fail(f"--update head {head} is not an ancestor of checked-out HEAD {current}")
        regenerate_once(base, head)
        print("README dashboard regenerated from exact PR head diff")
        return

    for _ in range(5):
        if not regenerate_once(base, None):
            files = changed_files(base, None)
            additions, deletions = diff_metrics(base, None)
            print(
                "README dashboard already generated: "
                f"{len(files)} files, +{additions}/-{deletions}; gates={gate_plan(ci_scope(files))}"
            )
            return

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

## Archivos modificados en esta entrega candidata
Inventario de solo el deploy actual:
<!-- grindflow:changed-files -->
- `old.txt`

## Validación
ok
"""
    scope = {"run_tests": "true", "run_database": "true"}
    generated = generated_readme(sample, ["README.md", "app.php"], 12, 3, scope)
    assert "| **2** | **+12** | **−3** | **+9** |" in generated
    assert "**preflight · fast[operational contracts + automation syntax + README dashboard] · PHPUnit · MariaDB**" in generated
    assert "- `README.md`\n- `app.php`" in generated

    duplicate = sample + "\n" + CHANGED_FILES_MARKER + "\n"
    try:
        changed_files_block(duplicate)
    except SystemExit:
        pass
    else:
        raise AssertionError("duplicate changed-files marker must be rejected")

    roadmap_sample = """## Progress convention
✅ ~~Completado~~
🚧 Pendiente

## Qué sigue
[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **NOW** | 🚧 entrega actual | 🚧 validando |
| **NEXT** | 🚧 siguiente slice | 🚧 pendiente |
| **BLOCKED / EXTERNAL** | ⛔ dependencia | ⛔ externa |
| **LATER** | 🚧 trabajo posterior | 🚧 pendiente |
"""
    assert roadmap_error(roadmap_sample) is None

    for invalid in (
        roadmap_sample.replace("## Panorama general pendiente", "## Otro panorama"),
        roadmap_sample.replace("| **NEXT** | 🚧 siguiente slice | 🚧 pendiente |\n", ""),
        roadmap_sample.replace("| **NEXT** | 🚧 siguiente slice | 🚧 pendiente |", "| **DONE** | ✅ ~~historia~~ | ✅ ~~hecho~~ |"),
        roadmap_sample.replace(
            "[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)",
            "[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)\n| **NOW** | 🚧 duplicado | 🚧 pendiente |",
        ),
        roadmap_sample.replace(
            "[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)",
            "[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)\\n"
            "[Smoke #73](https://github.com/pl0n3r/GrindFlow/issues/73)",
        ),
        roadmap_sample.replace(
            "| **NOW** | 🚧 entrega actual | 🚧 validando |",
            "🚧 **NOW**",
        ),
    ):
        assert roadmap_error(invalid) is not None

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
