#!/bin/sh
# Guard and collision tests against local rehearsal databases (never the canonical ones).
#   scripts/migration/tests/guards_test.sh <db with wp_ destination + lgcy_ source tables>
set -u
cd "$(dirname "$0")/../../.."
DB=${1:?db}; R=./scripts/migration/run.sh; fails=0
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
# Remove leftovers of an earlier aborted run of this test (only the marker record).
sql -e "DELETE FROM $DB.wp_posts WHERE post_name='dd-collision-test' AND post_title='collision test page'; DELETE FROM $DB.dd_mig_idmap WHERE ns='posts' AND old_id=699500 AND kind='page'"
expect_fail() { name=$1; pat=$2; shift 2; out=$("$@" 2>&1); if echo "$out" | grep -q "$pat"; then echo "ok   $name"; else echo "FAIL $name :: $(echo "$out" | grep -v Container | tail -2)"; fails=$((fails+1)); fi; }
expect_ok() { name=$1; shift; out=$("$@" 2>&1); if [ $? -eq 0 ]; then echo "ok   $name"; else echo "FAIL $name :: $(echo "$out" | grep -v Container | tail -2)"; fails=$((fails+1)); fi; }
expect_fail "same-db source with destination prefix refused" "overlaps" $R sync.php plan --src=$DB --src-prefix=wp_ --dest=$DB --only=users
expect_fail "nested prefix wp_old_ refused" "overlaps" $R sync.php plan --src=$DB --src-prefix=wp_old_ --dest=$DB --only=users
expect_fail "canonical local DB refused as destination" "protected" $R sync.php plan --src=$DB --src-prefix=lgcy_ --dest=dermadirect_local --only=users
expect_fail "non-rehearsal DB refused without hosted confirmation" "not a rehearsal" $R sync.php plan --src=$DB --src-prefix=lgcy_ --dest=kinsta_like_db --only=users
sql -e "DROP DATABASE IF EXISTS dd_rh_test_guard; CREATE DATABASE dd_rh_test_guard; CREATE TABLE dd_rh_test_guard.wp_options (option_id INT PRIMARY KEY, option_name VARCHAR(191), option_value LONGTEXT); INSERT INTO dd_rh_test_guard.wp_options VALUES (1,'stylesheet','porto-child');"
expect_fail "legacy-looking destination (Porto theme) refused" "does not run the new Sage theme" $R sync.php plan --src=$DB --src-prefix=lgcy_ --dest=dd_rh_test_guard --only=users
sql -e "DROP DATABASE dd_rh_test_guard"
expect_fail "missing source tables refused" "not found\|missing required tables" $R sync.php plan --src=$DB --src-prefix=nope_ --dest=$DB --only=users
# Collision handling: already-moved records are recognised; a NEW destination record inside legacy's
# ID space is detected, blocks the sync, and is moved by a repeat renumber run (map kept).
before=$(sql -e "SELECT COUNT(*) FROM $DB.dd_mig_idmap")
expect_ok "already-renumbered destination passes collision preflight" $R sync.php plan --src=$DB --src-prefix=lgcy_ --dest=$DB --only=users
sql -e "INSERT INTO $DB.wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)
        VALUES (699500,1,NOW(),UTC_TIMESTAMP(),'','collision test page','','publish','closed','closed','','dd-collision-test','','',NOW(),UTC_TIMESTAMP(),'',0,'',0,'page','',0)"
expect_fail "new destination record in legacy ID space blocks the sync" "new ID collisions\|growth range" $R sync.php plan --src=$DB --src-prefix=lgcy_ --dest=$DB --only=users
o_before=$(sql -e "SELECT CONCAT(post_type,'|',post_status,'|',(SELECT COUNT(*) FROM $DB.wp_postmeta WHERE post_id=695564)) FROM $DB.wp_posts WHERE ID=695564")
expect_ok "renumber moves only the new record" $R renumber.php apply --dest=$DB --src=$DB --src-prefix=lgcy_
o_after=$(sql -e "SELECT CONCAT(post_type,'|',post_status,'|',(SELECT COUNT(*) FROM $DB.wp_postmeta WHERE post_id=695564)) FROM $DB.wp_posts WHERE ID=695564")
[ -n "$o_before" ] && [ "$o_before" = "$o_after" ] && echo "ok   legacy order at a vacated ID (695564) untouched by a later renumber ($o_after)" || { echo "FAIL legacy order 695564 changed: $o_before -> $o_after"; fails=$((fails+1)); }
after=$(sql -e "SELECT COUNT(*) FROM $DB.dd_mig_idmap"); moved=$(sql -e "SELECT new_id FROM $DB.dd_mig_idmap WHERE old_id=699500")
[ "$after" -eq $((before+1)) ] && [ "$moved" = 999500 ] && echo "ok   map kept ($before) + 1 new entry (699500 -> $moved)" || { echo "FAIL map: before=$before after=$after moved=$moved"; fails=$((fails+1)); }
expect_ok "sync preflight passes again after renumber" $R sync.php plan --src=$DB --src-prefix=lgcy_ --dest=$DB --only=users
lgcy_before=$(sql -e "CHECKSUM TABLE $DB.lgcy_posts, $DB.lgcy_postmeta, $DB.lgcy_users" | awk '{print $2}' | tr '\n' ' ')
expect_ok "renumber rerun is a no-op" $R renumber.php apply --dest=$DB --src=$DB --src-prefix=lgcy_
lgcy_after=$(sql -e "CHECKSUM TABLE $DB.lgcy_posts, $DB.lgcy_postmeta, $DB.lgcy_users" | awk '{print $2}' | tr '\n' ' ')
[ "$lgcy_before" = "$lgcy_after" ] && echo "ok   lgcy_ source tables unchanged by tools" || { echo "FAIL source changed"; fails=$((fails+1)); }
[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
