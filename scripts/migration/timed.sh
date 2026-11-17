#!/bin/sh
# Run one step, record its real exit status, and exit with it.
#   scripts/migration/timed.sh <tsv-file> <label> -- <cmd...>
# Appends: label <TAB> start_utc <TAB> seconds <TAB> rc. The status is captured immediately after the
# command (a later $(...) or pipeline would reset $? and report 0 for a failed step).
set -u
[ $# -ge 4 ] && [ "$3" = -- ] || { echo "usage: timed.sh <tsv-file> <label> -- <cmd...>" >&2; exit 2; }
tsv=$1; label=$2; shift 3
start=$(date -u +%Y-%m-%dT%H:%M:%SZ); s=$(date +%s)
rc=0; "$@" || rc=$?
printf '%s\t%s\t%s\t%s\n' "$label" "$start" "$(( $(date +%s) - s ))" "$rc" >> "$tsv" || { echo "timed.sh: cannot write $tsv" >&2; [ "$rc" -ne 0 ] || rc=2; }
exit "$rc"
