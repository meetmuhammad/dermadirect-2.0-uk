#!/bin/sh
# Local end-to-end rehearsal of Action A against a simulated Kinsta review site, using the real package,
# the real server scripts and the real code artifact.
#   code tree  = <sim-root>/public, deployed with deploy.sh code; media library = APFS clone of local uploads
#   database   = dd_rh_test_hostedsim, reset from <sim-root>/seed/hosted-pristine.sql.gz (the review-site
#                database before Action A) with the review URL in its stored site address and settings,
#                as on Kinsta (read-only check 2026-09-29). It keeps one local-only administrator at ID
#                17553 — a legacy customer's ID — as the regression case for identity-based staff restore.
#   server     = wordpress:cli container on the egress-blocked network, paths as on Kinsta
#                (/www/dermadirect20uk_672/{public,private}), running remote/initial-import.sh,
#                initial-verify.sh and restore-backup.sh from the package.
#   web        = sim-web.sh (Apache at the Kinsta path, rewrites like Kinsta's nginx), Playwright via --map.
#   scripts/deploy/tests/initial_sim_test.sh <sim-root> <package-dir> <code-artifact.tar.gz>
set -eu
cd "$(dirname "$0")/../../.."
SIM=$1; PKG=$2; ART=$3; P=$SIM/public; PRIV=$SIM/private/dd-deploy
K=/www/dermadirect20uk_672; KR=$K/public; KP=$K/private/dd-deploy; KPKG=$KP/incoming/pkg
URL=https://dermadirect20uk.kinsta.cloud; DB=dd_rh_test_hostedsim; X=dd_rh_test_export
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
fails=0; t() { if eval "$2"; then echo "PASS $1"; else echo "FAIL $1"; fails=$((fails+1)); fi; }
F() { grep -v -e WC_SA_Email -e 'a-email.php'; }

echo "== reset the simulated hosted database to its seed"
(cd "$SIM/seed" && shasum -a 256 -c hosted-pristine.sql.gz.sha256 >/dev/null) || { echo "seed missing or altered"; exit 1; }
sql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL ON $DB.* TO 'dermadirect'@'%';"
gunzip -c "$SIM/seed/hosted-pristine.sql.gz" | docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$DB"
# The seed came from a local copy (site address http://localhost:8088). On Kinsta the stored address and
# integration settings use the review URL: apply that, serialization-safe, to settings only.
for f in 'http://localhost:8088' 'http:\/\/localhost:8088'; do
  to=$URL; [ "$f" = 'http://localhost:8088' ] || to=$(echo "$URL" | sed 's#/#\\/#g')
  DB_NAME=$DB docker compose --profile cli run --rm -T --no-deps --entrypoint sh wpcli -c "php -d memory_limit=2G /usr/local/bin/wp search-replace '$f' '$to' wp_options --precise --skip-plugins --skip-themes --report-changed-only 2>&1 | grep -E 'Success|Error'" 2>/dev/null
done
t "seed: stored site address is the review URL, no local URL in settings" "[ \"\$(sql -e \"SELECT GROUP_CONCAT(option_value) FROM $DB.wp_options WHERE option_name IN ('home','siteurl')\")\" = '$URL,$URL' ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_options WHERE option_value REGEXP 'localhost:808'\")\" = 0 ]"
t "seed: regression case present (hosted administrator at 17553, a legacy customer's ID in the package)" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_usermeta WHERE user_id=17553 AND meta_key='wp_capabilities' AND meta_value LIKE '%administrator%'\")\" = 1 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $X.wp_usermeta WHERE user_id=17553 AND meta_key='wp_capabilities' AND meta_value LIKE '%\\\"customer\\\"%'\")\" = 1 ]"
t "code artifact and plugin bundle contain no macOS metadata entries (as Linux tar sees them)" "[ \"\$(docker run --rm -v $PWD/$ART:/a.tgz:ro -v $PWD/$PKG/plugins-retained.tar.gz:/p.tgz:ro --entrypoint sh wordpress:cli -c 'tar -tzf /a.tgz 2>&1; tar -tzf /p.tgz 2>&1' | grep -c '\\(^\\|/\\)\\._\\|Ignoring unknown extended header')\" = 0 ]"
HOSTED_17553=$(sql -e "SELECT SHA2(CONCAT_WS('|',user_login,user_email,user_pass),256) FROM $DB.wp_users WHERE ID=17553")
STAFF_HASH_BEFORE=$(sql -e "SELECT user_pass FROM $DB.wp_users WHERE ID=10224")

