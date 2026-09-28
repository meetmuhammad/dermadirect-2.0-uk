#!/bin/sh
# Runs a migration PHP tool inside the egress-blocked wpcli container as the least-privilege
# dd_migrate DB user (ALL on dd_rh_dest*/dd_rh_test*, SELECT only on dd_rh_src*).
#   scripts/migration/run.sh scan.php --dest=dd_rh_dest --src=dd_rh_src
set -eu
cd "$(dirname "$0")/../.."
. ./secrets/migration-db.env
tool=$1; shift
exec docker compose --profile cli run --rm -T --no-deps \
  -e MIG_DB_USER="$MIG_DB_USER" -e MIG_DB_PASS="$MIG_DB_PASS" -e MIG_REPORT_DIR=/var/www/html/migration-reports \
  --entrypoint php wpcli -d memory_limit=4G "scripts/migration/$tool" "$@"
