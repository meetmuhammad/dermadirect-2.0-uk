#!/bin/sh
# Local viewer for a migrated candidate database, beside the canonical :8088 site (never replacing it).
#   scripts/migration/view-candidate.sh up <dd_rh_* db> [port=8090] [uploads dir]   |   view-candidate.sh down
# [uploads dir] (e.g. the pulled hosted uploads) is mounted read-only over web/app/uploads for this viewer only.
# A one-off copy of the compose `web` service (same code, same egress-blocked `internal` network, same
# safety harness and Mailpit) with DB_NAME/WP_HOME overridden, plus an nginx relay that publishes
# 127.0.0.1:<port> only. The database is served as is: no URL rewrite, nothing written by this script.
set -eu
cd "$(dirname "$0")/../.."
case "${1:-}" in
up)
  DB=${2:?db}; PORT=${3:-8090}; UPL=${4:-}
  VOL=""; [ -n "$UPL" ] && VOL="-v $(cd "$UPL" && pwd):/var/www/html/web/app/uploads:ro"
  case "$DB" in dd_rh_*) ;; *) echo "refusing: only dd_rh_* candidate databases"; exit 2;; esac
  mkdir -p recovery/oct-refresh; CONF=recovery/oct-refresh/view-nginx.conf
  cat > "$CONF" <<NG
events { worker_connections 256; }
http { server { listen 80; client_max_body_size 64m;
  location / { proxy_pass http://dd-cand-web:80; proxy_set_header Host localhost:$PORT; proxy_set_header X-Forwarded-For \$remote_addr; } } }
NG
  DB_NAME=$DB WP_HOME=http://localhost:$PORT WP_SITEURL=http://localhost:$PORT/wp \
    docker compose run -d --no-deps --name dd-cand-web $VOL web >/dev/null
  docker run -d --rm --name dd-cand-proxy --network dermadirect20uk_internal -p 127.0.0.1:$PORT:80 -v "$PWD/$CONF:/etc/nginx/nginx.conf:ro" nginx:alpine >/dev/null
  docker network connect dermadirect20uk_ingress dd-cand-proxy
  sleep 3; echo "candidate $DB at http://localhost:$PORT (8088 unchanged)" ;;
down) docker rm -f dd-cand-web dd-cand-proxy >/dev/null 2>&1 || true; echo "candidate viewer removed" ;;
*) echo "usage: view-candidate.sh up <db> [port] | down"; exit 2 ;;
esac
