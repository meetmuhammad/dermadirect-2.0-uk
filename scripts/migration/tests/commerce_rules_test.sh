#!/bin/sh
# Functional test of the October business rules on a DISPOSABLE copy of the candidate (it creates test
# accounts, a test coupon and carts, and switches the Nexfill rule on there only; the candidate is untouched).
#   scripts/migration/tests/commerce_rules_test.sh dd_rh_test_qa_<name>
set -u
cd "$(dirname "$0")/../../.."
DB=${1:?disposable db}; case "$DB" in dd_rh_test_qa*) ;; *) echo "refusing: use a disposable dd_rh_test_qa* copy"; exit 2;; esac
W="./scripts/migration/wp.sh $DB"
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@" </dev/null; }
fails=0; t() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fails=$((fails+1)); fi; }
R=migration-reports/commerce-rules-$DB.jsonl; : > $R
run() { $W eval-file scripts/migration/tests/commerce_rules_scenarios.php "$1" "$2" 2>/dev/null | grep '^{' | tail -1 | tee -a $R; }
# currency strings use a non-breaking space after the symbol; compare them without it
j() { python3 -c "import json,sys; d=json.loads(sys.stdin.read() or '{}'); n=lambda v: str(v).replace(chr(160),'').replace(' ',''); print($1)"; }

echo "== setup on $DB (test-only changes)"
sql -e "UPDATE $DB.wp_wdr_rules SET enabled=1 WHERE id=80"   # rule 80 is disabled on production since 2026-10-19/20
P=$(openssl rand -hex 10)
$W user create dd-test-eligible dd-test-eligible@example.invalid --role=customer --user_pass=$P --porcelain >/dev/null 2>&1
$W user add-role dd-test-eligible nexfill_switch >/dev/null 2>&1
$W user create dd-test-ordinary dd-test-ordinary@example.invalid --role=customer --user_pass=$P --porcelain >/dev/null 2>&1
$W eval '$c=new WC_Coupon(); $c->set_code("ddtest10"); $c->set_discount_type("percent"); $c->set_amount(10); $c->save(); echo $c->get_id();' >/dev/null 2>&1
t "test accounts exist (eligible holds customer + nexfill_switch)" "[ \"\$(sql -e \"SELECT COUNT(*) FROM $DB.wp_users u JOIN $DB.wp_usermeta m ON m.user_id=u.ID AND m.meta_key='wp_capabilities' WHERE u.user_login='dd-test-eligible' AND m.meta_value LIKE '%nexfill_switch%' AND m.meta_value LIKE '%\\\"customer\\\"%'\")\" = 1 ]"

echo "== pricing (Sage helper; one process per customer)"
E=$(run price dd-test-eligible); O=$(run price dd-test-ordinary); L=$(run price -)
t "eligible customer sees £205.00 ex VAT / £246.00 inc VAT, no struck-through price" "[ \"\$(echo '$E' | j \"n(d.get('ex')),n(d.get('inc')),d.get('on_sale')\")\" = '£205.00 £246.00 False' ]"
t "ordinary customer sees £225.00 ex / £270.00 inc" "[ \"\$(echo '$O' | j \"n(d.get('ex')),n(d.get('inc'))\")\" = '£225.00 £270.00' ]"
t "logged-out visitor sees £225.00 ex / £270.00 inc" "[ \"\$(echo '$L' | j \"n(d.get('ex')),n(d.get('inc'))\")\" = '£225.00 £270.00' ]"
t "no leakage: ordinary customer priced after the eligible one in sequence still sees £225.00" "[ \"\$(run price dd-test-ordinary | j \"n(d.get('ex'))\")\" = '£225.00' ]"

echo "== codes and mixed baskets"
M=$(run mixed dd-test-ordinary)
t "WooCommerce code in a mixed basket discounts only the non-PRODERMIS item" "[ \"\$(echo '$M' | j \"[ (l['role'], l['subtotal_ex']==l['total_ex']) for l in d['lines'] ] == [('ordinary', False), ('prodermis', True)] and d['code_accepted']\")\" = True ]"
N=$(run prodonly dd-test-ordinary)
t "code on a PRODERMIS-only basket is refused with the PRODERMIS message" "[ \"\$(echo '$N' | j \"(not d['code_accepted']) and any('Prodermis' in n for n in d['notices'])\")\" = True ]"
D=$(run wdrcode dd-test-ordinary)
t "Discount Rules code (welcome10) never reduces the PRODERMIS item" "[ \"\$(echo '$D' | j \"all(l['subtotal_ex']==l['total_ex'] for l in d['lines'] if l['role']=='prodermis')\")\" = True ]"
echo "   welcome10 effect on the ordinary item: $(echo "$D" | j "[(l['role'], l['subtotal_ex'], l['total_ex']) for l in d['lines']]")"

echo "== bundle in the basket (eligible customer)"
BE=$(run bundle dd-test-eligible)
t "bundle added with 10 boxes" "[ \"\$(echo '$BE' | j \"d.get('bundle_added')\")\" = True ]"
t "eligible customer: bundle line = £205.00 ex VAT + £41.00 VAT (£246.00 inc)" "[ \"\$(echo '$BE' | j \"[(float(l['subtotal_ex']), float(l['tax'])) for l in d['lines'] if l['role']=='bundle'] == [(205.0, 41.0)]\")\" = True ]"
t "code never reduces the bundle (or its boxes), only the other item" "[ \"\$(echo '$BE' | j \"all(l['subtotal_ex']==l['total_ex'] for l in d['lines'] if l['role'] in ('bundle','bundle-child')) and any(l['subtotal_ex']>l['total_ex'] for l in d['lines'] if l['role']=='ordinary')\")\" = True ]"
BO=$(run bundle dd-test-ordinary)
t "ordinary customer: bundle line = £225.00 ex VAT + £45.00 VAT" "[ \"\$(echo '$BO' | j \"[(float(l['subtotal_ex']), float(l['tax'])) for l in d['lines'] if l['role']=='bundle'] == [(225.0, 45.0)]\")\" = True ]"

echo "== offer expiry (rule end date moved into the past on this copy only)"
END=$(sql -e "SELECT date_to FROM $DB.wp_wdr_rules WHERE id=80")
sql -e "UPDATE $DB.wp_wdr_rules SET date_to=UNIX_TIMESTAMP()-60 WHERE id=80"
X=$(run price dd-test-eligible)
t "after the end date the eligible customer sees the normal £225.00 / £270.00" "[ \"\$(echo '$X' | j \"n(d.get('ex')),n(d.get('inc'))\")\" = '£225.00 £270.00' ]"
sql -e "UPDATE $DB.wp_wdr_rules SET date_to=$END WHERE id=80"
t "configured end date is 2027-01-31 23:59:59 Europe/London" "[ \"\$(TZ=Europe/London date -r $END '+%F %T')\" = '2027-01-31 23:59:59' ]"
[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
