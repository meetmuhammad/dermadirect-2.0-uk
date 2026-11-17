#!/bin/sh
# Regression test for the Action A staff-restore defect: a hosted administrator at ID 17553 overwrote legacy
# customer 17553 (row, password hash, role) because staff were restored by numeric ID.
# Fixtures (rehearsal DBs, built from the package copy dd_rh_test_export):
#   dd_rh_test_staffdest = the package's accounts (all users + usermeta) + customer 17553's orders + idmap
#   dd_rh_test_staffhost = the hosted site: the 14 package staff (10224 with a different hosted hash)
#                          + a DIFFERENT person as administrator at 17553 (the exact collision)
#   scripts/migration/tests/staff_restore_test.sh
set -u
cd "$(dirname "$0")/../../.."
fails=0; t() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fails=$((fails+1)); fi; }
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
R=./scripts/migration/run.sh; X=dd_rh_test_export; DD=dd_rh_test_staffdest; DH=dd_rh_test_staffhost
W=migration-reports/tmp-staff-test; mkdir -p "$W"; F=/var/www/html/$W
ORDERS="SELECT post_id FROM $X.wp_postmeta WHERE meta_key='_customer_user' AND meta_value='17553'"
STAFFQ="SELECT user_id FROM $X.wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value NOT LIKE '%\"customer\"%' AND meta_value NOT LIKE '%\"blocked_customer\"%' AND meta_value<>'a:0:{}'"

