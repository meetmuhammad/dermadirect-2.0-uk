#!/bin/sh
# Regression for Action A's destination drift guard (remote/dest-fingerprint.sh): the fingerprint must
# change for any content or settings edit, addition or deletion, and must NOT change for volatile rows
# (transients, login sessions, queue/log tables). Runs the real script in the local WP-CLI container.
#   scripts/deploy/tests/drift_guard_test.sh
set -u
cd "$(dirname "$0")/../../.."
B=dd_rh_test_base_oct19; F=dd_rh_test_drift
fails=0; t() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fails=$((fails+1)); fi; }
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
# test container only: the wordpress:cli client requires TLS that the local MariaDB lacks; the simulator's
# client wrappers add --skip-ssl (scripts/deploy/tests/initial_sim_test.sh creates them)
W=$PWD/migration-reports/tmp-client-bin; mkdir -p "$W"
for c in mariadb mariadb-dump mysql mysqldump; do
  printf '#!/bin/sh\nif [ "${1:-}" = --no-defaults ]; then shift; exec /usr/bin/%s --no-defaults --skip-ssl "$@"; fi\nexec /usr/bin/%s --skip-ssl "$@"\n' $c $c > "$W/$c"; chmod +x "$W/$c"
done
fp() { o=$(DB_NAME=$F docker compose --profile cli run --rm -T --no-deps -v "$W:/usr/local/sbin:ro" --entrypoint bash wpcli scripts/deploy/remote/dest-fingerprint.sh /var/www/html 2>/dev/null) || { echo FINGERPRINT-FAILED; return; }; echo "$o" | shasum | cut -c1-16; }
sql -e "DROP DATABASE IF EXISTS $F; CREATE DATABASE $F; GRANT ALL ON $F.* TO 'dermadirect'@'%';"
for tb in wp_posts wp_postmeta wp_options wp_users wp_usermeta wp_terms wp_woocommerce_sessions wp_actionscheduler_actions; do sql -e "CREATE TABLE $F.$tb LIKE $B.$tb"; done
sql -e "INSERT INTO $F.wp_posts SELECT * FROM $B.wp_posts WHERE post_type IN ('page','product') LIMIT 200;
  INSERT INTO $F.wp_postmeta SELECT pm.* FROM $B.wp_postmeta pm JOIN $F.wp_posts p ON p.ID=pm.post_id;
  INSERT INTO $F.wp_options SELECT * FROM $B.wp_options; INSERT INTO $F.wp_users SELECT * FROM $B.wp_users WHERE ID IN (1,17,10224);
  INSERT INTO $F.wp_usermeta SELECT * FROM $B.wp_usermeta WHERE user_id IN (1,17,10224); INSERT INTO $F.wp_terms SELECT * FROM $B.wp_terms;"
PID=$(sql -e "SELECT MIN(ID) FROM $F.wp_posts WHERE post_type='product'"); MID=$(sql -e "SELECT MIN(meta_id) FROM $F.wp_postmeta WHERE post_id=$PID")
base=$(fp)
t "fingerprint runs (every query answered)" "[ '$base' != FINGERPRINT-FAILED ]"
t "fingerprint is stable when nothing changes" "[ \"\$(fp)\" = '$base' ]"
sql -e "INSERT INTO $F.wp_options (option_name, option_value, autoload) VALUES ('_transient_dd_drift_test','x','off');
  INSERT INTO $F.wp_usermeta (user_id, meta_key, meta_value) VALUES (1,'session_tokens','a:0:{}');
  INSERT INTO $F.wp_woocommerce_sessions (session_key, session_value, session_expiry) VALUES ('dd-drift','x',0);"
t "volatile rows (transient, login session, cart session) do not trigger the guard" "[ \"\$(fp)\" = '$base' ]"
sql -e "DELETE FROM $F.wp_options WHERE option_name='_transient_dd_drift_test'; DELETE FROM $F.wp_usermeta WHERE meta_key='session_tokens' AND meta_value='a:0:{}'; DELETE FROM $F.wp_woocommerce_sessions WHERE session_key='dd-drift'"
sql -e "UPDATE $F.wp_postmeta SET meta_value=CONCAT(meta_value,'x') WHERE meta_id=$MID"
t "an edited product meta value is detected" "[ \"\$(fp)\" != '$base' ]"
sql -e "UPDATE $F.wp_postmeta SET meta_value=LEFT(meta_value, CHAR_LENGTH(meta_value)-1) WHERE meta_id=$MID"
t "reverting the edit restores the fingerprint" "[ \"\$(fp)\" = '$base' ]"
sql -e "CREATE TABLE $F.keep_row SELECT * FROM $F.wp_postmeta WHERE meta_id=$MID; DELETE FROM $F.wp_postmeta WHERE meta_id=$MID"
t "a deleted row is detected (not visible to max-ID or modified-time checks)" "[ \"\$(fp)\" != '$base' ]"
sql -e "INSERT INTO $F.wp_postmeta SELECT * FROM $F.keep_row; DROP TABLE $F.keep_row"
sql -e "UPDATE $F.wp_options SET option_value=CONCAT(option_value,' ') WHERE option_name='blogdescription'"
t "an edited setting is detected" "[ \"\$(fp)\" != '$base' ]"
sql -e "UPDATE $F.wp_options SET option_value=RTRIM(option_value) WHERE option_name='blogdescription'; UPDATE $F.wp_users SET display_name=CONCAT(display_name,'.') WHERE ID=17"
t "an edited staff account is detected" "[ \"\$(fp)\" != '$base' ]"
sql -e "DROP DATABASE $F"
o=$(DB_NAME=dd_rh_test_missing_db docker compose --profile cli run --rm -T --no-deps -v "$W:/usr/local/sbin:ro" --entrypoint bash wpcli scripts/deploy/remote/dest-fingerprint.sh /var/www/html 2>/dev/null); rc=$?
t "a failing database query aborts the fingerprint (no empty fingerprint)" "[ $rc != 0 ]"
rm -rf "$W"
[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
