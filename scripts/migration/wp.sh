#!/bin/sh
# Application-side WP-CLI against an isolated rehearsal DB (egress-blocked container, local
# safety mu-plugin active). Refuses the working/protected databases.
#   scripts/migration/wp.sh dd_rh_dest <wp args...>
set -eu
cd "$(dirname "$0")/../.."
db=$1; shift
case "$db" in dd_rh_dest*|dd_rh_test*) ;; *) echo "wp.sh: refusing non-rehearsal db '$db'" >&2; exit 2;; esac
[ -f web/app/mu-plugins/000-local-safety.php ] || { echo "wp.sh: local safety mu-plugin missing" >&2; exit 2; }
q=""; for a in "$@"; do q="$q '$(printf %s "$a" | sed "s/'/'\\\\''/g")'"; done
# stderr is kept except the known pre-existing WC_SA_Email warning noise and compose chatter
DB_NAME=$db docker compose --profile cli run --rm -T --no-deps --entrypoint sh wpcli -c "php -d memory_limit=2G /usr/local/bin/wp $q >/tmp/wp.out 2>&1; rc=\$?; grep -v -e WC_SA_Email -e 'Deprecated:' /tmp/wp.out; exit \$rc" 2>/dev/null
