#!/usr/bin/env python3
from __future__ import annotations

import os
import re
import shlex
import subprocess
import sys
import tempfile
from contextlib import contextmanager
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[2]
HOST_RE = re.compile(r"^(?=.{1,253}$)(?!-)[A-Za-z0-9.-]+(?<!-)$")
USER_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
REMOTE_SHELL = "sh -c"


class AdapterError(RuntimeError):
    pass


def env(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise AdapterError(f"falta {name}")
    return value


def release_root() -> str:
    raw = env("HOSTINGER_RELEASE_ROOT")
    if not raw.startswith("/") or "\x00" in raw or "\\" in raw:
        raise AdapterError("HOSTINGER_RELEASE_ROOT inválido")
    path = PurePosixPath(raw)
    normalized = str(path)
    if (
        normalized == "/"
        or normalized != raw.rstrip("/")
        or any(part in {"", ".", ".."} for part in path.parts[1:])
    ):
        raise AdapterError("HOSTINGER_RELEASE_ROOT inválido")
    return normalized


def sha() -> str:
    value = env("GITHUB_SHA")
    if not SHA_RE.fullmatch(value):
        raise AdapterError("GITHUB_SHA inválido")
    return value


def migration_mode() -> str:
    value = os.environ.get("MIGRATION_MODE", "none").strip()
    if value not in {"none", "additive"}:
        raise AdapterError("MIGRATION_MODE inválido")
    return value


def run(
    cmd: list[str],
    *,
    cwd: Path = ROOT,
    extra_env: dict[str, str] | None = None,
) -> None:
    merged = os.environ.copy()
    if extra_env:
        merged.update(extra_env)
    subprocess.run(cmd, cwd=cwd, env=merged, check=True)


@contextmanager
def ssh_material():
    host = env("HOSTINGER_SSH_HOST")
    user = env("HOSTINGER_SSH_USER")
    port = env("HOSTINGER_SSH_PORT")
    labels = host.split(".")
    if (
        not HOST_RE.fullmatch(host)
        or any(
            not label
            or len(label) > 63
            or label.startswith("-")
            or label.endswith("-")
            for label in labels
        )
        or not USER_RE.fullmatch(user)
        or not port.isdigit()
        or not 1 <= int(port) <= 65535
    ):
        raise AdapterError("configuración SSH inválida")
    known = env("HOSTINGER_KNOWN_HOSTS")
    key = env("DEPLOY_SSH_KEY")
    with tempfile.TemporaryDirectory(prefix="grindflow-factory-") as tmp:
        directory = Path(tmp)
        keyfile = directory / "key"
        knownfile = directory / "known_hosts"
        keyfile.write_text(key, encoding="utf-8")
        keyfile.chmod(0o600)
        knownfile.write_text(known + "\n", encoding="utf-8")
        knownfile.chmod(0o600)
        base = [
            "ssh",
            "-i",
            str(keyfile),
            "-p",
            port,
            "-o",
            "BatchMode=yes",
            "-o",
            "IdentitiesOnly=yes",
            "-o",
            "StrictHostKeyChecking=yes",
            "-o",
            f"UserKnownHostsFile={knownfile}",
            f"{user}@{host}",
        ]
        yield base, host, user, port, keyfile, knownfile


def remote(base: list[str], script: str, *args: str) -> None:
    command = " ".join([script, *[shlex.quote(arg) for arg in args]])
    run([*base, command])


def build() -> None:
    required = (
        ROOT / "artisan",
        ROOT / "composer.json",
        ROOT / "scripts/deploy-hostinger.sh",
    )
    if any(not path.is_file() or path.is_symlink() for path in required):
        raise AdapterError("fuentes requeridas de deploy ausentes o inseguras")
    run(["bash", "-n", "scripts/deploy-hostinger.sh"])


def backup() -> None:
    if migration_mode() != "none":
        raise AdapterError(
            "migraciones Factory deshabilitadas hasta disponer de backup DB verificable"
        )
    root = release_root()
    commit = sha()
    marker = f"{root}/.predeploy-{commit}"
    with ssh_material() as (ssh, *_):
        script = (
            'set -eu; root=$1; marker=$2; cur=$root/current; '
            '[ ! -L "$root" ] || exit 40; mkdir -p "$root" "$root/releases"; '
            '[ ! -L "$root/releases" ] || exit 41; '
            '[ ! -L "$marker" ] || exit 42; '
            'if [ -L "$cur" ]; then old=$(readlink "$cur"); '
            'case "$old" in "$root"/releases/*) ;; *) exit 43 ;; esac; '
            '[ -d "$old" ] && [ ! -L "$old" ] || exit 44; '
            'printf "%s\\n" "$old" > "$marker.tmp"; '
            'else [ ! -e "$cur" ] || exit 45; printf "__NONE__\\n" > "$marker.tmp"; fi; '
            'chmod 600 "$marker.tmp"; mv -f "$marker.tmp" "$marker"'
        )
        remote(ssh, REMOTE_SHELL, script, "grindflow-backup", root, marker)


def migrate() -> None:
    raise AdapterError(
        "migración Factory bloqueada: usar backup/migración aprobado antes de habilitar additive"
    )


def deploy() -> None:
    root = release_root()
    commit = sha()
    release = f"{root}/releases/{commit}"
    marker = f"{root}/.predeploy-{commit}"
    with ssh_material() as (ssh, host, user, port, keyfile, knownfile):
        preflight = (
            'set -eu; root=$1; rel=$2; marker=$3; shared=$root/shared; '
            '[ -f "$marker" ] && [ ! -L "$marker" ] || exit 50; '
            'mkdir -p "$root/releases" "$rel"; '
            'for p in "$root" "$root/releases" "$rel"; do [ ! -L "$p" ] || exit 51; done; '
            '[ -f "$shared/.env" ] && [ ! -L "$shared/.env" ] || exit 52; '
            '[ -d "$shared/storage" ] && [ ! -L "$shared/storage" ] || exit 53'
        )
        remote(
            ssh,
            REMOTE_SHELL,
            preflight,
            "grindflow-deploy-preflight",
            root,
            release,
            marker,
        )
        rsync_ssh = (
            f"ssh -i {shlex.quote(str(keyfile))} -p {port} "
            "-o BatchMode=yes -o IdentitiesOnly=yes "
            "-o StrictHostKeyChecking=yes "
            f"-o UserKnownHostsFile={shlex.quote(str(knownfile))}"
        )
        run(
            [
                "rsync",
                "-a",
                "--delete",
                "--chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r",
                "--exclude=.git/",
                "--exclude=.github/",
                "--exclude=.env",
                "--exclude=.env.*",
                "--exclude=.release-sha",
                "--exclude=node_modules/",
                "--exclude=vendor/",
                "--exclude=storage/",
                "--exclude=tests/",
                "-e",
                rsync_ssh,
                f"{ROOT}/",
                f"{user}@{host}:{release}/",
            ]
        )
        finalize = (
            'set -eu; root=$1; rel=$2; sha=$3; marker=$4; shared=$root/shared; '
            '[ -d "$rel" ] && [ ! -L "$rel" ] || exit 54; '
            '[ -f "$marker" ] && [ ! -L "$marker" ] || exit 55; '
            'cp "$shared/.env" "$rel/.env"; chmod 600 "$rel/.env"; '
            'if [ -L "$rel/storage" ] && [ "$(readlink "$rel/storage")" = "$shared/storage" ]; then :; '
            'else [ ! -e "$rel/storage" ] || exit 56; ln -s "$shared/storage" "$rel/storage"; fi; '
            'cd "$rel"; SMOKE_URL="" bash scripts/deploy-hostinger.sh; '
            'printf "%s\\n" "$sha" > "$rel/.release-sha.tmp"; '
            'chmod 600 "$rel/.release-sha.tmp"; mv -f "$rel/.release-sha.tmp" "$rel/.release-sha"; '
            'old=$(cat "$marker"); prev=$root/.previous; '
            'if [ "$old" = "__NONE__" ]; then rm -f "$prev"; '
            'else case "$old" in "$root"/releases/*) ;; *) exit 57 ;; esac; '
            'printf "%s\\n" "$old" > "$prev.tmp"; chmod 600 "$prev.tmp"; mv -f "$prev.tmp" "$prev"; fi; '
            'cur=$root/current; rm -f "$root/current.next"; '
            'ln -s "$rel" "$root/current.next"; mv -Tf "$root/current.next" "$cur"; '
            'rm -f "$marker"'
        )
        remote(
            ssh,
            REMOTE_SHELL,
            finalize,
            "grindflow-deploy",
            root,
            release,
            commit,
            marker,
        )


def rollback() -> None:
    root = release_root()
    with ssh_material() as (ssh, *_):
        script = (
            'set -eu; root=$1; prev=$root/.previous; [ -f "$prev" ] && [ ! -L "$prev" ] || exit 60; '
            'target=$(cat "$prev"); case "$target" in "$root"/releases/*) ;; *) exit 61 ;; esac; '
            '[ -d "$target" ] && [ ! -L "$target" ] || exit 62; '
            '[ -f "$target/.release-sha" ] && [ ! -L "$target/.release-sha" ] || exit 63; '
            'release_sha=$(cat "$target/.release-sha"); '
            '[ "$target" = "$root/releases/$release_sha" ] || exit 64; '
            'rm -f "$root/current.next"; ln -s "$target" "$root/current.next"; '
            'mv -Tf "$root/current.next" "$root/current"'
        )
        remote(ssh, REMOTE_SHELL, script, "grindflow-rollback", root)


STAGES = {
    "build": build,
    "backup": backup,
    "migrate": migrate,
    "deploy": deploy,
    "rollback": rollback,
}


def main() -> int:
    if len(sys.argv) != 2 or sys.argv[1] not in STAGES:
        print("uso: adapter.py <build|backup|migrate|deploy|rollback>", file=sys.stderr)
        return 2
    try:
        STAGES[sys.argv[1]]()
    except (AdapterError, subprocess.CalledProcessError) as exc:
        print(f"adapter {sys.argv[1]} falló: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
