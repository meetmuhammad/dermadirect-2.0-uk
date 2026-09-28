#!/bin/sh
# Temporary web server for the simulated Kinsta review site (Action A rehearsal only).
#   sim-web.sh up <sim-root> <db>   |   sim-web.sh down
# Web container: internal (egress-blocked) network only, serves <sim-root>/public at Kinsta's real path
# (/www/dermadirect20uk_672/public, document root web/), rewrites every non-file request to WordPress
# (what Kinsta's nginx try_files does for Bedrock; Bedrock's web/.htaccess is not part of the release),
# and marks requests arriving with X-Forwarded-Proto https as HTTPS. Proxy container publishes
# 127.0.0.1:8091 and pins Host to dermadirect20uk.kinsta.cloud. Both are removed by `down`.
set -eu
cd "$(dirname "$0")/../../.."
K=/www/dermadirect20uk_672
case "$1" in
up)
  SIM=$2; DB=$3; CONF=$SIM/conf; mkdir -p "$CONF"
  cat > "$CONF/vhost.conf" <<VH
<VirtualHost *:80>
    DocumentRoot $K/public/web
    <Directory $K/public/web>
        AllowOverride None
        Require all granted
        Options -Indexes +FollowSymLinks
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>
    SetEnvIf X-Forwarded-Proto "https" HTTPS=on
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
VH
  cat > "$CONF/nginx.conf" <<'NG'
events { worker_connections 256; }
http { server { listen 80; client_max_body_size 64m;
  location / { proxy_pass http://dd-sim-web:80; proxy_set_header Host dermadirect20uk.kinsta.cloud;
    proxy_set_header X-Forwarded-Proto https; proxy_set_header X-Forwarded-For $remote_addr; } } }
NG
  docker run -d --rm --name dd-sim-web --network dermadirect20uk_internal -v "$SIM/public:$K/public" \
    -v "$CONF/vhost.conf:/etc/apache2/sites-available/000-default.conf:ro" -e DB_NAME="$DB" dermadirect20uk-web >/dev/null
  docker run -d --rm --name dd-sim-proxy --network dermadirect20uk_internal -p 127.0.0.1:8091:80 -v "$CONF/nginx.conf:/etc/nginx/nginx.conf:ro" nginx:alpine >/dev/null
  docker network connect dermadirect20uk_ingress dd-sim-proxy
  sleep 3; echo "sim web up: https://dermadirect20uk.kinsta.cloud -> 127.0.0.1:8091 (db $DB, root $K/public)" ;;
down) docker rm -f dd-sim-web dd-sim-proxy >/dev/null 2>&1 || true; echo "sim web removed" ;;
esac
