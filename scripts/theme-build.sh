#!/usr/bin/env bash
# Build Sage theme assets. This is the only step, besides `composer install`, where the
# stack is allowed to reach the internet (to fetch npm packages) — it runs on the isolated
# `deps` network, never on the app's `internal` network.
# Usage: scripts/theme-build.sh [build|watch|dev]
set -euo pipefail
cd "$(dirname "$0")/.."

CMD="${1:-build}"

echo "Building theme assets (yarn ${CMD}) via THEME_PATH=${THEME_PATH:-web/app/themes/sage} — requires internet access."
docker compose --profile deps run --rm node sh -c "yarn install --frozen-lockfile && yarn ${CMD}"
