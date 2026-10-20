#!/bin/sh
# No-DB regression test: dest-fingerprint.sh must refuse NULL/empty/non-numeric/mismatched CHECKSUM TABLE
# answers and malformed row fingerprints, and --validate must refuse such files even when both are identical.
#   sh scripts/deploy/tests/fingerprint_null_test.sh
set -u
FP=$(cd "$(dirname "$0")/../remote" && pwd)/dest-fingerprint.sh
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
mkdir "$W/bin"; echo "DB_PREFIX=wp_" > "$W/.env"
# fake wp: answers from $FAKE_CS (CHECKSUM TABLE row) and $FAKE_ROWS (options/usermeta count:sum)
cat > "$W/bin/wp" <<'EOF'
#!/bin/sh
q=$(cat)
case "$q" in
 *information_schema.tables*) printf 'wp_posts\n';;
 *'CHECKSUM TABLE'*) printf '%b' "$FAKE_CS";;
 *'CONCAT(COUNT'*) printf '%s\n' "$FAKE_ROWS";;
 *) exit 1;;
esac
EOF
chmod +x "$W/bin/wp"
fails=0
run() { PATH="$W/bin:$PATH" FAKE_CS=$1 FAKE_ROWS=$2 bash "$FP" "$W" > "$W/out.tsv" 2> "$W/err"; }
expect() { # name want(0|nonzero) cs rows
  run "$3" "$4"; rc=$?
  if { [ "$2" = 0 ] && [ $rc = 0 ]; } || { [ "$2" != 0 ] && [ $rc != 0 ]; }; then echo "ok   $1 (rc=$rc)"; else echo "FAIL $1 (rc=$rc) $(cat "$W/err")"; fails=$((fails+1)); fi
}
expect "NULL checksum refused"            nonzero 'fixture.wp_posts\tNULL\n' 1:123
expect "empty checksum refused"           nonzero 'fixture.wp_posts\t\n'     1:123
expect "non-numeric checksum refused"     nonzero 'fixture.wp_posts\t12ab\n' 1:123
expect "negative checksum refused"        nonzero 'fixture.wp_posts\t-5\n'   1:123
expect "wrong table name refused"         nonzero 'fixture.wp_users\t42\n'   1:123
expect "two rows refused"                 nonzero 'fixture.wp_posts\t42\nfixture.wp_posts\t43\n' 1:123
expect "malformed options/usermeta refused" nonzero 'fixture.wp_posts\t42\n' NULL
expect "bare table name accepted"         0 'wp_posts\t42\n' 1:123
expect "valid db-qualified accepted"      0 'fixture.wp_posts\t42\n' 1:123
want=$(printf 'wp_posts\tchecksum\t42\nwp_options\trows\t1:123\nwp_usermeta\trows\t1:123\n')
[ "$(head -3 "$W/out.tsv")" = "$want" ] && echo "ok   valid output TSV" || { echo "FAIL valid output TSV: $(head -3 "$W/out.tsv")"; fails=$((fails+1)); }
bash "$FP" --validate "$W/out.tsv" "$W/out.tsv" && echo "ok   --validate accepts a valid file" || { echo "FAIL --validate rejected a valid file"; fails=$((fails+1)); }
# identical files containing NULL (the reviewer's reproduction) must still be refused
sed 's/\tchecksum\t42$/\tchecksum\tNULL/' "$W/out.tsv" > "$W/null.tsv"; cp "$W/null.tsv" "$W/null2.tsv"
grep -q NULL "$W/null.tsv" || { echo "FAIL fixture: NULL not injected"; fails=$((fails+1)); }
v() { name=$1; shift; if bash "$FP" --validate "$@" 2>/dev/null; then echo "FAIL $name"; fails=$((fails+1)); else echo "ok   $name"; fi; }
v "--validate refuses identical files containing NULL" "$W/null.tsv" "$W/null2.tsv"
v "--validate refuses when either file is invalid" "$W/out.tsv" "$W/null.tsv"
: > "$W/empty.tsv"; v "--validate refuses an empty file" "$W/empty.tsv"
grep -v usermeta "$W/out.tsv" > "$W/short.tsv"; v "--validate refuses a file missing a row set" "$W/short.tsv"
grep -v checksum "$W/out.tsv" > "$W/nocs.tsv"; v "--validate refuses a file with no checksums" "$W/nocs.tsv"
v "--validate refuses a missing file" "$W/nope.tsv"
v "--validate with no file refused"
echo; [ $fails = 0 ] && echo "ALL PASSED" || echo "$fails FAILED"
[ $fails = 0 ]
