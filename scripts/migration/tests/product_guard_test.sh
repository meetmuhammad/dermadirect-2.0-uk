#!/bin/sh
# Regression: destination products/variations are never relocated, and every entry point needs explicit
# identities. Fixture dd_rh_test_guard = minimal new-site tables with a PRODUCT and a PAGE inside legacy's
# ID space (IDs taken from the 2026-10-12 review-site collisions); source = the frozen production snapshot.
#   scripts/migration/tests/product_guard_test.sh [source_db]
set -u
cd "$(dirname "$0")/../../.."
SRC=${1:-dd_rh_src_prod_20261019}; G=dd_rh_test_guard; B=dd_rh_test_base_oct19
fails=0; t() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fails=$((fails+1)); fi; }
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
R=./scripts/migration/run.sh
PROD=696776; PAGE=696796   # legacy has an order at 696776 and nothing at 696796
sql -e "DROP DATABASE IF EXISTS $G; CREATE DATABASE $G;"
for tb in wp_posts wp_postmeta wp_users wp_usermeta wp_options wp_term_relationships wp_comments wp_commentmeta; do sql -e "CREATE TABLE $G.$tb LIKE $B.$tb"; done
sql -e "INSERT INTO $G.wp_options SELECT * FROM $B.wp_options WHERE option_name IN ('stylesheet','template','home','siteurl');
  INSERT INTO $G.wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES
   ($PROD,1,NOW(),UTC_TIMESTAMP(),'','Guard test product','','publish','open','closed','','guard-product','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'product','',0),
   ($PAGE,1,NOW(),UTC_TIMESTAMP(),'','Guard test page','','publish','closed','closed','','guard-page','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'page','',0);
  INSERT INTO $G.wp_postmeta (post_id,meta_key,meta_value) VALUES ($PROD,'_sku','GUARD-SKU'),($PROD,'_price','9.99');"
PFP=$(sql -e "SELECT MD5(CONCAT_WS('|',(SELECT CONCAT_WS('|',ID,post_title,post_type,post_status,post_name) FROM $G.wp_posts WHERE ID=$PROD),(SELECT GROUP_CONCAT(meta_key,'=',meta_value ORDER BY meta_key) FROM $G.wp_postmeta WHERE post_id=$PROD)))")

$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ > migration-reports/tmp-guard-1.log 2>&1; rc=$?
t "relocation refuses while a destination product sits in legacy ID space" "[ $rc != 0 ] && grep -q 'products/variations sit in legacy ID space' migration-reports/tmp-guard-1.log"
t "nothing was moved by the refused run" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $G.wp_posts WHERE ID IN ($PROD,$PAGE)\")\" = 2 ]"
t "the colliding product is listed for decision" "grep -q '^$PROD,product' migration-reports/renumber-product-collisions.csv"

$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ --skip-products > migration-reports/tmp-guard-2.log 2>&1; rc=$?
t "--skip-products relocates only non-product records" "[ $rc = 0 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $G.wp_posts WHERE ID=$PAGE\")\" = 0 ] && [ \"\$(sql -e \"SELECT post_type FROM $G.wp_posts WHERE ID=$((PAGE + 300000))\")\" = page ]"
t "the product is untouched: same ID, row and meta" "[ \"\$(sql -e \"SELECT MD5(CONCAT_WS('|',(SELECT CONCAT_WS('|',ID,post_title,post_type,post_status,post_name) FROM $G.wp_posts WHERE ID=$PROD),(SELECT GROUP_CONCAT(meta_key,'=',meta_value ORDER BY meta_key) FROM $G.wp_postmeta WHERE post_id=$PROD)))\")\" = '$PFP' ]"
$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ --skip-products > migration-reports/tmp-guard-3.log 2>&1; rc=$?
t "rerun is a no-op (page not moved twice, product still in place)" "[ $rc = 0 ] && grep -q 'nothing to move' migration-reports/tmp-guard-3.log && [ \"\$(sql -e \"SELECT COUNT(*) FROM $G.wp_posts WHERE ID IN ($PROD,$((PAGE + 300000)))\")\" = 2 ]"

# explicit identities on every entry point
scripts/migration/final-sync.sh plan prod-20261019 > migration-reports/tmp-guard-4.log 2>&1; rc=$?
t "final-sync refuses a local command without --dest" "[ $rc = 2 ] && grep -q 'needs an explicit --dest' migration-reports/tmp-guard-4.log"
# the sync preflight needs the commerce tables and raised ID counters: add empty copies, then headroom
for tb in $(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$B' AND table_name LIKE 'wp\\_%' AND table_name NOT IN (SELECT table_name FROM information_schema.tables WHERE table_schema='$G')"); do sql -e "CREATE TABLE $G.\`$tb\` LIKE $B.\`$tb\`"; done
$R headroom.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ > /dev/null 2>&1
$R sync.php plan --src=$SRC --src-prefix=lgcy_ --dest=$G --profile=verbatim > migration-reports/tmp-guard-5.log 2>&1; rc=$?
t "sync refuses while a product collision is undecided (no isolation flag)" "[ $rc != 0 ] && grep -q '1 destination products' migration-reports/tmp-guard-5.log"
scripts/deploy/package-initial.sh guardtest > migration-reports/tmp-guard-6.log 2>&1; rc=$?
t "packaging refuses without explicit source, legacy snapshot and baseline" "[ $rc = 2 ] && grep -q 'needs --source' migration-reports/tmp-guard-6.log && [ ! -e recovery/deploy/initial-guardtest ]"
PS=$(docker compose --profile cli run --rm -T --no-deps --entrypoint php wpcli -r 'echo (require "scripts/migration/config.php")["product_sync"] ? 1 : 0;' 2>/dev/null)
t "product sync is off in the shared configuration" "[ '$PS' = 0 ]"

[ "${KEEP:-}" ] || { sql -e "DROP DATABASE $G"; rm -f migration-reports/tmp-guard-*.log; }
[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
