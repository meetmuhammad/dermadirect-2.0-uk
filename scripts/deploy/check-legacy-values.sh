#!/bin/sh
# Proves packaging did not alter legacy-owned values (the corrected legacy domain, email addresses and
# historical commerce text). Compares every legacy-owned row of the package copy with the same row
# (same primary key) on the canonical local site. The ONLY accepted difference is the recorded
# transformation "this site's own address -> the review address" (plain or JSON-escaped, with PHP
# serialized lengths adjusted), e.g. order GUIDs and order-attribution landing pages.
# Rows on one side only are listed by meta key; only session_tokens (sessions are cleared) and
# _edit_lock (editor lock) may differ that way.
#   scripts/deploy/check-legacy-values.sh <canonical_db> <package_db> <canonical_home> <review_home>
set -eu
A=$1; B=$2; FROM=$3; TO=$4
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
fails=0; t() { if [ "$2" = "$3" ]; then echo "   ok   $1${4:+ ($4)}"; else echo "   FAIL $1 (expected $2, got $3)"; fails=$((fails+1)); fi; }
FJ=$(printf '%s' "$FROM" | sed 's#/#\\\\\\\\/#g'); TJ=$(printf '%s' "$TO" | sed 's#/#\\\\\\\\/#g')
# normalise: review address -> canonical address, then drop serialized string lengths
n() { echo "REGEXP_REPLACE(REPLACE(REPLACE($1,'$TO','$FROM'),'$TJ','$FJ'),'s:[0-9]+:\"','s:\"')"; }
COM="SELECT ID FROM $B.wp_posts WHERE post_type IN ('shop_order','shop_order_refund','shop_coupon')"
CUS="SELECT user_id FROM $B.wp_usermeta WHERE meta_key='wp_capabilities' AND (meta_value LIKE '%\"customer\"%' OR meta_value LIKE '%\"blocked_customer\"%')"
# table | primary key | compared columns | legacy-owned rows | key column for one-sided rows
for d in "wp_posts|ID|post_author,post_date_gmt,post_content,post_title,post_excerpt,post_status,post_password,post_name,post_parent,guid,post_type|ID IN ($COM)|post_type" \
         "wp_postmeta|meta_id|post_id,meta_key,meta_value|post_id IN ($COM)|meta_key" \
         "wp_comments|comment_ID|comment_post_ID,comment_author,comment_author_email,comment_author_url,comment_date_gmt,comment_content,comment_type|comment_post_ID IN ($COM)|comment_type" \
         "wp_users|ID|user_login,user_pass,user_email,user_url,display_name|ID IN ($CUS)|'user'" \
         "wp_usermeta|umeta_id|user_id,meta_key,meta_value|user_id IN ($CUS)|meta_key"; do
  IFS='|' read -r tb pk cols w key <<EOF
$d
EOF
  wa=$(echo "$w" | sed "s/^\([a-zA-Z_]*\)/a.\1/"); wb=$(echo "$w" | sed "s/^\([a-zA-Z_]*\)/b.\1/")
  exact=""; norm=""
  for c in $(echo "$cols" | tr ',' ' '); do exact="$exact OR NOT (a.$c <=> b.$c)"; norm="$norm OR NOT ($(n "a.$c") <=> $(n "b.$c"))"; done
  changed=$(sql -e "SELECT COUNT(*) FROM $A.$tb a JOIN $B.$tb b ON b.$pk=a.$pk WHERE $wb AND (0 $exact)")
  other=$(sql -e "SELECT COUNT(*) FROM $A.$tb a JOIN $B.$tb b ON b.$pk=a.$pk WHERE $wb AND (0 $norm)")
  t "$tb: no legacy-owned value changed except the recorded address substitution" 0 "$other" "address substituted in $changed rows"
  onlyA=$(sql -e "SELECT COALESCE(GROUP_CONCAT(CONCAT(k,'=',n)),'') FROM (SELECT $key k, COUNT(*) n FROM $A.$tb a WHERE $wa AND NOT EXISTS (SELECT 1 FROM $B.$tb b WHERE b.$pk=a.$pk) GROUP BY 1) z")
  onlyB=$(sql -e "SELECT COALESCE(GROUP_CONCAT(CONCAT(k,'=',n)),'') FROM (SELECT $key k, COUNT(*) n FROM $B.$tb b WHERE $wb AND NOT EXISTS (SELECT 1 FROM $A.$tb a WHERE a.$pk=b.$pk) GROUP BY 1) z")
  bad=$(printf '%s\n%s\n' "$onlyA" "$onlyB" | tr ',' '\n' | grep -v -e '^$' -e '^session_tokens=' -e '^_edit_lock=' || true)
  t "$tb: no legacy-owned row added or removed (sessions/editor locks aside)" "" "$bad" "canonical-only: ${onlyA:-none}; package-only: ${onlyB:-none}"
done
t "order notes mentioning @dermadirect.co.uk unchanged" "$(sql -e "SELECT COUNT(*) FROM $A.wp_comments WHERE comment_post_ID IN ($COM) AND comment_content LIKE '%@dermadirect.co.uk%'")" "$(sql -e "SELECT COUNT(*) FROM $B.wp_comments WHERE comment_post_ID IN ($COM) AND comment_content LIKE '%@dermadirect.co.uk%'")"
t "email fields containing a review/local host" 0 "$(sql -e "SELECT (SELECT COUNT(*) FROM $B.wp_users WHERE user_email REGEXP 'kinsta\\\\.cloud|localhost|httpsdermadirectcom\\\\.local' AND ID IN ($CUS)) + (SELECT COUNT(*) FROM $B.wp_postmeta WHERE meta_key='_billing_email' AND meta_value REGEXP 'kinsta\\\\.cloud|localhost|httpsdermadirectcom\\\\.local') + (SELECT COUNT(*) FROM $B.wp_comments WHERE comment_post_ID IN ($COM) AND comment_author_email REGEXP 'kinsta\\\\.cloud|localhost')")"
[ $fails = 0 ] && echo "LEGACY VALUES INTACT" || { echo "LEGACY VALUES CHANGED BY PACKAGING: $fails"; exit 1; }
