#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

docker compose restart "$@"

# nginx (the `proxy` service) resolves the `web`/`mailpit` hostnames once at its own
# startup, so if either was just restarted (changing its container IP on the `internal`
# network) and wasn't the *only* thing restarted, restart `proxy` too to re-resolve them.
if [[ $# -gt 0 && "$*" != "proxy" ]]; then
    docker compose restart proxy
fi