echo "== fixtures"
sql -e "DROP DATABASE IF EXISTS $DD; DROP DATABASE IF EXISTS $DH; CREATE DATABASE $DD; CREATE DATABASE $DH;"
for tb in wp_users wp_usermeta wp_options wp_posts wp_postmeta wp_comments dd_mig_idmap; do sql -e "CREATE TABLE $DD.$tb LIKE $X.$tb"; done
for tb in wp_users wp_usermeta wp_options; do sql -e "CREATE TABLE $DH.$tb LIKE $X.$tb"; done
sql -e "INSERT INTO $DD.wp_users SELECT * FROM $X.wp_users; INSERT INTO $DD.wp_usermeta SELECT * FROM $X.wp_usermeta;
  INSERT INTO $DD.wp_options SELECT * FROM $X.wp_options WHERE option_name IN ('stylesheet','home');
  INSERT INTO $DD.wp_posts SELECT * FROM $X.wp_posts WHERE ID IN ($ORDERS) OR post_author IN (17553,47553);
  INSERT INTO $DD.wp_postmeta SELECT * FROM $X.wp_postmeta WHERE post_id IN (SELECT ID FROM $DD.wp_posts);
  INSERT INTO $DD.dd_mig_idmap SELECT * FROM $X.dd_mig_idmap;
  INSERT INTO $DH.wp_options SELECT * FROM $X.wp_options WHERE option_name IN ('stylesheet','home');
  INSERT INTO $DH.wp_users SELECT * FROM $X.wp_users WHERE ID IN ($STAFFQ);
  INSERT INTO $DH.wp_usermeta SELECT * FROM $X.wp_usermeta WHERE user_id IN ($STAFFQ);
  INSERT INTO $DH.wp_users SELECT * FROM $X.wp_users WHERE ID=35;
  INSERT INTO $DH.wp_usermeta SELECT * FROM $X.wp_usermeta WHERE user_id=35;
  UPDATE $DH.wp_users SET user_pass='\$wp\$2y\$10\$hostedhashhostedhashhostedhashhostedhashhostedhashho' WHERE ID=10224;
  INSERT INTO $DH.wp_users (ID,user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name)
    VALUES (17553,'dd-test-hosted-admin','\$wp\$2y\$10\$stafhashstafhashstafhashstafhashstafhashstafhashstafha','dd-test-hosted-admin','dd-test-hosted-admin@example.invalid','','2026-09-01 00:00:00','',0,'Hosted Admin');
  INSERT INTO $DH.wp_usermeta (user_id,meta_key,meta_value) VALUES (17553,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(17553,'wp_user_level','10'),(17553,'nickname','dd-test-hosted-admin');"
t "fixture: package has customer 17553 with orders, hosted has a different administrator at 17553" \
  "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_usermeta WHERE user_id=17553 AND meta_key='wp_capabilities' AND meta_value LIKE '%\\\"customer\\\"%'\")\" = 1 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_posts WHERE ID IN ($ORDERS)\")\" -ge 1 ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DH.wp_usermeta WHERE user_id=17553 AND meta_value LIKE '%administrator%'\")\" = 1 ]"
# snapshot of the customer as the package holds it
CUST_BEFORE=$(sql -e "SELECT SHA2(CONCAT_WS('|',ID,user_login,user_email,user_pass,display_name),256) FROM $DD.wp_users WHERE ID=17553")
CMETA_BEFORE=$(sql -e "SELECT SHA2(GROUP_CONCAT(meta_key,'=',meta_value ORDER BY umeta_id SEPARATOR '\n'),256) FROM $DD.wp_usermeta WHERE user_id=17553")
OWN_BEFORE=$(sql -e "SELECT COUNT(*) FROM $DD.wp_postmeta WHERE meta_key='_customer_user' AND meta_value='17553'")

echo "== scenario A: exact collision"
$R restore-staff.php identity --dest=$DD --out=$F/pkg.tsv >/dev/null
$R restore-staff.php save --dest=$DH --out=$F/staff.json >/dev/null
t "save: only staff accounts saved (customer 35 excluded): 15 package staff + the hosted admin" "python3 -c \"import json,sys;s=json.load(open('$W/staff.json'))['staff'];ids=[int(x['user']['ID']) for x in s];sys.exit(0 if len(ids)==16 and 35 not in ids and 17553 in ids else 1)\""
$R restore-staff.php plan --staff=$F/staff.json --identity=$F/pkg.tsv --out=$F/plan.json > "$W/plan.log" 2>&1; rc=$?
t "plan: no conflicts" "[ $rc = 0 ]"
t "plan: hosted admin 17553 relocated to 47553 (reserved range), not written over the customer" "grep -q 'staff 17553: relocate -> 47553 (ID 17553 holds a migrated customer account)' $W/plan.log"
t "plan: same-identity staff 10224 restored in place" "grep -q 'staff 10224: in-place' $W/plan.log"
$R restore-staff.php apply --dest=$DD --staff=$F/staff.json --expect=$F/plan.json > "$W/apply.log" 2>&1; rc=$?
t "apply succeeded" "[ $rc = 0 ]"
t "customer 17553: row identical (login, email, password hash, name)" "[ \"\$(sql -e \"SELECT SHA2(CONCAT_WS('|',ID,user_login,user_email,user_pass,display_name),256) FROM $DD.wp_users WHERE ID=17553\")\" = '$CUST_BEFORE' ]"
t "customer 17553: all usermeta identical (role, capabilities, addresses)" "[ \"\$(sql -e \"SELECT SHA2(GROUP_CONCAT(meta_key,'=',meta_value ORDER BY umeta_id SEPARATOR '\\n'),256) FROM $DD.wp_usermeta WHERE user_id=17553\")\" = '$CMETA_BEFORE' ]"
t "customer 17553: role is customer, no administrator capability, no user_level" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_usermeta WHERE user_id=17553 AND ((meta_key='wp_capabilities' AND (meta_value LIKE '%administrator%' OR meta_value NOT LIKE '%\\\"customer\\\"%')) OR (meta_key='wp_user_level' AND meta_value<>'0'))\")\" = 0 ]"
t "customer 17553: still owns the same orders" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_postmeta WHERE meta_key='_customer_user' AND meta_value='17553'\")\" = '$OWN_BEFORE' ]"
t "relocated staff 47553: hosted login, hosted hash, administrator + user_level 10" "[ \"\$(sql -e \"SELECT CONCAT(u.user_login,'|',u.user_pass=h.user_pass) FROM $DD.wp_users u JOIN $DH.wp_users h ON h.ID=17553 WHERE u.ID=47553\")\" = 'dd-test-hosted-admin|1' ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_usermeta WHERE user_id=47553 AND ((meta_key='wp_capabilities' AND meta_value LIKE '%administrator%') OR (meta_key='wp_user_level' AND meta_value='10'))\")\" = 2 ]"
t "relocated staff 47553: usermeta references point at the new ID (none left at 17553 from the hosted account)" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_usermeta WHERE user_id=17553 AND meta_value LIKE '%dd-test-hosted-admin%'\")\" = 0 ]"
t "staff mapping persisted (dd_mig_idmap ns=staff 17553 -> 47553)" "[ \"\$(sql -e \"SELECT new_id FROM $DD.dd_mig_idmap WHERE ns='staff' AND old_id=17553\")\" = 47553 ]"
t "in-place staff 10224 carries the hosted hash" "[ \"\$(sql -e \"SELECT a.user_pass=b.user_pass FROM $DD.wp_users a JOIN $DH.wp_users b ON b.ID=a.ID WHERE a.ID=10224\")\" = 1 ]"
$R restore-staff.php verify --dest=$DD --staff=$F/staff.json --identity=$F/pkg.tsv > "$W/verify.log" 2>&1; rc=$?
t "verify: every migrated account identical to the package; no unexpected privileged account" "[ $rc = 0 ] && grep -q 'STAFF VERIFY OK' $W/verify.log"

echo "== rerun (idempotent)"
N_USERS=$(sql -e "SELECT COUNT(*) FROM $DD.wp_users"); N_META=$(sql -e "SELECT COUNT(*) FROM $DD.wp_usermeta")
$R restore-staff.php apply --dest=$DD --staff=$F/staff.json --expect=$F/plan.json > "$W/apply2.log" 2>&1; rc=$?
t "second apply succeeds with the same targets" "[ $rc = 0 ] && grep -q 'staff 17553: relocate -> 47553' $W/apply2.log"
t "no duplicate accounts or usermeta after rerun" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_users\")\" = $N_USERS ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_usermeta\")\" = $N_META ] && [ \"\$(sql -e \"SELECT COUNT(*) FROM $DD.wp_users WHERE user_login='dd-test-hosted-admin'\")\" = 1 ]"

echo "== privilege scan catches an elevated customer"
sql -e "UPDATE $DD.wp_usermeta SET meta_value='a:1:{s:13:\"administrator\";b:1;}' WHERE user_id=35 AND meta_key='wp_capabilities'"
$R restore-staff.php verify --dest=$DD --staff=$F/staff.json --identity=$F/pkg.tsv > "$W/verify-neg.log" 2>&1; rc=$?
t "verify fails when any customer gains staff capabilities" "[ $rc = 1 ] && grep -q 'account 35 has staff capabilities' $W/verify-neg.log"
sql -e "UPDATE $DD.wp_usermeta m JOIN $X.wp_usermeta x ON x.umeta_id=m.umeta_id SET m.meta_value=x.meta_value WHERE m.user_id=35 AND m.meta_key='wp_capabilities'"

echo "== scenario B: ambiguous identities are refused, nothing changes"
FP=$(sql -e "SELECT CONCAT(COUNT(*),':',SUM(CRC32(CONCAT_WS('|',ID,user_login,user_email,user_pass)))) FROM $DD.wp_users")
# B1: hosted account at 17553 shares only the customer's email
sql -e "UPDATE $DH.wp_users h JOIN $DD.wp_users c ON c.ID=17553 SET h.user_email=c.user_email WHERE h.ID=17553"
$R restore-staff.php save --dest=$DH --out=$F/staff-b.json >/dev/null
$R restore-staff.php plan --staff=$F/staff-b.json --identity=$F/pkg.tsv > "$W/plan-b.log" 2>&1; rc=$?
t "plan refuses: only the email matches the customer at the same ID" "[ $rc = 1 ] && grep -q 'CONFLICT staff 17553: only the email matches' $W/plan-b.log"
# B2: a hosted staff account at a free ID uses a customer's login elsewhere
sql -e "UPDATE $DH.wp_users SET user_email='dd-test-hosted-admin@example.invalid' WHERE ID=17553;
  UPDATE $DH.wp_users h JOIN $DD.wp_users c ON c.ID=35 SET h.ID=17600, h.user_login=c.user_login WHERE h.ID=17553; UPDATE $DH.wp_usermeta SET user_id=17600 WHERE user_id=17553"
$R restore-staff.php save --dest=$DH --out=$F/staff-b2.json >/dev/null
$R restore-staff.php plan --staff=$F/staff-b2.json --identity=$F/pkg.tsv > "$W/plan-b2.log" 2>&1; rc=$?
t "plan refuses: staff login already used by migrated customer 35" "[ $rc = 1 ] && grep -q 'CONFLICT staff 17600: login is also used by migrated account 35 (customer)' $W/plan-b2.log"
$R restore-staff.php apply --dest=$DD --staff=$F/staff-b2.json > "$W/apply-b2.log" 2>&1; rc=$?
t "apply refuses the same conflict and changes nothing" "[ $rc = 1 ] && grep -q 'conflicts — nothing changed' $W/apply-b2.log && [ \"\$(sql -e \"SELECT CONCAT(COUNT(*),':',SUM(CRC32(CONCAT_WS('|',ID,user_login,user_email,user_pass)))) FROM $DD.wp_users\")\" = '$FP' ]"

[ "${KEEP:-}" ] || { sql -e "DROP DATABASE $DD; DROP DATABASE $DH;"; rm -rf "$W"; }
[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
