#!/bin/sh
# Runs ON the Kinsta review server. Documented rollback of Action A: returns the database to exactly the
# state in a pre-initial backup (same rows AND same table set: tables the import added are dropped).
#   restore-backup.sh <project-root> <expected-url> <private-dir> <backup.sql.gz>
# Refuses once the new site has taken its own orders (remote-guard), on production, and on an
# unverified or incomplete backup. Never run automatically.
set -euo pipefail
ROOT=$1; URL=$2; PRIV=$3; BK=$4
mkdir "$PRIV/lock" 2>/dev/null || { echo "LOCKED: $(cat "$PRIV/lock/owner" 2>/dev/null)"; exit 4; }
echo "restore-backup $(date -u +%FT%TZ)" > "$PRIV/lock/owner"; trap 'rm -rf "$PRIV/lock"' EXIT
cd "$ROOT"
sh "$(dirname "$0")/remote-guard.sh" "$ROOT" "$URL" restore
(cd "$(dirname "$BK")" && sha256sum -c "$(basename "$BK").sha256" >/dev/null) || { echo "backup checksum mismatch or .sha256 missing — refusing"; exit 5; }
gunzip -c "$BK" | tail -c 300 | grep -q -- '-- Dump completed' || { echo "backup incomplete — refusing"; exit 9; }
KEEP=$(gunzip -c "$BK" | sed -n 's/^CREATE TABLE `\([^`]*\)`.*/\1/p' | sort)
[ "$(printf '%s\n' "$KEEP" | grep -c .)" -gt 0 ] || { echo "no tables in backup — refusing"; exit 9; }
echo "== import $(basename "$BK")"
gunzip -c "$BK" | wp db import -
echo "== drop tables absent from the backup"
for t in $(wp db query --skip-column-names "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()"); do
  printf '%s\n' "$KEEP" | grep -qx "$t" || { wp db query "DROP TABLE \`$t\`"; echo "   dropped $t"; }
done
wp cache flush; wp rewrite flush; (wp kinsta cache purge --all 2>/dev/null || true)
echo "RESTORED $(basename "$BK")"
