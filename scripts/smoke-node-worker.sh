#!/usr/bin/env bash
set -euo pipefail

image="${1:-grindflow-worker-node-ci}"
name="grindflow-worker-node-smoke-${GITHUB_RUN_ID:-local}-${RANDOM}"

cleanup() {
  docker rm -f "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d \
  --network none \
  --name "$name" \
  -e NEXT_PUBLIC_SUPABASE_URL=http://127.0.0.1:9 \
  -e NEXT_PUBLIC_SUPABASE_ANON_KEY=ci-anon-key-000000000000 \
  -e SUPABASE_SERVICE_ROLE_KEY=ci-service-key-0000000000 \
  -e R2_ACCESS_KEY_ID=ci \
  -e R2_SECRET_ACCESS_KEY=ci \
  -e R2_BUCKET=ci \
  -e R2_ENDPOINT=http://127.0.0.1:9 \
  -e R2_REGION=auto \
  -e UPLOAD_LINK_SECRET=0123456789abcdef0123456789abcdef \
  -e ENCRYPTION_MASTER_KEY=0000000000000000000000000000000000000000000000000000000000000000 \
  -e CLICK_IP_SALT=0123456789abcdef \
  -e INGEST_WORKER_POLL_SECONDS=1 \
  "$image" >/dev/null

for _ in $(seq 1 10); do
  logs="$(docker logs "$name" 2>&1 || true)"
  if grep -Fq "worker de ingesta en marcha" <<<"$logs"; then
    docker stop -t 5 "$name" >/dev/null
    final_logs="$(docker logs "$name" 2>&1 || true)"
    if ! grep -Fq "worker de ingesta detenido" <<<"$final_logs"; then
      printf '%s\n' "$final_logs" >&2
      echo "worker Node no termino limpiamente tras SIGTERM" >&2
      exit 1
    fi
    exit 0
  fi

  running="$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null || true)"
  if [[ "$running" != "true" ]]; then
    printf '%s\n' "$logs" >&2
    echo "worker Node termino antes de anunciar arranque" >&2
    exit 1
  fi
  sleep 1
done

docker logs "$name" >&2 || true
echo "worker Node no anuncio arranque dentro del timeout" >&2
exit 1
