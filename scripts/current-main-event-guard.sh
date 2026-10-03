#!/usr/bin/env bash
set -euo pipefail

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 1
}

event_name="${GITHUB_EVENT_NAME:-}"
event_sha="${GITHUB_SHA:-}"
repository="${GITHUB_REPOSITORY:-}"
default_branch="${DEFAULT_BRANCH:-}"

[[ "$event_name" == "push" || "$event_name" == "workflow_dispatch" ]]   || fail "evento no soportado"
[[ "$event_sha" =~ ^[0-9a-f]{40}$ ]] || fail "GITHUB_SHA inválido"
[[ "$repository" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]   || fail "GITHUB_REPOSITORY inválido"
[[ "$default_branch" =~ ^[A-Za-z0-9._/-]+$ ]]   || fail "default branch inválida"
[[ "$default_branch" != -* && "$default_branch" != *..* && "$default_branch" != *//* ]]   || fail "default branch insegura"

remote_ref="refs/heads/$default_branch"
remote_output="$(git ls-remote --heads "https://github.com/$repository.git" "$remote_ref")"   || fail "no se pudo resolver HEAD remoto"
[[ "$(printf '%s\n' "$remote_output" | sed '/^[[:space:]]*$/d' | wc -l | tr -d ' ')" == "1" ]]   || fail "HEAD remoto ambiguo"

remote_sha="$(printf '%s\n' "$remote_output" | awk 'NR == 1 {print $1}')"
remote_name="$(printf '%s\n' "$remote_output" | awk 'NR == 1 {print $2}')"
[[ "$remote_sha" =~ ^[0-9a-f]{40}$ && "$remote_name" == "$remote_ref" ]]   || fail "HEAD remoto inválido"

current=false
stale=true
if [[ "$remote_sha" == "$event_sha" ]]; then
  current=true
  stale=false
fi

if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
  {
    printf 'current=%s\n' "$current"
    printf 'stale=%s\n' "$stale"
    printf 'remote_sha=%s\n' "$remote_sha"
  } >> "$GITHUB_OUTPUT"
else
  printf 'current=%s\nstale=%s\nremote_sha=%s\n' "$current" "$stale" "$remote_sha"
fi
