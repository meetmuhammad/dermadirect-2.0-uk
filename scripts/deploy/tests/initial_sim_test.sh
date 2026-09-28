#!/bin/sh
# Local end-to-end rehearsal of Action A against a simulated Kinsta review site:
#   code tree  = scratch "kinsta-sim/public" (deployed with deploy.sh code)
#   database   = dd_rh_test_hostedsim (restored from the original, pre-migration destination dump)
#   server     = wordpress:cli container on the egress-blocked network, running the REAL
#                remote/initial-import.sh and remote/initial-verify.sh from the package.
#   scripts/deploy/tests/initial_sim_test.sh <sim-root> <package-dir> <code-artifact.tar.gz>
set -eu
cd "$(dirname "$0")/../../.."
SIM=$1; PKG=$2; ART=$3; P=$SIM/public; PRIV=$SIM/private/dd-deploy
URL=https://dermadirect20uk.kinsta.cloud; DB=dd_rh_test_hostedsim
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
echo "== deploy code (Action B) to the simulated site"
scripts/deploy/deploy.sh code "local:$P" "$ART" | tail -1
echo "== simulated hosted .env (staging, harness on, review URL)"
{ grep -E '^(DB_USER|DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)=' .env
  printf "WP_ENV='staging'\nWP_HOME='%s'\nWP_SITEURL='%s/wp'\nDB_NAME='%s'\nDB_HOST='db'\nDB_PREFIX='wp_'\nDD_SAFETY_HARNESS='on'\nDISABLE_WP_CRON='true'\n" "$URL" "$URL" "$DB"; } > "$P/.env"
