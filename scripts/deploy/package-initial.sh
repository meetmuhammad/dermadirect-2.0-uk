#!/bin/sh
# Builds the private Action A data package from a verified migrated candidate database.
#   scripts/deploy/package-initial.sh <label> --source=<candidate db> --legacy-snapshot=<dd_rh_src_* db>
#        --baseline-dir=<recovery/final-sync/<pull label>> [--uploads-dir=<dir>] [--rewrite-from=<url>]
#   -> recovery/deploy/initial-<label>/   (an existing package directory is never reused)
# Works on a per-label export copy (dd_rh_test_export_<label>); the candidate is only read.
# Transform: remove local-only records, drop scratch tables, neutralise webhooks/queued actions, clear
# transients/sessions. No URL rewrite unless --rewrite-from is given (a candidate built on a pulled hosted
# baseline already carries the review URL; commerce values are verbatim production values). Strict checks:
# no local URL, test marker or local path in legacy-owned records; legacy-owned values identical to the
# candidate's. The manifest records the real legacy snapshot and destination baseline identities, and the
# baseline fingerprint travels with the package for Action A's drift guard.
set -eu
LABEL=${1:?label}; shift
SRC=""; LEG=""; BASE=""; UPL=web/app/uploads; FROM=""
for a in "$@"; do case "$a" in --source=*) SRC=${a#*=};; --legacy-snapshot=*) LEG=${a#*=};; --baseline-dir=*) BASE=${a#*=};; --uploads-dir=*) UPL=${a#*=};; --rewrite-from=*) FROM=${a#*=};; --notes=*) NOTES=${a#*=};; *) echo "unknown option $a"; exit 2;; esac; done
cd "$(dirname "$0")/../.."
[ -n "$SRC" ] && [ -n "$LEG" ] && [ -n "$BASE" ] || { echo "needs --source=<candidate db> --legacy-snapshot=<source db> --baseline-dir=<pull dir>"; exit 2; }
for f in destination-identity.tsv dest-fingerprint.tsv; do [ -s "$BASE/$f" ] || { echo "baseline dir lacks $f (pull it with final-sync.sh pull-dest)"; exit 2; }; done
. ./.deploy-targets
URL=$KINSTA_REVIEW_URL; X=dd_rh_test_export_$(echo "$LABEL" | tr -c 'a-z0-9_\n' '_')
OUT=recovery/deploy/initial-$LABEL
# the manifest's code_commit must be exactly the code that built the package
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "refusing: tracked code has uncommitted changes (commit first; the manifest records the commit)"; exit 2; }
[ -z "${RESUME_AT:-}" ] && [ -e "$OUT" ] && { echo "refusing: $OUT exists (packages are never rebuilt in place; use a new label)"; exit 2; }
mkdir -p "$OUT/uploads" "$OUT/remote"; chmod 700 recovery/deploy "$OUT"
C=dermadirect20uk-db-1
sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
wpx() { DB_NAME=$X docker compose --profile cli run --rm -T --no-deps --entrypoint sh wpcli -c "php -d memory_limit=4G /usr/local/bin/wp $* --skip-plugins --skip-themes 2>&1 | grep -v -e WC_SA_Email -e FS_Plugin" 2>/dev/null; }
R=./scripts/migration/run.sh
if [ "${RESUME_AT:-}" = residual ] || [ "${RESUME_AT:-}" = after-dump ]; then
  # Resume after an interruption past the URL rewrite: only if the scratch copy is fully transformed.
  [ "$(sql -e "SELECT option_value FROM $X.wp_options WHERE option_name='home'")" = "$URL" ] || { echo "cannot resume: $X is not fully transformed"; exit 1; }
  [ -z "$FROM" ] || [ -z "$(sql -e "SELECT 1 FROM $X.wp_postmeta WHERE meta_value LIKE '%$FROM%' LIMIT 1")" ] || { echo "cannot resume: $X still has $FROM values"; exit 1; }
  echo "== resuming at residual checks ($X already transformed)"
