#!/bin/sh
# No-DB regression test: timing wrappers must report and return the real exit status of the command.
#   sh scripts/migration/tests/exit_status_test.sh     (re-runs itself under sh, bash, dash and zsh if present)
set -u
D=$(cd "$(dirname "$0")/.." && pwd)
if [ -z "${EXIT_TEST_SHELL:-}" ]; then
  all=0
  for s in sh bash dash zsh; do
    command -v $s >/dev/null || continue
    echo "== $s"; EXIT_TEST_SHELL=$s $s "$0" || all=1
  done
  echo; [ $all = 0 ] && echo "ALL PASSED (all shells)" || echo "FAILED in at least one shell"
  exit $all
fi
SH=$EXIT_TEST_SHELL
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
fails=0
ok() { echo "ok   $1"; }; bad() { echo "FAIL $1"; fails=$((fails+1)); }
tcase() { # label want_rc cmd...
  label=$1; want=$2; shift 2
  $SH "$D/timed.sh" "$W/t.tsv" "$label" -- "$@"; rc=$?
  row=$(tail -1 "$W/t.tsv")
  rec=$(printf '%s\n' "$row" | awk -F'\t' '{print $4}')
  nf=$(printf '%s\n' "$row" | awk -F'\t' '{print NF}')
  [ "$rc" = "$want" ] && [ "$rec" = "$want" ] && [ "$nf" = 4 ] && printf '%s\n' "$row" | grep -q "^$label	[0-9-]*T[0-9:]*Z	[0-9]*	" \
    && ok "timed.sh $label: exit $rc, recorded $rec" || bad "timed.sh $label: exit $rc, row '$row' (want $want)"
}
tcase false 1 false
tcase exit7 7 sh -c 'exit 7'
tcase true 0 true
$SH "$D/timed.sh" "$W/t.tsv" nodash false 2>/dev/null; [ $? = 2 ] && ok "timed.sh without -- is a usage error" || bad "timed.sh without -- not refused"
# the reproduced bug: a $(...) between the command and $? reports 0 for a failed step
$SH -c 'false; s=$(date +%s); echo "$?"' | grep -qx 0 && ok "(control) command substitution resets \$? as reported"
# postsync.sh t(): extract the helper verbatim and run it under set -e like postsync does
T=$(grep '^t() ' "$D/postsync.sh"); [ -n "$T" ] || bad "t() not found in postsync.sh"
$SH -c "set -eu; $T"' ; t sh -c "exit 5"; echo "not reached"' > "$W/o" 2>&1; rc=$?
[ $rc = 5 ] && ! grep -q "not reached" "$W/o" && grep -q "rc=5" "$W/o" && ok "postsync t(): failing step returns 5 and stops the script" || bad "postsync t(): rc=$rc $(cat "$W/o")"
$SH -c "set -eu; $T"' ; t false || echo "reported $?"' > "$W/o" 2>&1
grep -q "reported 1" "$W/o" && ok "postsync t(): failure visible to a '|| handler' (HPOS verify path)" || bad "postsync t(): handler saw $(cat "$W/o")"
$SH -c "set -eu; $T"' ; t true; echo "continued"' > "$W/o" 2>&1
grep -q continued "$W/o" && grep -q "rc=0" "$W/o" && ok "postsync t(): success returns 0" || bad "postsync t(): success $(cat "$W/o")"
[ $fails = 0 ] && echo "PASSED ($SH)" || echo "$fails FAILED ($SH)"
[ $fails = 0 ]
