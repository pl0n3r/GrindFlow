#!/usr/bin/env python3
"""Guard fail-closed para decidir si un push exact-main debe publicar release.

Lee solo objetos Git versionados. Nunca crea/modifica tags, releases o refs.
Solo omite publicación con versión sin cambio y tag anotado previo auténtico.
"""
from __future__ import annotations

import argparse
from pathlib import Path
import re
import subprocess
import sys

SHA = re.compile(r"[0-9a-f]{40}\Z")
VERSION = re.compile(r"(?m)^\s*['\"]number['\"]\s*=>\s*['\"]((?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8}))['\"]\s*,?\s*$")
NUMBER_KEY = re.compile(r"(?m)^\s*['\"]number['\"]\s*=>")
SOURCE = "config/version.php"


class GuardError(ValueError):
    """Falta prueba canónica para autorizar un skip de release."""


def git(repo: Path, *args: str, preserve_output: bool = False) -> str:
    try:
        result = subprocess.run(
            ["git", *args], cwd=repo, capture_output=True, text=True,
            check=False, timeout=10,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise GuardError("Consulta Git no verificable") from exc
    if result.returncode != 0:
        raise GuardError("Objeto, tag o relación Git inválida")
    # Git diff -z is NUL-delimited. strip() would silently remove leading
    # whitespace/control characters from the first filename.
    return result.stdout if preserve_output else result.stdout.strip()


def version_from_text(source: str) -> tuple[int, int, int]:
    if len(source) > 16384 or len(NUMBER_KEY.findall(source)) != 1:
        raise GuardError("Fuente de versión ambigua o fuera de presupuesto")
    matches = VERSION.findall(source)
    if len(matches) != 1:
        raise GuardError("Número de versión PHP ausente o malformado")
    return tuple(int(piece) for piece in matches[0].split("."))


def version_at(repo: Path, sha: str) -> tuple[int, int, int]:
    if not SHA.fullmatch(sha):
        raise GuardError("SHA de versión inválido")
    size = git(repo, "cat-file", "-s", f"{sha}:{SOURCE}")
    if not size.isdecimal() or int(size) > 16384:
        raise GuardError("Tamaño de fuente de versión inválido")
    return version_from_text(git(repo, "show", f"{sha}:{SOURCE}"))


def decide_release(repo: Path, before: str, after: str) -> bool:
    if not SHA.fullmatch(before) or not SHA.fullmatch(after):
        raise GuardError("SHAs de evento inválidos")
    if before == "0" * 40 or before == after:
        raise GuardError("No se puede verificar la transición de push")
    if git(repo, "rev-parse", "--verify", "HEAD") != after:
        raise GuardError("HEAD de checkout distinto del push autenticado")
    for sha in (before, after):
        if git(repo, "cat-file", "-t", sha) != "commit":
            raise GuardError("SHA no apunta a commit")
    # No aceptar force pushes: solo un ancestry completo autoriza la clasificación.
    git(repo, "merge-base", "--is-ancestor", before, after)
    previous = version_at(repo, before)
    current = version_at(repo, after)
    if current < previous:
        raise GuardError("Descenso de versión prohibido")
    if current > previous:
        return True  # Factory release.yml@v1 debe seguir aplicando todas sus puertas.
    # Un skip NO es admisible si el push cambia runtime, metadata ejecutable,
    # manifests o release/CI: esas rutas requieren una versión nueva antes
    # de publicación, aunque el guard de PR permita construir en paralelo.
    changed = git(repo, "diff", "--name-only", "--no-renames", "-z", before, after, "--",
                  preserve_output=True)
    paths = changed.split("\0")
    if paths[-1:] == [""]:
        paths.pop()
    if not paths or len(paths) > 256 or len(set(paths)) != len(paths):
        raise GuardError("Inventario de cambios ausente, ambiguo o excesivo")
    # Documentación pura y fixtures de tests pueden saltar publicación.
    # Un JSON/YAML/script bajo readme/ o docs/ NO es documentación segura:
    # puede cambiar metadata operativa sin variar config/version.php.
    # El workflow readme-contract también es código de CI, no un archivo MD.
    allow_exact = {"README.md", "AGENTS.md", "AGENTES.md"}
    doc_suffixes = (".md", ".mdx", ".rst", ".txt", ".png", ".jpg",
                    ".jpeg", ".gif", ".webp")
    for path in paths:
        if (not path or path.startswith("/") or path.strip() != path
                or any(ord(char) < 32 or ord(char) == 127 for char in path)):
            raise GuardError("Ruta de diff inválida")
        if path in allow_exact:
            continue
        if path.startswith(("docs/", "readme/")):
            if path.endswith(doc_suffixes) and ".." not in Path(path).parts:
                continue
            raise GuardError("Manifest o ruta ejecutable sin incremento de versión")
        if path.startswith("tests/") or ("/" not in path and path.endswith(".md")):
            continue
        raise GuardError("Cambio ejecutable sin incremento de versión")
    version = ".".join(str(part) for part in previous)
    ref = f"refs/tags/v{version}"
    if git(repo, "cat-file", "-t", ref) != "tag":
        raise GuardError("Tag anotado de la versión previa ausente")
    tagged = git(repo, "rev-parse", "--verify", ref + "^{commit}")
    if not SHA.fullmatch(tagged):
        raise GuardError("Tag previo no resuelve un commit")
    git(repo, "merge-base", "--is-ancestor", tagged, before)
    if version_at(repo, tagged) != previous:
        raise GuardError("Tag previo no coincide con versión declarada")
    return False


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--before", required=True)
    parser.add_argument("--after", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        publish = decide_release(Path.cwd(), args.before, args.after)
    except GuardError as exc:
        print(f"::error::tag-release-version-gate: {exc}", file=sys.stderr)
        return 1
    with args.output.open("a", encoding="utf-8") as out:
        out.write(f"publish={'true' if publish else 'false'}\n")
    print("Release de versión nueva" if publish else "Versión existente: release omitido con tag previo verificado")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