else
echo "== export copy of $SRC"
./scripts/migration/snapshot.sh dump "$SRC" "$OUT/source-candidate.sql.gz"
./scripts/migration/snapshot.sh restore "$OUT/source-candidate.sql.gz" $X
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
if [ -n "$FROM" ]; then
echo "== URL rewrite $FROM -> $URL (explicit --rewrite-from)"
TABLES=$(sql -e "SELECT DISTINCT table_name FROM information_schema.columns WHERE table_schema='$X' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext') AND table_name LIKE 'wp\\_%'")
for t in $TABLES; do
  hit=$(sql -e "SELECT CONCAT('SELECT 1 FROM \`$X\`.\`$t\` WHERE ', GROUP_CONCAT(CONCAT('\`',column_name,'\` LIKE ''%$FROM%''') SEPARATOR ' OR '), ' LIMIT 1') FROM information_schema.columns WHERE table_schema='$X' AND table_name='$t' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext')" | sql)
  [ -n "$hit" ] && wpx search-replace "'$FROM'" "'$URL'" "$t" --all-tables --precise --report-changed-only | grep -E "Success|Error" | sed "s/^/   $t: /"
done
else
  echo "== no URL rewrite (environment URLs: home/siteurl are set by initial-import.sh; content keeps the baseline's URLs)"
