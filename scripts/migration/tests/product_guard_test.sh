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
PROD2=696808; ATT=696053   # legacy order at 696808; ATT is a destination attachment at the legacy ID of PROD2's product
sql -e "DROP DATABASE IF EXISTS $G; CREATE DATABASE $G;"
for tb in wp_posts wp_postmeta wp_users wp_usermeta wp_options wp_term_relationships wp_comments wp_commentmeta; do sql -e "CREATE TABLE $G.$tb LIKE $B.$tb"; done
sql -e "INSERT INTO $G.wp_options SELECT * FROM $B.wp_options WHERE option_name IN ('stylesheet','template','home','siteurl');
  INSERT INTO $G.wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES
   ($PROD,1,NOW(),UTC_TIMESTAMP(),'','Jalupro Recovery Cream (1x30ml)','','publish','open','closed','','guard-product','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'product','',0),
   ($PAGE,1,NOW(),UTC_TIMESTAMP(),'','Guard test page','','publish','closed','closed','','guard-page','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'page','',0),
   ($PROD2,1,NOW(),UTC_TIMESTAMP(),'','Teofil Lips (1x1.1ml)','','publish','open','closed','','guard-product-2','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'product','',0),
   ($ATT,1,NOW(),UTC_TIMESTAMP(),'','Guard image','','inherit','open','closed','','guard-image','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'attachment','image/jpeg',0);
  INSERT INTO $G.wp_postmeta (post_id,meta_key,meta_value) VALUES ($PROD,'_sku','07011030'),($PROD,'_price','9.99'),($PROD2,'_sku','06960537'),($PROD2,'_thumbnail_id','$ATT');"
PFP=$(sql -e "SELECT MD5(CONCAT_WS('|',(SELECT CONCAT_WS('|',ID,post_title,post_type,post_status,post_name) FROM $G.wp_posts WHERE ID=$PROD),(SELECT GROUP_CONCAT(meta_key,'=',meta_value ORDER BY meta_key) FROM $G.wp_postmeta WHERE post_id=$PROD)))")

$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ > migration-reports/tmp-guard-1.log 2>&1; rc=$?
t "relocation refuses while a destination product sits in legacy ID space" "[ $rc != 0 ] && grep -q 'products/variations sit in legacy ID space' migration-reports/tmp-guard-1.log"
t "nothing was moved by the refused run" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $G.wp_posts WHERE ID IN ($PROD,$PAGE)\")\" = 2 ]"
t "the colliding product is listed for decision" "grep -q '^$PROD,product' migration-reports/renumber-product-collisions.csv"

$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ --skip-products > migration-reports/tmp-guard-2.log 2>&1; rc=$?
t "--skip-products relocates only non-product records" "[ $rc = 0 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $G.wp_posts WHERE ID IN ($PAGE,$ATT)\")\" = 0 ] && [ \"\$(sql -e \"SELECT post_type FROM $G.wp_posts WHERE ID=$((PAGE + 300000))\")\" = page ] && [ \"\$(sql -e \"SELECT meta_value FROM $G.wp_postmeta WHERE post_id=$PROD2 AND meta_key='_thumbnail_id'\")\" = $((ATT + 300000)) ]"
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

# explicit identity correction (--product-map, approved 2026-10-20): identity-checked, references follow, rerun no-op
LEG=701103   # the legacy product with this SKU/title
sql -e "INSERT INTO $G.wp_wc_product_meta_lookup (product_id,sku) VALUES ($PROD,'07011030'); INSERT INTO $G.wp_stock_log (product_id,qty,date_created) VALUES ($PROD,5,NOW());
  INSERT INTO $G.wp_options (option_name,option_value,autoload) VALUES ('_transient_wc_related_$PROD','a:0:{}','off'),('_transient_timeout_wc_related_$PROD','9999999999','off');
  INSERT INTO $G.wp_term_relationships (object_id,term_taxonomy_id) VALUES ($PROD,2)"
M=migration-reports/tmp-guard-map.csv
printf 'destination_id,legacy_id\n%s,%s\n%s,%s\n' $PROD $LEG $PROD $LEG > $M
$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ --product-map=$M > migration-reports/tmp-guard-7b.log 2>&1; rc=$?
t "--product-map refuses a duplicated row (nothing moved)" "[ $rc != 0 ] && grep -q 'duplicate row or target' migration-reports/tmp-guard-7b.log && [ \"\$(sql -e \"SELECT COUNT(*) FROM $G.wp_posts WHERE ID=$PROD\")\" = 1 ]"
printf 'destination_id,legacy_id\n%s,%s\n' $PROD 697314 > $M
$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ --product-map=$M > migration-reports/tmp-guard-7.log 2>&1; rc=$?
t "--product-map refuses a target whose legacy SKU/title differ (nothing moved)" "[ $rc != 0 ] && grep -q 'does not match' migration-reports/tmp-guard-7.log && [ \"\$(sql -e \"SELECT COUNT(*) FROM $G.wp_posts WHERE ID=$PROD\")\" = 1 ]"
printf 'destination_id,legacy_id\n%s,%s\n%s,%s\n' $PROD $LEG $PROD2 $ATT > $M
$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ --product-map=$M > migration-reports/tmp-guard-8.log 2>&1; rc=$?
t "--product-map moves the product to its legacy ID with every reference" "[ $rc = 0 ] && [ \"\$(sql -e \"SELECT CONCAT_WS(',',(SELECT COUNT(*) FROM $G.wp_posts WHERE ID=$PROD),(SELECT post_type FROM $G.wp_posts WHERE ID=$LEG),(SELECT COUNT(*) FROM $G.wp_postmeta WHERE post_id=$LEG),(SELECT COUNT(*) FROM $G.wp_wc_product_meta_lookup WHERE product_id=$LEG),(SELECT COUNT(*) FROM $G.wp_stock_log WHERE product_id=$LEG),(SELECT COUNT(*) FROM $G.wp_term_relationships WHERE object_id=$LEG))\")\" = '0,product,2,1,1,1' ]"
t "product values unchanged apart from the ID" "[ \"\$(sql -e \"SELECT MD5(CONCAT_WS('|',(SELECT CONCAT_WS('|',$PROD,post_title,post_type,post_status,post_name) FROM $G.wp_posts WHERE ID=$LEG),(SELECT GROUP_CONCAT(meta_key,'=',meta_value ORDER BY meta_key) FROM $G.wp_postmeta WHERE post_id=$LEG)))\")\" = '$PFP' ]"
t "the transient cache naming the old ID is deleted with its timeout" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $G.wp_options WHERE option_name LIKE '%wc_related_$PROD'\")\" = 0 ]"
t "the reversible mapping is recorded" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $G.dd_mig_idmap WHERE ns='posts' AND old_id=$PROD AND new_id=$LEG\")\" = 1 ]"
$R renumber.php apply --dest=$G --src=$SRC --src-prefix=lgcy_ --product-map=$M > migration-reports/tmp-guard-9.log 2>&1; rc=$?
t "--product-map rerun is a no-op" "[ $rc = 0 ] && grep -q 'nothing to move' migration-reports/tmp-guard-9.log"
# PROD2 now holds ATT's vacated ID: its own rows written by renumber pass the residual check (provenance) ...
t "PROD2 re-occupies the vacated attachment ID, keeping the moved image reference" "[ \"\$(sql -e \"SELECT CONCAT(post_type,',',(SELECT meta_value FROM $G.wp_postmeta WHERE post_id=$ATT AND meta_key='_thumbnail_id')) FROM $G.wp_posts WHERE ID=$ATT\")\" = 'product,$((ATT + 300000))' ]"
$R renumber.php residual --dest=$G --src=$SRC --src-prefix=lgcy_ > migration-reports/tmp-guard-10.log 2>&1; rc=$?
t "residual passes: every hit on the re-occupied ID was written by renumber" "[ $rc = 0 ] && grep -q 'no remaining references (OK)' migration-reports/tmp-guard-10.log"
# ... but a stale reference to the OLD attachment (never rewritten) must still fail, even though the ID is reused
sql -e "INSERT INTO $G.wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (1001,1,NOW(),UTC_TIMESTAMP(),'','stale child','','inherit','open','closed','','stale-child','','',NOW(),UTC_TIMESTAMP(),'',$ATT,'',0,'attachment','image/jpeg',0)"
$R renumber.php residual --dest=$G --src=$SRC --src-prefix=lgcy_ > migration-reports/tmp-guard-11.log 2>&1; rc=$?
t "residual FAILS on a stale post_parent to the reused ID (not written by renumber)" "[ $rc != 0 ] && grep -q 'REMAINING REFERENCES (FAIL)' migration-reports/tmp-guard-11.log"
sql -e "DELETE FROM $G.wp_posts WHERE ID=1001"
# provenance (exact, valid after the sync too): every recorded cell holds its recorded new ID
$R renumber.php provenance --dest=$G --src=$SRC --src-prefix=lgcy_ > migration-reports/tmp-guard-12.log 2>&1; rc=$?
t "provenance passes: every recorded cell holds its new ID" "[ $rc = 0 ] && grep -q '(OK)' migration-reports/tmp-guard-12.log"
# the sync re-imports the legacy order at the vacated ID with REGENERATED meta IDs and the same values:
# provenance is unaffected, and residual (a pre-sync check) refuses rather than accepting it by ID
sql -e "INSERT INTO $G.wp_posts SELECT * FROM $SRC.lgcy_posts WHERE ID=$PROD; INSERT INTO $G.wp_postmeta (post_id,meta_key,meta_value) SELECT post_id,meta_key,meta_value FROM $SRC.lgcy_postmeta WHERE post_id=$PROD"
$R renumber.php provenance --dest=$G --src=$SRC --src-prefix=lgcy_ > migration-reports/tmp-guard-13.log 2>&1; rc=$?
t "provenance still passes with a legacy graph (regenerated meta IDs) at the vacated ID" "[ $rc = 0 ] && grep -q '(OK)' migration-reports/tmp-guard-13.log"
$R renumber.php residual --dest=$G --src=$SRC --src-prefix=lgcy_ > migration-reports/tmp-guard-14.log 2>&1; rc=$?
t "residual refuses after the sync (never accepts a legacy record by ID/key equality)" "[ $rc != 0 ] && grep -q 'REMAINING REFERENCES (FAIL)' migration-reports/tmp-guard-14.log"
sql -e "DELETE FROM $G.wp_postmeta WHERE post_id=$PROD; DELETE FROM $G.wp_posts WHERE ID=$PROD"
# a recorded cell reverted to the old ID (stale reference) or changed only in letter case is caught
sql -e "UPDATE $G.wp_stock_log SET product_id=$PROD WHERE product_id=$LEG"
$R renumber.php provenance --dest=$G --src=$SRC --src-prefix=lgcy_ > migration-reports/tmp-guard-15.log 2>&1; rc=$?
t "provenance FAILS when a recorded reference no longer holds its new ID" "[ $rc != 0 ] && grep -q '(FAIL)' migration-reports/tmp-guard-15.log"
sql -e "UPDATE $G.wp_stock_log SET product_id=$LEG WHERE product_id=$PROD"
# case-only differences: the comparisons used for protection must be byte-exact (MariaDB's default collation is not)
t "case-only change is a difference for the hashed comparison parity uses" "[ \"\$(sql -e \"SELECT MD5(CONCAT('_sku',0x1F,COALESCE(HEX('Abc'),'~'))) = MD5(CONCAT('_sku',0x1F,COALESCE(HEX('abc'),'~'))), 'Abc' = 'abc'\")\" = '0	1' ]"
t "NULL and empty value differ for that comparison" "[ \"\$(sql -e \"SELECT MD5(CONCAT('k',0x1F,COALESCE(HEX(NULL),'~'))) = MD5(CONCAT('k',0x1F,COALESCE(HEX(''),'~')))\")\" = 0 ]"

[ "${KEEP:-}" ] || { sql -e "DROP DATABASE $G"; rm -f migration-reports/tmp-guard-*.log; }
[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