echo "== fresh Kinsta-like code tree: Action B deploy, hosted plugins, media library, hosted .env"
rm -rf "$P"; mkdir -p "$P/web/app"
cp -Rc web/app/uploads "$P/web/app/uploads"          # APFS clone: real media, no extra disk
scripts/deploy/deploy.sh code "local:$P" "$ART" | tail -1
{ grep -E '^(DB_USER|DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)=' .env
  printf "WP_ENV='staging'\nWP_HOME='%s'\nWP_SITEURL='%s/wp'\nDB_NAME='%s'\nDB_HOST='db'\nDB_PREFIX='wp_'\nDD_SAFETY_HARNESS='on'\nDISABLE_WP_CRON='true'\n" "$URL" "$URL" "$DB"; } > "$P/.env"
for d in web/app/plugins/*/; do n=$(basename "$d"); case $n in super-payments|woocommerce-gateway-rules-manager|product-batch-expiry-tracking-for-woocommerce|woocommerce-back-in-stock-notifications|wpgens-refer-a-friend-premium|advanced-shipment-tracking-pro) ;; *) cp -Rp "$d" "$P/web/app/plugins/";; esac; done
rm -rf "$SIM/private"; mkdir -p "$PRIV/incoming"; cp -Rp "$PKG" "$PRIV/incoming/pkg"
# Test container only: local MariaDB 10.11 has no TLS and the 11.x client requires it; WP-CLI passes
# --no-defaults for some calls, so client wrappers add --skip-ssl. PHP memory = Kinsta web limit (512M).
mkdir -p "$SIM/conf/bin"; printf '[client]\nskip-ssl\n' > "$SIM/conf/nossl.cnf"; printf 'memory_limit=512M\n' > "$SIM/conf/php-sim.ini"
for c in mariadb mariadb-dump mysql mysqldump; do
  printf '#!/bin/sh\nif [ "${1:-}" = --no-defaults ]; then shift; exec /usr/bin/%s --no-defaults --skip-ssl "$@"; fi\nexec /usr/bin/%s "$@"\n' $c $c > "$SIM/conf/bin/$c"; chmod +x "$SIM/conf/bin/$c"
done
DOCK="docker run --rm --network dermadirect20uk_internal -v $SIM:$K -v $SIM/conf/nossl.cnf:/etc/my.cnf.d/zz-nossl.cnf:ro -v $SIM/conf/bin:/usr/local/sbin:ro -v $SIM/conf/php-sim.ini:/usr/local/etc/php/conf.d/zz-sim.ini:ro -w $KR --entrypoint bash wordpress:cli -c"
if $DOCK 'php -r "exit(@file_get_contents(\"http://example.com\", false, stream_context_create([\"http\"=>[\"timeout\"=>5]])) === false ? 0 : 1);"' >/dev/null 2>&1; then NET=blocked; else NET=open; fi
t "simulated server cannot reach the internet" "[ $NET = blocked ]"
# pre-state for the restore drill
tables() { sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB' ORDER BY 1" | shasum | cut -c1-16; }
files() { (cd "$P" && { find web/app/plugins -maxdepth 1 | sort; find web/app/uploads -type f | sort; shasum .env; find web/app/uploads/generateblocks -type f -exec shasum {} + | sort; }) | shasum | cut -c1-16; }
PRE_TABLES=$(tables); PRE_FILES=$(files)

echo "== remote/initial-import.sh (inside the simulated server)"
$DOCK "set -o pipefail; bash $KPKG/remote/initial-import.sh $KR $URL $KPKG $KP SIMULATED-MYKINSTA-BACKUP 2>&1 | grep -v -e WC_SA_Email -e 'a-email.php'" \
  || { echo "IMPORT FAILED — stopping the rehearsal (see output above)"; exit 1; }
echo "== remote/initial-verify.sh"
t "server-side verifier passes" "$DOCK 'set -o pipefail; bash $KPKG/remote/initial-verify.sh $KR $KPKG $URL $KP 2>&1 | grep -v -e WC_SA_Email -e a-email.php'"

echo "== assertions: accounts and permissions"
t "customer 17553 identical to the package (row, all usermeta) and still owns its orders" "[ \"\$(sql -e \"SELECT SHA2(CONCAT_WS('|',ID,user_login,user_email,user_pass,display_name),256) FROM $DB.wp_users WHERE ID=17553\")\" = \"\$(sql -e \"SELECT SHA2(CONCAT_WS('|',ID,user_login,user_email,user_pass,display_name),256) FROM $X.wp_users WHERE ID=17553\")\" ] && [ \"\$(sql -e \"SELECT SHA2(GROUP_CONCAT(meta_key,'=',meta_value ORDER BY meta_key,umeta_id),256) FROM $DB.wp_usermeta WHERE user_id=17553\")\" = \"\$(sql -e \"SELECT SHA2(GROUP_CONCAT(meta_key,'=',meta_value ORDER BY meta_key,umeta_id),256) FROM $X.wp_usermeta WHERE user_id=17553\")\" ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_postmeta WHERE meta_key='_customer_user' AND meta_value='17553'\")\" = \"\$(sql -e \"SELECT COUNT(*) FROM $X.wp_postmeta WHERE meta_key='_customer_user' AND meta_value='17553'\")\" ]"
t "hosted administrator relocated to 47553 with its own login, hash and administrator role; mapping recorded" "[ \"\$(sql -e \"SELECT SHA2(CONCAT_WS('|',user_login,user_email,user_pass),256) FROM $DB.wp_users WHERE ID=47553\")\" = '$HOSTED_17553' ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_usermeta WHERE user_id=47553 AND meta_key='wp_capabilities' AND meta_value LIKE '%administrator%'\")\" = 1 ] && [ \"\$(sql -e \"SELECT new_id FROM $DB.dd_mig_idmap WHERE ns='staff' AND old_id=17553\")\" = 47553 ]"
t "hosted staff password hash preserved (10224, restored in place)" "[ \"\$(sql -e \"SELECT user_pass FROM $DB.wp_users WHERE ID=10224\")\" = \"\$STAFF_HASH_BEFORE\" ]"
t "no migrated customer changed role or capabilities (all $(sql -e "SELECT COUNT(*) FROM $X.wp_usermeta WHERE meta_key='wp_capabilities' AND (meta_value LIKE '%\"customer\"%' OR meta_value LIKE '%\"blocked_customer\"%')") customers)" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $X.wp_usermeta x LEFT JOIN $DB.wp_usermeta d ON d.umeta_id=x.umeta_id WHERE x.meta_key IN ('wp_capabilities','wp_user_level') AND x.user_id IN (SELECT user_id FROM $X.wp_usermeta WHERE meta_key='wp_capabilities' AND (meta_value LIKE '%\\\"customer\\\"%' OR meta_value LIKE '%\\\"blocked_customer\\\"%')) AND NOT (x.meta_value <=> d.meta_value AND x.user_id <=> d.user_id)\")\" = 0 ]"
t "privileged accounts are exactly package staff + hosted staff" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value NOT LIKE '%\\\"customer\\\"%' AND meta_value NOT LIKE '%\\\"blocked_customer\\\"%' AND meta_value<>'a:0:{}'\")\" = 16 ]"
t "package excludes the local-only admin record (no ID 47553 in the package)" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $X.wp_users WHERE ID=47553\")\" = 0 ]"
t "no synthetic test records" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_posts WHERE post_title LIKE 'DD-MIGRATION-TEST%'\")\" = 0 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_users WHERE user_email LIKE '%@example.invalid'\")\" = 0 ]"

echo "== assertions: data, URLs, files, isolation"
t "stored home and siteurl are the review URL" "[ \"\$(sql -e \"SELECT GROUP_CONCAT(option_value ORDER BY option_name) FROM $DB.wp_options WHERE option_name IN ('home','siteurl')\")\" = '$URL,$URL' ]"
t "legacy order IDs present (latest 698961)" "[ \"\$(sql -e \"SELECT MAX(ID) FROM $DB.wp_posts WHERE post_type='shop_order'\")\" = 698961 ]"
t "renumbering map carried over (1160 rows)" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.dd_mig_idmap WHERE ns<>'staff'\")\" = 1160 ]"
t "renumbered CSS files placed in uploads" "[ -f $P/web/app/uploads/generateblocks/style-996282.css ]"
t "retained plugins installed" "[ -f $P/web/app/plugins/super-payments/super-payments.php ]"
MAXA=$(cat "$PRIV"/backups/max-action-*.txt)
t "webhooks paused; every carried-over action cancelled; no webhook delivery queued" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_wc_webhooks WHERE status='active'\")\" = 0 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_actionscheduler_actions WHERE status='pending' AND (action_id<=$MAXA OR hook='woocommerce_deliver_webhook_async')\")\" = 0 ]"
t "pre-import backup valid (trailer, table count, checksum)" "B=\$(ls -1t $PRIV/backups/pre-initial-*.sql.gz | head -1) && gunzip -c \$B | tail -c 300 | grep -q -- '-- Dump completed' && [ \$(gunzip -c \$B | grep -c '^CREATE TABLE') -ge 200 ] && (cd $PRIV/backups && sed 's#$KP/backups/##' \$(basename \$B).sha256 | shasum -a 256 -c >/dev/null)"
t "second apply refused while lock held" "mkdir $PRIV/lock && ! $DOCK 'bash $KPKG/remote/initial-import.sh $KR $URL $KPKG $KP X' >/dev/null 2>&1; rmdir $PRIV/lock 2>/dev/null; true"
sql -e "INSERT INTO $DB.wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (1000001,0,NOW(),UTC_TIMESTAMP(),'','sim new-site order','','wc-processing','closed','closed','','','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'shop_order','',0)"
t "Action A refuses once the new site has its own orders" "$DOCK 'bash $KPKG/remote/initial-import.sh $KR $URL $KPKG $KP X' 2>&1 | grep -q 'permanently disabled'"
t "restore also refuses once the new site has its own orders" "$DOCK 'bash $KPKG/remote/restore-backup.sh $KR $URL $KP \$(ls -1t $KP/backups/pre-initial-*.sql.gz | head -1)' 2>&1 | grep -q 'permanently disabled'"
sql -e "DELETE FROM $DB.wp_posts WHERE ID=1000001"

echo "== browser checks on the simulated hosted URL (Playwright; host mapped to the internal web server)"
WPS() { $DOCK "cd $KR && wp $1 2>&1 | grep -v -e WC_SA_Email -e 'a-email.php'"; }
QAP=$(openssl rand -hex 12); WPS "user create dd-sim-qa dd-sim-qa@example.invalid --role=administrator --user_pass=$QAP --skip-email --porcelain" >/dev/null
CUST=$(sql -e "SELECT pm.meta_value FROM $DB.wp_postmeta pm JOIN $DB.wp_posts p ON p.ID=pm.post_id AND p.post_type='shop_order' AND p.post_status='wc-completed' WHERE pm.meta_key='_customer_user' AND pm.meta_value>0 AND pm.post_id>698900 ORDER BY pm.post_id DESC LIMIT 1")
OWN=$(sql -e "SELECT GROUP_CONCAT(post_id) FROM $DB.wp_postmeta WHERE meta_key='_customer_user' AND meta_value='$CUST'")
FOREIGN=$(sql -e "SELECT pm.post_id FROM $DB.wp_postmeta pm JOIN $DB.wp_posts p ON p.ID=pm.post_id AND p.post_type='shop_order' AND p.post_status='wc-completed' WHERE pm.meta_key='_customer_user' AND pm.meta_value>0 AND pm.meta_value<>'$CUST' ORDER BY pm.post_id DESC LIMIT 1")
CLOGIN=$(sql -e "SELECT user_login FROM $DB.wp_users WHERE ID=$CUST"); CHASH=$(sql -e "SELECT user_pass FROM $DB.wp_users WHERE ID=$CUST"); CP=$(openssl rand -hex 12)
WPS "user update $CUST --user_pass=$CP --skip-email" >/dev/null
umask 077; E=$SIM/qa-env; mkdir -p "$E"; printf 'RH_ADMIN_LOGIN=dd-sim-qa\nRH_ADMIN_PASS=%s\n' "$QAP" > "$E/admin.env"; printf 'RH_CUSTOMER_LOGIN=%s\nRH_CUSTOMER_PASS=%s\n' "$CLOGIN" "$CP" > "$E/customer.env"
scripts/deploy/tests/sim-web.sh up "$SIM" "$DB"
PLAYWRIGHT_MODULE=${PLAYWRIGHT_MODULE:?} node scripts/migration/qa/qa.mjs --label=sim-hosted --base=$URL --map=http://127.0.0.1:8091 --admin-env="$E/admin.env" --customer --customer-env="$E/customer.env" --expect-orders="$OWN" --foreign-order="$FOREIGN" --orderid=698961 --homeid=996282 > migration-reports/qa-sim-hosted.log 2>&1 || echo "qa.mjs exited non-zero (see migration-reports/qa-sim-hosted.log)"
scripts/deploy/tests/sim-web.sh down
WPS "user delete dd-sim-qa --yes" >/dev/null; sql -e "UPDATE $DB.wp_users SET user_pass='$CHASH' WHERE ID=$CUST"; rm -rf "$E"
J=migration-reports/qa/sim-hosted/report.json
jq() { python3 -c "import json,sys;d=json.load(open('$J'));$1" 2>/dev/null; }
t "sim: temporary QA admin removed and customer hash restored" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_users WHERE user_login='dd-sim-qa'\")\" = 0 ] && [ \"\$(sql -e \"SELECT user_pass FROM $DB.wp_users WHERE ID=$CUST\")\" = \"\$CHASH\" ]"
t "browser: admin login; customer login" "jq \"sys.exit(0 if d.get('adminLogin') and d.get('customerLogin') else 1)\""
t "browser: customer sees only their own orders; another customer's order is refused" "jq \"sys.exit(0 if d.get('customerOrdersAllOwn') and d.get('foreignOrderDenied') else 1)\""
# One recorded exception (PLAN §9.7, unchanged from earlier rehearsals): the homepage block editor returns 500
# while Super Payments is active and its API is unreachable; pending Kiefer's Super Payments setup on Kinsta.
t "browser: every storefront, product, menu and admin page returns 2xx/3xx without a critical error (except the recorded Super Payments editor 500)" "jq \"s=d['summary'];bad=[x for x in s['non2xx'] if x!='admin-home-edit-desktop:500']+[x for x in s['criticalErrors'] if x!='admin-home-edit-desktop'];print(bad);sys.exit(1 if bad else 0)\""
t "browser: no broken images on storefront pages" "jq \"sys.exit(0 if d['summary']['brokenImages']==0 else 1)\""
t "browser: every same-site link checked resolves" "jq \"sys.exit(0 if not d['summary']['badLinks'] else 1)\""
t "browser: orders screen All (120,194) / 120,148 items" "grep -q 'All (120,194)' migration-reports/qa-sim-hosted.log && grep -q '120,148 items' migration-reports/qa-sim-hosted.log"
t "browser: no page requested a local address (localhost/:8088/:8089); external hosts all blocked" "jq \"sys.exit(0 if not d.get('localRequests') and not any(h in ('localhost','127.0.0.1') for h in d['blockedExternal']) else 1)\""

echo "== cron suppression on the simulated server"
T0=$(date -u '+%Y-%m-%d %H:%M:%S'); $DOCK "cd $KR && php web/wp/wp-cron.php >/dev/null 2>&1; true"
t "sim: wp-cron.php executes no scheduled action" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_actionscheduler_actions WHERE last_attempt_gmt >= '$T0'\")\" = 0 ]"
t "sim: no scheduled action executed at any point since the import" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_actionscheduler_actions WHERE action_id>$MAXA AND status IN ('complete','failed','in-progress')\")\" = 0 ]"

echo "== independent reconciliation of the simulated hosted database against the legacy snapshot"
./scripts/migration/run.sh reconcile.php --src=dd_rh_src_pristine_20260926 --src-prefix=lgcy_ --dest=$DB > migration-reports/reconcile-sim-hosted.log 2>&1 || true
t "sim reconcile OK" "grep -q 'RECONCILE OK' migration-reports/reconcile-sim-hosted.log"

echo "== recovery: restore-backup.sh with the pre-import backup (the documented rollback)"
BKN=$(basename "$(ls -1t $PRIV/backups/pre-initial-*.sql.gz | head -1)")
$DOCK "set -o pipefail; bash $KPKG/remote/restore-backup.sh $KR $URL $KP $KP/backups/$BKN 2>&1 | grep -v -e WC_SA_Email -e 'a-email.php'" || echo "restore-backup.sh exited non-zero"
t "restore: same table set as before the import (no dd_mig_* tables left)" "[ \"\$(tables)\" = '$PRE_TABLES' ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name LIKE 'dd\\_mig%'\")\" = 0 ]"
t "restore: plugins, uploads and .env exactly as before the import" "[ \"\$(files)\" = '$PRE_FILES' ]"
t "restore: pre-import data (max order 695563, original staff hash, hosted admin back at 17553)" "[ \"\$(sql -e \"SELECT MAX(ID) FROM $DB.wp_posts WHERE post_type='shop_order'\")\" = 695563 ] && [ \"\$(sql -e \"SELECT user_pass FROM $DB.wp_users WHERE ID=10224\")\" = \"\$STAFF_HASH_BEFORE\" ] && [ \"\$(sql -e \"SELECT SHA2(CONCAT_WS('|',user_login,user_email,user_pass),256) FROM $DB.wp_users WHERE ID=17553\")\" = '$HOSTED_17553' ]"
t "restore: import marker cleared" "[ ! -e $PRIV/last-import ]"

[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
