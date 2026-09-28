#!/bin/sh
# Runs ON the target server before any deploy action. Refuses anything that is not the new
# Bedrock/Sage site, and (for data-replacing actions) anything already trading as production.
#   remote-guard.sh <project-root> <expected-url> [code|initial|restore|final-sync]
set -eu
ROOT=$1; URL=$2; ACTION=${3:-code}
cd "$ROOT"
[ -f web/wp/wp-settings.php ] && [ -f config/application.php ] || { echo "GUARD: $ROOT is not a Bedrock project"; exit 3; }
[ -d web/app/themes/derma-direct ] || { echo "GUARD: new Sage theme missing"; exit 3; }
env_val() { sed -n "s/^$1=['\"]\{0,1\}\([^'\"]*\)['\"]\{0,1\}$/\1/p" .env | tail -1; }
[ "$(env_val WP_HOME)" = "$URL" ] || { echo "GUARD: WP_HOME does not match $URL"; exit 3; }
if [ "$ACTION" != code ]; then
  [ "$(env_val WP_ENV)" != production ] || { echo "GUARD: WP_ENV=production — data actions are disabled once the site is live"; exit 3; }
  [ "$(env_val DD_SAFETY_HARNESS)" != off ] || { echo "GUARD: safety harness is off — refusing"; exit 3; }
fi
if [ "$ACTION" = initial ] || [ "$ACTION" = restore ]; then
  n=$(wp db query --skip-column-names "SELECT COUNT(*) FROM $(env_val DB_PREFIX)posts WHERE post_type='shop_order' AND ID>=1000000" 2>/dev/null || echo 0)
  [ "${n:-0}" = 0 ] || { echo "GUARD: $n orders were created on the new site — replacing its database ($ACTION) is permanently disabled"; exit 3; }
fi
echo "GUARD OK: $(hostname) $ROOT $URL action=$ACTION"
