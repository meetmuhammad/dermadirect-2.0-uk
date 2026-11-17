#!/bin/sh
# Post-deploy health check: storefront, login page and REST root answer; no PHP fatal marker.
set -eu
URL=${1:?url}
fail=0
for p in / /wp/wp-login.php /wp-json/ /shop/; do
  code=$(curl -sk -o /tmp/dd-hc.$$ -w '%{http_code}' --max-time 60 "$URL$p" || echo 000)
  if grep -qi "critical error\|Fatal error" /tmp/dd-hc.$$ 2>/dev/null; then code="$code+FATAL"; fi
  echo "  $code $URL$p"; case "$code" in 200|301|302) ;; *) fail=1 ;; esac
done
rm -f /tmp/dd-hc.$$
[ $fail = 0 ] && echo "HEALTH OK" || { echo "HEALTH FAILED"; exit 1; }
