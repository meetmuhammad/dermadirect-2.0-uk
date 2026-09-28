#!/bin/sh
# Temporary web server for the simulated Kinsta review site (Action A rehearsal only).
#   sim-web.sh up <sim-root> <db>   |   sim-web.sh down
# Web container: internal (egress-blocked) network only, serves <sim-root>/public, marks requests
# arriving with X-Forwarded-Proto https as HTTPS. Proxy container publishes 127.0.0.1:8091 and pins
# Host to dermadirect20uk.kinsta.cloud. Both are removed by `down`; nothing else is touched.
set -eu
cd "$(dirname "$0")/../../.."
case "$1" in
up)
  SIM=$2; DB=$3; CONF=$SIM/conf; mkdir -p "$CONF"
  sed 's|</VirtualHost>|    SetEnvIf X-Forwarded-Proto "https" HTTPS=on\n</VirtualHost>|' docker/php/000-default.conf > "$CONF/vhost.conf"
  cat > "$CONF/nginx.conf" <<'NG'
events { worker_connections 256; }
http { server { listen 80; client_max_body_size 64m;
  location / { proxy_pass http://dd-sim-web:80; proxy_set_header Host dermadirect20uk.kinsta.cloud;
    proxy_set_header X-Forwarded-Proto https; proxy_set_header X-Forwarded-For $remote_addr; } } }
NG
  docker run -d --rm --name dd-sim-web --network dermadirect20uk_internal -v "$SIM/public:/var/www/html" \
    -v "$CONF/vhost.conf:/etc/apache2/sites-available/000-default.conf:ro" -e DB_NAME="$DB" dermadirect20uk-web >/dev/null
  docker run -d --rm --name dd-sim-proxy --network dermadirect20uk_internal -p 127.0.0.1:8091:80 -v "$CONF/nginx.conf:/etc/nginx/nginx.conf:ro" nginx:alpine >/dev/null
  docker network connect dermadirect20uk_ingress dd-sim-proxy
  sleep 3; echo "sim web up: https://dermadirect20uk.kinsta.cloud -> 127.0.0.1:8091 (db $DB)" ;;
down) docker rm -f dd-sim-web dd-sim-proxy >/dev/null 2>&1 || true; echo "sim web removed" ;;
esac
