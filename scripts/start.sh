#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# `docker compose up -d` starts web/db/mailpit/wpcli(profile)/proxy. The `proxy` (nginx)
# service publishes 127.0.0.1:8088 -> web:80 and 127.0.0.1:18025 -> mailpit:8025 itself
# (see docker-compose.yml, docker/nginx/nginx.conf) — no separate host-side process is
# needed. scripts/host-proxy.py is kept in the repo as an obsolete fallback only; see
# README.md.
docker compose up -d
docker compose ps
