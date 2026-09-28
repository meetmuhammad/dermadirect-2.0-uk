#!/bin/sh
# Builds the private Action A data package from the clean canonical local site (dermadirect_local).
#   scripts/deploy/package-initial.sh <label>      -> recovery/deploy/initial-<label>/
# Works on an export copy (dd_rh_test_export); dermadirect_local is only read.
# Transform: remove local-only records (local admin 47553 + its auto-draft), drop scratch tables,
# neutralise webhooks/queued actions, clear transients/sessions, rewrite localhost URLs to the review
# URL (serialization-safe, per table) and verify no local URL, test marker or local path remains.
# Package: db.sql.gz, uploads delta (renumbered files), retained-plugin bundle, remote scripts, manifest.
set -eu
LABEL=${1:?label}
cd "$(dirname "$0")/../.."
. ./.deploy-targets
URL=$KINSTA_REVIEW_URL; FROM=http://localhost:8088; X=dd_rh_test_export
OUT=recovery/deploy/initial-$LABEL; mkdir -p "$OUT/uploads" "$OUT/remote"; chmod 700 recovery/deploy "$OUT"
C=dermadirect20uk-db-1
sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
wpx() { DB_NAME=$X docker compose --profile cli run --rm -T --no-deps --entrypoint sh wpcli -c "php -d memory_limit=4G /usr/local/bin/wp $* --skip-plugins --skip-themes 2>&1 | grep -v -e WC_SA_Email -e FS_Plugin" 2>/dev/null; }
R=./scripts/migration/run.sh
if [ "${RESUME_AT:-}" = residual ] || [ "${RESUME_AT:-}" = after-dump ]; then
  # Resume after an interruption past the URL rewrite: only if the scratch copy is fully transformed.
  [ "$(sql -e "SELECT option_value FROM $X.wp_options WHERE option_name='home'")" = "$URL" ] || { echo "cannot resume: $X is not fully transformed"; exit 1; }
  [ -z "$(sql -e "SELECT 1 FROM $X.wp_postmeta WHERE meta_value LIKE '%$FROM%' LIMIT 1")" ] || { echo "cannot resume: $X still has $FROM values"; exit 1; }
  echo "== resuming at residual checks ($X already transformed)"
else
echo "== export copy of dermadirect_local"
./scripts/migration/snapshot.sh dump dermadirect_local "$OUT/source-dermadirect_local.sql.gz"
./scripts/migration/snapshot.sh restore "$OUT/source-dermadirect_local.sql.gz" $X
echo "== remove local-only records"
sql -e "DELETE FROM $X.wp_usermeta WHERE user_id=47553; DELETE FROM $X.wp_users WHERE ID=47553 AND user_login='dermadirect-local-admin';
        DELETE FROM $X.wp_postmeta WHERE post_id IN (SELECT ID FROM $X.wp_posts WHERE post_author=47553 AND post_status='auto-draft');
        DELETE FROM $X.wp_posts WHERE post_author=47553 AND post_status='auto-draft';
        UPDATE $X.wp_posts SET post_author=0 WHERE post_author=47553;
        DELETE FROM $X.wp_usermeta WHERE meta_key IN ('session_tokens');
        DELETE FROM $X.wp_options WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name IN ('dd_safety_blocked_log','fs_active_plugins','wp_mail_smtp_email_sending_debug');
        TRUNCATE $X.wp_woocommerce_sessions;"
