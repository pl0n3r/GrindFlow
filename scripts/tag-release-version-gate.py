#!/usr/bin/env python3
"""Decide whether an exact-main push represents a new GrindFlow product release.

Read-only Git preflight. A push with an unchanged product version is *not* a
release even when it contains runtime, workflow, manifest or test changes.
Never retarget, delete or create tags; Factory release.yml@v1 owns publishing.
"""
from __future__ import annotations

import argparse
import datetime as dt
from pathlib import Path
import re
import subprocess
import sys

SHA = re.compile(r"[0-9a-f]{40}\Z")
VERSION = re.compile(
    r"(?m)^[ \t]*['\"]number['\"][ \t]*=>[ \t]*['\"]"
    r"((?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})"
    r"\.(?:0|[1-9][0-9]{0,8}))['\"][ \t]*,?[ \t]*$"
)
DATE = re.compile(r"(?m)^[ \t]*['\"]released_at['\"][ \t]*=>[ \t]*['\"](\d{4}-\d{2}-\d{2})['\"][ \t]*,?[ \t]*$")
NUMBER_KEY = re.compile(r"(?m)^[ \t]*['\"]number['\"][ \t]*=>")
DATE_KEY = re.compile(r"(?m)^[ \t]*['\"]released_at['\"][ \t]*=>")
SOURCE = "config/version.php"


class GuardError(ValueError):
    """An event or its release identity lacks sufficient trustworthy evidence."""


def git(repo: Path, *args: str, preserve_output: bool = False) -> str:
    try:
        run = subprocess.run(
            ["git", *args], cwd=repo, capture_output=True, text=True,
            check=False, timeout=10,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise GuardError("Consulta Git no verificable") from exc
    if run.returncode != 0:
        raise GuardError("Objeto, tag o relación Git inválida")
    return run.stdout if preserve_output else run.stdout.strip()


def version_from_text(source: str) -> tuple[int, int, int]:
    if len(source) > 16384 or len(NUMBER_KEY.findall(source)) != 1:
        raise GuardError("Fuente de versión ambigua o demasiado grande")
    if len(DATE_KEY.findall(source)) != 1:
        raise GuardError("Fecha de versión ausente o ambigua")
    versions, dates = VERSION.findall(source), DATE.findall(source)
    if len(versions) != 1 or len(dates) != 1:
        raise GuardError("Metadatos PHP ausentes o malformados")
    try:
        dt.date.fromisoformat(dates[0])
    except ValueError as exc:
        raise GuardError("Fecha de versión inválida") from exc
    version = tuple(int(part) for part in versions[0].split("."))
    if version[0] != 0:
        raise GuardError("La publicación 1.0 exige hito explícito")
    return version


def version_at(repo: Path, sha: str) -> tuple[int, int, int]:
    if not SHA.fullmatch(sha):
        raise GuardError("SHA de versión inválido")
    size = git(repo, "cat-file", "-s", f"{sha}:{SOURCE}")
    if not size.isdecimal() or int(size) > 16384:
        raise GuardError("Tamaño de versión inválido")
    return version_from_text(git(repo, "show", f"{sha}:{SOURCE}"))


def changed_paths(repo: Path, before: str, after: str) -> tuple[str, ...]:
    raw = git(
        repo, "diff", "--name-only", "--no-renames", "-z", before, after, "--",
        preserve_output=True,
    )
    paths = raw.split("\0")
    if paths[-1:] == [""]:
        paths.pop()
    if not paths or len(paths) != len(set(paths)):
        raise GuardError("Diff ausente o ambiguo")
    for path in paths:
        if (
            not path or path.startswith("/") or path.strip() != path
            or ".." in Path(path).parts
            or any(ord(c) < 32 or ord(c) == 127 for c in path)
        ):
            raise GuardError("Ruta de diff inválida")
    return tuple(paths)


def annotated_tag_commit(repo: Path, version: tuple[int, int, int]) -> str:
    name = ".".join(str(part) for part in version)
    ref = f"refs/tags/v{name}"
    if git(repo, "cat-file", "-t", ref) != "tag":
        raise GuardError("Falta tag anotado de la versión existente")
    tagged = git(repo, "rev-parse", "--verify", ref + "^{commit}")
    if not SHA.fullmatch(tagged) or git(repo, "cat-file", "-t", tagged) != "commit":
        raise GuardError("Identidad del tag no resoluble a commit")
    return tagged


def require_available_release_tag(
    repo: Path, current: tuple[int, int, int], after: str
) -> None:
    """Reject a new release tag already claimed by a different Git identity."""
    ref = "refs/tags/v" + ".".join(str(part) for part in current)
    try:
        found = subprocess.run(
            ["git", "show-ref", "--verify", "--quiet", ref],
            cwd=repo, capture_output=True, timeout=10, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise GuardError("No se pudo verificar exclusividad del nuevo tag") from exc
    if found.returncode == 0:
        # An idempotent retry may reuse only this exact annotated commit.
        if annotated_tag_commit(repo, current) != after:
            raise GuardError("La identidad de versión ya tiene tag en otro SHA")
    elif found.returncode != 1:
        raise GuardError("Estado de tags desconocido")


def decide_release(repo: Path, before: str, after: str) -> bool:
    """Return True only for a valid, deliberate next product version."""
    if not SHA.fullmatch(before) or not SHA.fullmatch(after):
        raise GuardError("SHAs de evento inválidos")
    if before == "0" * 40 or before == after:
        raise GuardError("Historia de evento no comprobable")
    if git(repo, "rev-parse", "--verify", "HEAD") != after:
        raise GuardError("Checkout no coincide con SHA del evento")
    for sha in (before, after):
        if git(repo, "cat-file", "-t", sha) != "commit":
            raise GuardError("SHA de evento no es commit")
    # Git ancestry is a strict prerequisite, never allow force-push histories.
    git(repo, "merge-base", "--is-ancestor", before, after)
    changed_paths(repo, before, after)
    previous, current = version_at(repo, before), version_at(repo, after)
    if current == previous:
        tagged = annotated_tag_commit(repo, previous)
        git(repo, "merge-base", "--is-ancestor", tagged, before)
        if version_at(repo, tagged) != previous:
            raise GuardError("Tag previo no coincide con versión declarada")
        return False

    major, minor, patch = previous
    allowed = {(major, minor, patch + 1), (0, minor + 1, 0)}
    if current not in allowed:
        raise GuardError("Incremento de versión no consecutivo o no permitido")

    require_available_release_tag(repo, current, after)
    return True


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--before", required=True)
    parser.add_argument("--after", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        publish = decide_release(Path.cwd(), args.before, args.after)
        with args.output.open("a", encoding="utf-8") as out:
            out.write(f"publish={'true' if publish else 'false'}\n")
    except (GuardError, OSError) as exc:
        print(f"::error::tag-release-version-gate: {exc}", file=sys.stderr)
        return 1
    print("Release nuevo autorizado por preflight" if publish else
          "Versión estable: publicación omitida con evidencia Git exacta")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