fi
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
# legacy-owned = records that exist in the legacy snapshot (destination-only records kept by the sync are
# destination-owned: their values are listed under REPORTED, never rewritten here)
# materialised once with primary keys (a nested IN over the snapshot re-ran per row on 11M meta rows and
# stalled the database during the 2026-10-20 rehearsal)
sql -e "DROP TABLE IF EXISTS $X.dd_pkg_commerce; CREATE TABLE $X.dd_pkg_commerce (id BIGINT UNSIGNED PRIMARY KEY) SELECT p.ID id FROM $X.wp_posts p JOIN \`$LEG\`.lgcy_posts l ON l.ID=p.ID WHERE p.post_type IN ('shop_order','shop_order_refund','shop_coupon');
  DROP TABLE IF EXISTS $X.dd_pkg_customers; CREATE TABLE $X.dd_pkg_customers (id BIGINT UNSIGNED PRIMARY KEY) SELECT DISTINCT m.user_id id FROM $X.wp_usermeta m JOIN \`$LEG\`.lgcy_users l ON l.ID=m.user_id WHERE m.meta_key='wp_capabilities' AND (m.meta_value LIKE '%\"customer\"%' OR m.meta_value LIKE '%\"blocked_customer\"%')"
COMMERCE="SELECT id FROM $X.dd_pkg_commerce"
CUSTOMERS="SELECT id FROM $X.dd_pkg_customers"
KEYED="wp_wc_orders wp_wc_order_addresses wp_wc_orders_meta wp_wc_order_operational_data wp_woocommerce_order_items wp_woocommerce_order_itemmeta wp_woocommerce_payment_tokens wp_woocommerce_payment_tokenmeta wp_webis_pbet wp_webis_pbet_order wp_webis_pbet_order_item wp_woocommerce_bis_notifications wp_woocommerce_bis_notificationsmeta wp_woocommerce_bis_activity wp_gens_raf wp_gens_rafmeta wp_gens_raf_guest_referrals wp_wt_sc_coupon_lookup wp_wdr_order_item_discounts wp_pp_order_actions_log wp_trackship_shipment wp_trackship_shipment_meta wp_zorem_email_sms_log wp_stock_log wp_wc_order_stats wp_wc_order_product_lookup wp_wc_order_tax_lookup wp_wc_order_coupon_lookup wp_wc_customer_lookup"
: > "$OUT/residuals-reported.tsv"
for needle in "localhost:808" "127.0.0.1:808" "httpsdermadirectcom.local" "/var/www/html" "DD-MIGRATION-TEST" "@example.invalid"; do
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
sql -e "DROP TABLE IF EXISTS $X.dd_pkg_commerce, $X.dd_pkg_customers"
echo "== dump package database"
docker exec "$C" sh -c 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --skip-triggers "$1"' sh $X | gzip -6 > "$OUT/db.sql.gz"
WANT=$(sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$X' AND table_type='BASE TABLE'")
gunzip -c "$OUT/db.sql.gz" | tail -c 300 | grep -q -- '-- Dump completed' && [ "$(gunzip -c "$OUT/db.sql.gz" | grep -c '^CREATE TABLE')" -ge "$WANT" ] || { echo "PACKAGE DUMP INCOMPLETE"; rm -f "$OUT/db.sql.gz"; exit 1; }
fi
echo "== legacy-owned values unchanged by packaging (emails, notes, corrected legacy domain)"
scripts/deploy/check-legacy-values.sh "$SRC" "$X" "${FROM:-$URL}" "$URL" > "$OUT/legacy-values-check.txt" || { cat "$OUT/legacy-values-check.txt"; echo "PACKAGE REFUSED: packaging changed legacy-owned values"; exit 1; }
cat "$OUT/legacy-values-check.txt"
echo "== account identity digest (for identity-based staff restore on the server; SHA-256, no personal data)"
$R restore-staff.php identity --dest=$X --out="/var/www/html/$OUT/users-identity.tsv"; chmod 600 "$OUT/users-identity.tsv"
echo "== uploads delta (files renamed by renumbering)"
sql -e "SELECT new_id FROM $X.dd_mig_idmap WHERE ns='posts'" | while read id; do f=$UPL/generateblocks/style-$id.css; if [ -f "$f" ]; then mkdir -p "$OUT/uploads/generateblocks" && cp -p "$f" "$OUT/uploads/generateblocks/"; fi; done
# shipped files get the same address rewrite as the database (plain and JSON-escaped), then a strict check
if [ -n "$FROM" ]; then
FROMJ=$(printf '%s' "$FROM" | sed 's#/#\\\\/#g'); URLJ=$(printf '%s' "$URL" | sed 's#/#\\\\/#g')
find "$OUT/uploads" -type f | while read -r f; do LC_ALL=C sed -i '' -e "s#$FROM#$URL#g" -e "s#$FROMJ#$URLJ#g" "$f"; done
fi
LEFT=$(grep -rlE 'https?:(\\?/){2}(localhost|127\.0\.0\.1)|:808[89]' "$OUT/uploads" || true)
[ -z "$LEFT" ] || { echo "PACKAGE REFUSED: local address left in shipped files: $LEFT"; exit 1; }
echo "   shipped upload files: no local address"
echo "== retained legacy plugins (private bundle)"
COPYFILE_DISABLE=1 tar --no-mac-metadata --no-xattrs -C web/app/plugins -czf "$OUT/plugins-retained.tar.gz" super-payments woocommerce-gateway-rules-manager product-batch-expiry-tracking-for-woocommerce woocommerce-back-in-stock-notifications wpgens-refer-a-friend-premium advanced-shipment-tracking-pro
cp scripts/deploy/remote/*.sh "$OUT/remote/"
echo "== manifest"
COUNTS=$(sql -e "SELECT CONCAT('{\"orders_by_status\":{', GROUP_CONCAT(CONCAT('\"',post_type,'/',post_status,'\":',n)), '}') FROM (SELECT post_type, post_status, COUNT(*) n FROM $X.wp_posts WHERE post_type IN ('shop_order','shop_order_refund') GROUP BY 1,2) z")
EXTRA=$(sql -e "SELECT CONCAT(',\"users\":',(SELECT COUNT(*) FROM $X.wp_users),',\"products\":',(SELECT COUNT(*) FROM $X.wp_posts WHERE post_type IN ('product','product_variation')),',\"coupons\":',(SELECT COUNT(*) FROM $X.wp_posts WHERE post_type='shop_coupon'),',\"idmap\":',(SELECT COUNT(*) FROM $X.dd_mig_idmap),',\"max_order_id\":',(SELECT MAX(ID) FROM $X.wp_posts WHERE post_type='shop_order'),',\"active_webhooks\":',(SELECT COUNT(*) FROM $X.wp_wc_webhooks WHERE status='active'),',\"pending_actions\":',(SELECT COUNT(*) FROM $X.wp_actionscheduler_actions WHERE status='pending'),'}')")
SNAPID=$(sql -e "SELECT CONCAT('{',GROUP_CONCAT(CONCAT('\"',k,'\":\"',REPLACE(v,'\"',''),'\"') ORDER BY k),'}') FROM \`$LEG\`.lgcy_dd_snapshot")
BASEID=$(awk -F'\t' '{gsub(/"/,"",$2); printf "%s\"%s\":\"%s\"", (NR>1?",":""), $1, $2}' "$BASE/destination-identity.tsv")
cp "$BASE/dest-fingerprint.tsv" "$OUT/destination-fingerprint.tsv"; cp "$BASE/destination-identity.tsv" "$OUT/destination-identity.tsv"
# explicit identity corrections (IDs only): mappings that are not the fixed relocation offset
sql -e "SELECT old_id, new_id, kind, reason FROM $X.dd_mig_idmap WHERE ns='posts' AND new_id <> old_id + 300000 ORDER BY old_id" > "$OUT/product-id-corrections.tsv"
PCORR=$(grep -c . "$OUT/product-id-corrections.tsv" || true)
[ -n "${NOTES:-}" ] && cp "$NOTES" "$OUT/KNOWN-ISSUES.txt"
cat > "$OUT/manifest.json" <<JSON
{"label":"$LABEL","created_utc":"$(date -u +%FT%TZ)","code_commit":"$(git rev-parse HEAD)","target_url":"$URL",
 "candidate_db":"$SRC","candidate_sha256":"$(cut -d' ' -f1 "$OUT/source-candidate.sql.gz.sha256" 2>/dev/null)",
 "legacy_snapshot":$SNAPID,"destination_baseline":{$BASEID},"url_rewrite":"${FROM:-none}","counts":$COUNTS$EXTRA,
 "excluded":["local-only records","transients, sessions, session_tokens","synthetic DD-MIGRATION-TEST records","Docker/.env/local mu-plugins (not in package)"],
 "preserved_on_import_from_hosted":["staff/admin users by identity (restore-staff.php)","retained gateway/SMTP/integration options (initial-import.sh RETAIN)",".env, uploads, server plugins"],
 "approved_transformations":{"product_id_corrections":$PCORR,"product_id_corrections_file":"product-id-corrections.tsv","note":"destination products moved to their verified legacy product IDs (identity: SKU+title+type); every reference followed; reversible map in dd_mig_idmap / dd_mig_refs"},
 "known_issues_file":"${NOTES:+KNOWN-ISSUES.txt}",
 "drift_guard":"initial-import.sh refuses unless the hosted fingerprint equals destination-fingerprint.tsv"}
JSON
rm -f "$OUT/source-candidate.sql.gz" "$OUT/source-candidate.sql.gz.sha256"
(cd "$OUT" && find . -type f ! -name SHA256SUMS | sort | xargs shasum -a 256 > SHA256SUMS)
[ -f "$OUT/residuals.txt" ] && { echo "PACKAGE HAS STRICT RESIDUALS — review $OUT/residuals.txt; not usable"; exit 1; }
echo "reported (pre-existing, destination-owned or inert) residual locations: $(wc -l < "$OUT/residuals-reported.tsv") — see residuals-reported.tsv"
echo "PACKAGE READY: $OUT"; du -sh "$OUT"