for t in $(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$X' AND table_name LIKE 'dd\\_mig\\_h\\_%'"); do sql -e "DROP TABLE $X.\`$t\`"; done
echo "== neutralise integrations"; $R neutralize.php apply --dest=$X
echo "== URL rewrite $FROM -> $URL"
TABLES=$(sql -e "SELECT DISTINCT table_name FROM information_schema.columns WHERE table_schema='$X' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext') AND table_name LIKE 'wp\\_%'")
for t in $TABLES; do
  hit=$(sql -e "SELECT CONCAT('SELECT 1 FROM \`$X\`.\`$t\` WHERE ', GROUP_CONCAT(CONCAT('\`',column_name,'\` LIKE ''%$FROM%''') SEPARATOR ' OR '), ' LIMIT 1') FROM information_schema.columns WHERE table_schema='$X' AND table_name='$t' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext')" | sql)
  [ -n "$hit" ] && wpx search-replace "'$FROM'" "'$URL'" "$t" --all-tables --precise --report-changed-only | grep -E "Success|Error" | sed "s/^/   $t: /"
done
fi
if [ "${RESUME_AT:-}" = after-dump ]; then
  gunzip -c "$OUT/db.sql.gz" | tail -c 300 | grep -q -- '-- Dump completed' && [ -f "$OUT/residuals-reported.tsv" ] && [ ! -f "$OUT/residuals.txt" ] || { echo "cannot resume after dump: dump missing/corrupt or strict residuals recorded"; exit 1; }
  echo "== resuming after verified dump ($(du -h "$OUT/db.sql.gz" | cut -f1))"
else
echo "== residual checks"
# STRICT (must be empty): legacy-owned commerce records carried by the migration.
# REPORTED (allowed, listed in residuals-reported.tsv): pre-existing values in destination-owned
# settings/content and in tables of legacy-only plugins the new site does not run (already on Kinsta).
txtcols() { sql -e "SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\` LIKE ''%$2%''') SEPARATOR ' OR ') FROM information_schema.columns WHERE table_schema='$X' AND table_name='$1' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext')"; }
COMMERCE="SELECT ID FROM $X.wp_posts WHERE post_type IN ('shop_order','shop_order_refund','shop_coupon')"
CUSTOMERS="SELECT user_id FROM $X.wp_usermeta WHERE meta_key='wp_capabilities' AND (meta_value LIKE '%\"customer\"%' OR meta_value LIKE '%\"blocked_customer\"%')"
KEYED="wp_wc_orders wp_wc_order_addresses wp_wc_orders_meta wp_wc_order_operational_data wp_woocommerce_order_items wp_woocommerce_order_itemmeta wp_woocommerce_payment_tokens wp_woocommerce_payment_tokenmeta wp_webis_pbet wp_webis_pbet_order wp_webis_pbet_order_item wp_woocommerce_bis_notifications wp_woocommerce_bis_notificationsmeta wp_woocommerce_bis_activity wp_gens_raf wp_gens_rafmeta wp_gens_raf_guest_referrals wp_wt_sc_coupon_lookup wp_wdr_order_item_discounts wp_pp_order_actions_log wp_trackship_shipment wp_trackship_shipment_meta wp_zorem_email_sms_log wp_stock_log wp_wc_order_stats wp_wc_order_product_lookup wp_wc_order_tax_lookup wp_wc_order_coupon_lookup wp_wc_customer_lookup"
: > "$OUT/residuals-reported.tsv"
for needle in "$FROM" "localhost:8089" "httpsdermadirectcom.local" "/var/www/html" "DD-MIGRATION-TEST" "@example.invalid"; do
  n=0
  n=$((n + $(sql -e "SELECT COUNT(*) FROM $X.wp_posts WHERE ID IN ($COMMERCE) AND ($(txtcols wp_posts "$needle"))")))
  n=$((n + $(sql -e "SELECT COUNT(*) FROM $X.wp_postmeta WHERE post_id IN ($COMMERCE) AND meta_value LIKE '%$needle%'")))
  n=$((n + $(sql -e "SELECT COUNT(*) FROM $X.wp_comments WHERE comment_post_ID IN ($COMMERCE) AND ($(txtcols wp_comments "$needle"))")))
  n=$((n + $(sql -e "SELECT COUNT(*) FROM $X.wp_users WHERE ID IN ($CUSTOMERS) AND ($(txtcols wp_users "$needle"))")))
  n=$((n + $(sql -e "SELECT COUNT(*) FROM $X.wp_usermeta WHERE user_id IN ($CUSTOMERS) AND meta_value LIKE '%$needle%'")))
  for t in $KEYED; do c=$(txtcols $t "$needle"); [ -n "$c" ] && [ "$c" != NULL ] && n=$((n + $(sql -e "SELECT COUNT(*) FROM $X.\`$t\` WHERE $c"))); done
  if [ "$n" -gt 0 ]; then echo "   STRICT RESIDUAL '$needle' in legacy-owned records: $n"; echo "$needle $n" >> "$OUT/residuals.txt"; else echo "   strict none: $needle"; fi
  Q=$(sql -e "SELECT GROUP_CONCAT(CONCAT('SELECT ''',table_name,'.',column_name,''' c, COUNT(*) n FROM \`$X\`.\`',table_name,'\` WHERE \`',column_name,'\` LIKE ''%$needle%''') SEPARATOR ' UNION ALL ') FROM information_schema.columns WHERE table_schema='$X' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext') AND table_name LIKE 'wp\\_%'")
  echo "SELECT * FROM ($Q) x WHERE n>0" | sql | awk -v n="$needle" '{print n "\t" $0}' >> "$OUT/residuals-reported.tsv"
done
echo "== dump package database"
docker exec "$C" sh -c 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --skip-triggers "$1"' sh $X | gzip -6 > "$OUT/db.sql.gz"
WANT=$(sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$X' AND table_type='BASE TABLE'")
gunzip -c "$OUT/db.sql.gz" | tail -c 300 | grep -q -- '-- Dump completed' && [ "$(gunzip -c "$OUT/db.sql.gz" | grep -c '^CREATE TABLE')" -ge "$WANT" ] || { echo "PACKAGE DUMP INCOMPLETE"; rm -f "$OUT/db.sql.gz"; exit 1; }
fi
echo "== uploads delta (files renamed by renumbering)"
sql -e "SELECT new_id FROM $X.dd_mig_idmap WHERE ns='posts'" | while read id; do f=web/app/uploads/generateblocks/style-$id.css; if [ -f "$f" ]; then mkdir -p "$OUT/uploads/generateblocks" && cp -p "$f" "$OUT/uploads/generateblocks/"; fi; done
echo "== retained legacy plugins (private bundle)"
tar -C web/app/plugins -czf "$OUT/plugins-retained.tar.gz" super-payments woocommerce-gateway-rules-manager product-batch-expiry-tracking-for-woocommerce woocommerce-back-in-stock-notifications wpgens-refer-a-friend-premium advanced-shipment-tracking-pro
cp scripts/deploy/remote/*.sh "$OUT/remote/"
echo "== manifest"
COUNTS=$(sql -e "SELECT CONCAT('{\"orders_by_status\":{', GROUP_CONCAT(CONCAT('\"',post_type,'/',post_status,'\":',n)), '}') FROM (SELECT post_type, post_status, COUNT(*) n FROM $X.wp_posts WHERE post_type IN ('shop_order','shop_order_refund') GROUP BY 1,2) z")
EXTRA=$(sql -e "SELECT CONCAT(',\"users\":',(SELECT COUNT(*) FROM $X.wp_users),',\"products\":',(SELECT COUNT(*) FROM $X.wp_posts WHERE post_type IN ('product','product_variation')),',\"coupons\":',(SELECT COUNT(*) FROM $X.wp_posts WHERE post_type='shop_coupon'),',\"idmap\":',(SELECT COUNT(*) FROM $X.dd_mig_idmap),',\"max_order_id\":',(SELECT MAX(ID) FROM $X.wp_posts WHERE post_type='shop_order'),',\"active_webhooks\":',(SELECT COUNT(*) FROM $X.wp_wc_webhooks WHERE status='active'),',\"pending_actions\":',(SELECT COUNT(*) FROM $X.wp_actionscheduler_actions WHERE status='pending'),'}')")
cat > "$OUT/manifest.json" <<JSON
{"label":"$LABEL","created_utc":"$(date -u +%FT%TZ)","code_commit":"$(git rev-parse HEAD)","target_url":"$URL","source_db":"dermadirect_local (via $X)",
 "legacy_snapshot":"legacy_snapshot_20260927 (DevKinsta legacy DB, latest order 2026-09-26 20:36)","counts":$COUNTS$EXTRA,
 "excluded":["local admin 47553 + auto-draft","transients, sessions, session_tokens","synthetic DD-MIGRATION-TEST records (removed before packaging)","Docker/.env/local mu-plugins (not in package)"],
 "preserved_on_import_from_hosted":["staff/admin users (non-customer roles) and their usermeta","gateway/SMTP/integration options (see remote/initial-import.sh)",".env, uploads, server plugins"]}
JSON
rm -f "$OUT/source-dermadirect_local.sql.gz" "$OUT/source-dermadirect_local.sql.gz.sha256"
(cd "$OUT" && find . -type f ! -name SHA256SUMS | sort | xargs shasum -a 256 > SHA256SUMS)
[ -f "$OUT/residuals.txt" ] && { echo "PACKAGE HAS STRICT RESIDUALS — review $OUT/residuals.txt; not usable"; exit 1; }
echo "reported (pre-existing, destination-owned or inert) residual locations: $(wc -l < "$OUT/residuals-reported.tsv") — see residuals-reported.tsv"
echo "PACKAGE READY: $OUT"; du -sh "$OUT"
