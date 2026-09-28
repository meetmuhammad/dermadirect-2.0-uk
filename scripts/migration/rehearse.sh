#!/bin/sh
# End-to-end rehearsal (the executable core of the cutover runbook), with per-step timings.
#   scripts/migration/rehearse.sh <pre-renumber dump.sql.gz> [src_db] [dest_db]
# Restores the destination from the given dump, then: renumber (apply, files, verify, residual,
# rerun no-op) -> headroom -> sync -> application phase -> reconcile -> identical-rerun check.
# Local rehearsal only: every tool refuses non-rehearsal databases.
set -eu
cd "$(dirname "$0")/../.."
DUMP=$1; SRC=${2:-dd_rh_src}; DEST=${3:-dd_rh_dest}
R=./scripts/migration/run.sh
LOG=migration-reports/rehearse-$(date -u +%Y%m%dT%H%M%S).log
T=migration-reports/rehearse-timings.tsv
step() { name=$1; shift; s=$(date +%s); echo "== $name" | tee -a "$LOG"; "$@" >>"$LOG" 2>&1 || { echo "FAILED: $name (see $LOG)"; exit 1; }; d=$(( $(date +%s)-s )); printf '%s\t%s\n' "$name" "$d" >> "$T"; echo "   ${d}s" | tee -a "$LOG"; }
: > "$T"
docker compose stop web >/dev/null 2>&1 || true
step "restore destination from pre-renumber backup" ./scripts/migration/snapshot.sh restore "$DUMP" "$DEST"
step "renumber: audit + apply (one transaction)" $R renumber.php apply --dest="$DEST" --src="$SRC"
step "renumber: files (GenerateBlocks CSS)" $R renumber.php files --dest="$DEST"
step "renumber: mapping-aware verify vs backup" $R renumber.php verify --dest="$DEST" --before=dd_rh_test_prerenumber
step "renumber: residual old-ID scan" $R renumber.php residual --dest="$DEST" --src="$SRC"
step "renumber: rerun is a no-op" $R renumber.php apply --dest="$DEST" --src="$SRC"
step "headroom: raise AUTO_INCREMENT" $R headroom.php apply --dest="$DEST" --src="$SRC"
step "sync: full apply" $R sync.php apply --src="$SRC" --dest="$DEST"
step "application phase" ./scripts/migration/postsync.sh "$DEST"
step "reconcile" $R reconcile.php --src="$SRC" --dest="$DEST" --before=dd_rh_test_presync
step "identical rerun plan (expect zero)" $R sync.php plan --src="$SRC" --dest="$DEST"
echo "timings:"; cat "$T"