for d in web/app/plugins/*/; do n=$(basename "$d"); case $n in super-payments|woocommerce-gateway-rules-manager|product-batch-expiry-tracking-for-woocommerce|woocommerce-back-in-stock-notifications|wpgens-refer-a-friend-premium|advanced-shipment-tracking-pro) rm -rf "$P/web/app/plugins/$n";; *) [ -e "$P/web/app/plugins/$n" ] || cp -Rp "$d" "$P/web/app/plugins/";; esac; done
STAFF_HASH_BEFORE=$(sql -e "SELECT user_pass FROM $DB.wp_users WHERE ID=10224")
rm -rf "$PRIV/incoming/pkg"; mkdir -p "$PRIV/incoming"; cp -Rp "$PKG" "$PRIV/incoming/pkg"
# Test-container only: the local MariaDB 10.11 has no TLS and the wordpress:cli client requires it by default.
mkdir -p "$SIM/conf"; printf '[client]\nskip-ssl\n' > "$SIM/conf/nossl.cnf"
DOCK="docker run --rm --network dermadirect20uk_internal -v $SIM:/sim -v $SIM/conf/nossl.cnf:/etc/my.cnf.d/zz-nossl.cnf:ro -w /sim/public --entrypoint bash wordpress:cli -c"
echo "== remote/initial-import.sh (inside the simulated server)"
$DOCK "bash /sim/private/dd-deploy/incoming/pkg/remote/initial-import.sh /sim/public $URL /sim/private/dd-deploy/incoming/pkg /sim/private/dd-deploy SIMULATED-MYKINSTA-BACKUP 2>&1 | grep -v -e WC_SA_Email -e 'a-email.php'"
echo "== remote/initial-verify.sh"
$DOCK "bash /sim/private/dd-deploy/incoming/pkg/remote/initial-verify.sh /sim/public /sim/private/dd-deploy/incoming/pkg 2>&1 | grep -v -e WC_SA_Email -e 'a-email.php'"
echo "== assertions"
fails=0; t() { if eval "$2"; then echo "PASS $1"; else echo "FAIL $1"; fails=$((fails+1)); fi; }
t "hosted staff password hash preserved (not the local override)" "[ \"\$(sql -e \"SELECT user_pass FROM $DB.wp_users WHERE ID=10224\")\" = \"\$STAFF_HASH_BEFORE\" ]"
t "local-only admin 47553 not imported" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_users WHERE ID=47553\")\" = 0 ]"
t "no synthetic test records" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_posts WHERE post_title LIKE 'DD-MIGRATION-TEST%'\")\" = 0 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_users WHERE user_email LIKE '%@example.invalid'\")\" = 0 ]"
t "home is the review URL" "[ \"\$(sql -e \"SELECT option_value FROM $DB.wp_options WHERE option_name='home'\")\" = '$URL' ]"
t "no localhost URL left (non-GUID)" "[ -z \"\$(sql -e \"SELECT 1 FROM $DB.wp_postmeta WHERE meta_value LIKE '%localhost:808%' LIMIT 1\")\" ] && [ -z \"\$(sql -e \"SELECT 1 FROM $DB.wp_options WHERE option_value LIKE '%localhost:808%' LIMIT 1\")\" ]"
t "legacy order IDs present (latest 698961)" "[ \"\$(sql -e \"SELECT MAX(ID) FROM $DB.wp_posts WHERE post_type='shop_order'\")\" = 698961 ]"
t "renumbering map carried over" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.dd_mig_idmap\")\" -ge 1160 ]"
t "renumbered CSS files placed in uploads" "[ -f $P/web/app/uploads/generateblocks/style-996282.css ]"
t "retained plugins installed" "[ -f $P/web/app/plugins/super-payments/super-payments.php ]"
t "webhooks paused, no pending deliveries" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_wc_webhooks WHERE status='active'\")\" = 0 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_actionscheduler_actions WHERE status='pending' AND hook='woocommerce_deliver_webhook_async'\")\" = 0 ]"
t "pre-import backup exists and is valid" "ls $PRIV/backups/pre-initial-*.sql.gz >/dev/null && gzip -t \$(ls -1t $PRIV/backups/pre-initial-*.sql.gz | head -1)"
t "second apply refused while lock held" "mkdir $PRIV/lock && ! $DOCK 'bash /sim/private/dd-deploy/incoming/pkg/remote/initial-import.sh /sim/public $URL /sim/private/dd-deploy/incoming/pkg /sim/private/dd-deploy X' >/dev/null 2>&1; rmdir $PRIV/lock 2>/dev/null || rm -rf $PRIV/lock"
sql -e "INSERT INTO $DB.wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (1000001,0,NOW(),UTC_TIMESTAMP(),'','Order','','wc-processing','closed','closed','','order-sim','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'shop_order','',0)"
t "Action A refuses once the new site has its own orders" "$DOCK 'bash /sim/private/dd-deploy/incoming/pkg/remote/initial-import.sh /sim/public $URL /sim/private/dd-deploy/incoming/pkg /sim/private/dd-deploy X' 2>&1 | grep -q 'permanently disabled'"
sql -e "DELETE FROM $DB.wp_posts WHERE ID=1000001"

echo "== browser checks on the simulated hosted URL (Playwright; host mapped to a temporary internal web server)"
WPS() { $DOCK "cd /sim/public && wp $1 2>&1 | grep -v -e WC_SA_Email -e 'a-email.php'"; }
QAP=$(openssl rand -hex 12); WPS "user create dd-sim-qa dd-sim-qa@example.invalid --role=administrator --user_pass=$QAP --skip-email --porcelain" >/dev/null
CUST=$(sql -e "SELECT pm.meta_value FROM $DB.wp_postmeta pm JOIN $DB.wp_posts p ON p.ID=pm.post_id AND p.post_type='shop_order' AND p.post_status='wc-completed' WHERE pm.meta_key='_customer_user' AND pm.meta_value>0 AND pm.post_id>698900 ORDER BY pm.post_id DESC LIMIT 1")
CLOGIN=$(sql -e "SELECT user_login FROM $DB.wp_users WHERE ID=$CUST"); CHASH=$(sql -e "SELECT user_pass FROM $DB.wp_users WHERE ID=$CUST"); CP=$(openssl rand -hex 12)
WPS "user update $CUST --user_pass=$CP --skip-email" >/dev/null
umask 077; E=$SIM/qa-env; mkdir -p "$E"; printf 'RH_ADMIN_LOGIN=dd-sim-qa\nRH_ADMIN_PASS=%s\n' "$QAP" > "$E/admin.env"; printf 'RH_CUSTOMER_LOGIN=%s\nRH_CUSTOMER_PASS=%s\n' "$CLOGIN" "$CP" > "$E/customer.env"
scripts/deploy/tests/sim-web.sh up "$SIM" "$DB"
PLAYWRIGHT_MODULE=${PLAYWRIGHT_MODULE:?} node scripts/migration/qa/qa.mjs --label=sim-hosted --base=$URL --map=http://127.0.0.1:8091 --admin-env="$E/admin.env" --customer --customer-env="$E/customer.env" --orderid=698961 --homeid=996282 > migration-reports/qa-sim-hosted.log 2>&1 || true
scripts/deploy/tests/sim-web.sh down
WPS "user delete dd-sim-qa --yes" >/dev/null; sql -e "UPDATE $DB.wp_users SET user_pass='$CHASH' WHERE ID=$CUST"; rm -rf "$E"
t "sim: temporary QA admin removed and customer hash restored" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_users WHERE user_login='dd-sim-qa'\")\" = 0 ] && [ \"\$(sql -e \"SELECT user_pass FROM $DB.wp_users WHERE ID=$CUST\")\" = \"\$CHASH\" ]"
t "sim browser: no failing page except the known offline Super Payments editor 500" "python3 -c \"import json,sys;d=json.load(open('migration-reports/qa/sim-hosted/report.json'));bad=[x for x in d['summary']['non2xx']+d['summary']['criticalErrors'] if not x.startswith('admin-home-edit')];print(bad);sys.exit(1 if bad else 0)\""
t "sim browser: admin login, customer login + order history" "grep -q 'adminLogin true' migration-reports/qa-sim-hosted.log && grep -q 'customerLogin true' migration-reports/qa-sim-hosted.log"
t "sim browser: orders screen All (120,194) / 120,148 items" "grep -q 'All (120,194)' migration-reports/qa-sim-hosted.log && grep -q '120,148 items' migration-reports/qa-sim-hosted.log"
t "sim browser: no request left for the internet" "! grep -q 'kinsta.cloud' migration-reports/qa/sim-hosted/report.json || python3 -c \"import json,sys;d=json.load(open('migration-reports/qa/sim-hosted/report.json'));sys.exit(0 if 'dermadirect20uk.kinsta.cloud' not in d['blockedExternal'] else 1)\""

echo "== cron suppression on the simulated server"
T0=$(date -u '+%Y-%m-%d %H:%M:%S'); $DOCK "cd /sim/public && php web/wp/wp-cron.php >/dev/null 2>&1; true"
t "sim: wp-cron.php executes no scheduled action" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_actionscheduler_actions WHERE last_attempt_gmt >= '$T0'\")\" = 0 ]"

echo "== independent reconciliation of the simulated hosted database against the legacy snapshot"
./scripts/migration/run.sh reconcile.php --src=dd_rh_src_pristine_20260926 --src-prefix=lgcy_ --dest=$DB > migration-reports/reconcile-sim-hosted.log 2>&1
t "sim reconcile OK" "grep -q 'RECONCILE OK' migration-reports/reconcile-sim-hosted.log"

echo "== recovery: restore the pre-import backup taken by initial-import.sh (the documented rollback)"
BK=$(ls -1t $PRIV/backups/pre-initial-*.sql.gz | head -1); BKN=$(basename "$BK")
$DOCK "cd /sim/public && gunzip -c /sim/private/dd-deploy/backups/$BKN | wp db import - >/dev/null 2>&1"
t "restore returns the pre-import site (max order 695563, original staff hash)" "[ \"\$(sql -e \"SELECT MAX(ID) FROM $DB.wp_posts WHERE post_type='shop_order'\")\" = 695563 ] && [ \"\$(sql -e \"SELECT user_pass FROM $DB.wp_users WHERE ID=10224\")\" = \"\$STAFF_HASH_BEFORE\" ]"

[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
