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
# files the import added are removed; files it replaced are put back (recorded by initial-import.sh)
TS=$(basename "$BK" .sql.gz | sed 's/^pre-initial-//'); D=$(dirname "$BK")
if [ -f "$D/files-added-$TS.txt" ]; then
  while read -r f; do case "$f" in web/app/plugins/?*|web/app/uploads/?*) rm -rf "./$f"; echo "   removed $f";; *) echo "   skipped unexpected path $f";; esac; done < "$D/files-added-$TS.txt"
fi
if [ -f "$D/files-replaced-$TS.tar.gz" ]; then
  while read -r f; do case "$f" in web/app/plugins/?*|web/app/uploads/?*) rm -rf "./$f";; esac; done < "$D/files-replaced-$TS.list"
  tar -xzf "$D/files-replaced-$TS.tar.gz"; echo "   put back $(wc -l < "$D/files-replaced-$TS.list") paths the import replaced"
fi
[ -f "$PRIV/last-import" ] && [ "$(cat "$PRIV/last-import")" = "$TS" ] && rm -f "$PRIV/last-import"
wp cache flush; wp rewrite flush; (wp kinsta cache purge --all 2>/dev/null || true)
echo "RESTORED $(basename "$BK")"
