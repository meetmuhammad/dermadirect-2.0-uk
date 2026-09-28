#!/bin/sh
# Runs ON the Kinsta review server (Action A apply). Replaces the review site's database with the
# migrated package after guards and a backup; preserves hosted staff access and hosted credentials.
#   initial-import.sh <project-root> <expected-url> <package-dir> <private-dir> <mykinsta-backup-label>
set -euo pipefail
ROOT=$1; URL=$2; PKG=$3; PRIV=$4; KBK=$5
# Accept a dump only if complete: trailer present and at least $2 CREATE TABLE statements.
dump_ok() { gunzip -c "$1" | tail -c 300 | grep -q -- '-- Dump completed' && [ "$(gunzip -c "$1" | grep -c '^CREATE TABLE' || true)" -ge "$2" ]; }
TS=$(date -u +%Y%m%dT%H%M%SZ); LOG="$PRIV/logs/initial-import-$TS.log"; mkdir -p "$PRIV/logs" "$PRIV/backups"; chmod 700 "$PRIV"
mkdir "$PRIV/lock" 2>/dev/null || { echo "LOCKED: $(cat "$PRIV/lock/owner" 2>/dev/null)"; exit 4; }
echo "initial-import $TS" > "$PRIV/lock/owner"; trap 'rm -rf "$PRIV/lock"' EXIT
cd "$ROOT"
sh "$PKG/remote/remote-guard.sh" "$ROOT" "$URL" initial
[ ! -e "$PKG/SUPERSEDED" ] || { echo "REFUSED: package is superseded — $(head -1 "$PKG/SUPERSEDED")"; exit 11; }
(cd "$PKG" && sha256sum -c SHA256SUMS >/dev/null) || { echo "package checksum mismatch"; exit 5; }
[ -f web/app/mu-plugins/dd-migration-safety.php ] && grep -q woocommerce_superpayments_settings web/app/mu-plugins/dd-migration-safety.php || { echo "patched safety harness not deployed — run Action B first"; exit 6; }
P=$(sed -n "s/^DB_PREFIX=['\"]\{0,1\}\([^'\"]*\)['\"]\{0,1\}$/\1/p" .env | tail -1); P=${P:-wp_}
DBN=$(wp db query --skip-column-names "SELECT DATABASE()")   # what WordPress actually uses (Kinsta SERVER_SECRET_*)
mig() { MIG_HOSTED=1 MIG_BEDROCK_ROOT="$ROOT" MIG_REPORT_DIR="$PRIV/reports" php scripts/migration/"$@" --dest="$DBN" --confirm-dest="$DBN"; }
echo "== hosted staff: save, then plan against the package by identity (before any change)"
[ -f "$PKG/users-identity.tsv" ] || { echo "package has no users-identity.tsv — refusing (nothing was changed)"; exit 10; }
mig restore-staff.php save --out="$PRIV/backups/hosted-staff-$TS.json"
mig restore-staff.php plan --staff="$PRIV/backups/hosted-staff-$TS.json" --identity="$PKG/users-identity.tsv" --out="$PRIV/backups/staff-plan-$TS.json" \
  || { echo "STAFF IDENTITY CONFLICT — refusing (nothing was changed); resolve the listed accounts first"; exit 10; }
echo "== MyKinsta backup declared by operator: $KBK"
echo "== own database backup"
avail=$(df -Pk "$PRIV" | awk 'NR==2{print $4}'); need=$(( $(stat -c %s "$PKG/db.sql.gz") / 1024 * 4 ))
[ "$avail" -gt "$need" ] || { echo "not enough disk for backup+import (${avail}K free, need ~${need}K)"; exit 7; }
NT=$(wp db query --skip-column-names "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")
# A failed or partial export is renamed so it can never be picked as "the latest backup" in a rollback.
if ! wp db export - --single-transaction --quick | gzip -6 > "$PRIV/backups/pre-initial-$TS.sql.gz" || ! dump_ok "$PRIV/backups/pre-initial-$TS.sql.gz" "$NT"; then
  mv "$PRIV/backups/pre-initial-$TS.sql.gz" "$PRIV/backups/INCOMPLETE-pre-initial-$TS.sql.gz"
  echo "BACKUP INCOMPLETE — refusing to continue (nothing was changed)"; exit 9
fi
sha256sum "$PRIV/backups/pre-initial-$TS.sql.gz" > "$PRIV/backups/pre-initial-$TS.sql.gz.sha256"
echo "   backup: $PRIV/backups/pre-initial-$TS.sql.gz ($(du -h "$PRIV/backups/pre-initial-$TS.sql.gz" | cut -f1))"
echo "== save retained hosted settings"
# Settings policy on the review site:
#  RETAIN from the hosted site (destination settings, credentials, integration endpoints): the RETAIN list.
#    Live payment/marketing/shipping credentials are kept as stored but stay BLOCKED at runtime: the safety
#    harness forces test mode, blanks keys in memory and blocks outbound HTTP and mail; webhooks are paused
#    and queued actions cancelled below. initial-verify.sh fails if any of that is not true.
#  TRANSFORM for this environment: home and siteurl are set to the expected review URL (never restored).
#  Everything else comes from the package (migrated data + the new site's content).
RETAIN="option_name IN ('admin_email','blog_public','wp_mail_smtp','woocommerce_email_from_address') OR option_name LIKE 'woocommerce\\_stripe%' OR option_name LIKE 'wc\\_stripe%' OR option_name LIKE 'woocommerce\\_superpayments%' OR option_name LIKE 'wcsp\\_%' OR option_name LIKE 'omnisend\\_%' OR option_name LIKE 'trackship%' OR option_name LIKE 'derma\\_crm%' OR option_name LIKE 'kinsta%' OR option_name LIKE 'wp\\_mail\\_smtp%'"
wp db export "$PRIV/backups/hosted-options-$TS.sql" --tables=${P}options --where="$RETAIN" --no-create-info=true --replace=true --skip-triggers=true
tail -c 300 "$PRIV/backups/hosted-options-$TS.sql" | grep -q -- '-- Dump completed' || { echo "export hosted-options incomplete — refusing (nothing was changed)"; exit 9; }
echo "== retained plugins + renumbered uploads (added/replaced files recorded for restore-backup.sh)"
: > "$PRIV/backups/files-added-$TS.txt"; : > "$PRIV/backups/files-replaced-$TS.list"
for d in $(tar -tzf "$PKG/plugins-retained.tar.gz" | cut -d/ -f1 | sort -u); do
  if [ -e "web/app/plugins/$d" ]; then echo "web/app/plugins/$d" >> "$PRIV/backups/files-replaced-$TS.list"; else echo "web/app/plugins/$d" >> "$PRIV/backups/files-added-$TS.txt"; fi
done
(cd "$PKG/uploads" && find . -type f | sed 's#^\./##') | while read -r f; do
  if [ -e "web/app/uploads/$f" ]; then echo "web/app/uploads/$f" >> "$PRIV/backups/files-replaced-$TS.list"; else echo "web/app/uploads/$f" >> "$PRIV/backups/files-added-$TS.txt"; fi
done
[ -s "$PRIV/backups/files-replaced-$TS.list" ] && tar -czf "$PRIV/backups/files-replaced-$TS.tar.gz" -T "$PRIV/backups/files-replaced-$TS.list"
tar -xzf "$PKG/plugins-retained.tar.gz" -C web/app/plugins
cp -Rp "$PKG/uploads/." web/app/uploads/
echo "== import migrated database"
gunzip -c "$PKG/db.sql.gz" | wp db import -
echo "== restore hosted staff by identity (never over a migrated account)"
mig restore-staff.php apply --staff="$PRIV/backups/hosted-staff-$TS.json" --expect="$PRIV/backups/staff-plan-$TS.json"
echo "== retained hosted settings, then review-environment URLs"
wp db import "$PRIV/backups/hosted-options-$TS.sql"
wp db query "UPDATE ${P}options SET option_value='$URL' WHERE option_name IN ('home','siteurl')"
echo "== neutralise integrations (again, after settings re-apply)"
mig neutralize.php apply
# every action that exists now came with the package and is cancelled; anything newer is self-scheduled by
# plugins when WordPress boots (initial-verify.sh lists those; the harness keeps every runner off)
wp db query --skip-column-names "SELECT COALESCE(MAX(action_id),0) FROM ${P}actionscheduler_actions" > "$PRIV/backups/max-action-$TS.txt"
echo "== caches (WordPress boots with WP_ENV!=production and the safety harness on)"
wp cache flush; wp rewrite flush; (wp kinsta cache purge --all 2>/dev/null || true)
echo "$TS" > "$PRIV/last-import"
echo "IMPORTED $(basename "$PKG") at $TS. Roll back with: bash $PKG/remote/restore-backup.sh $ROOT $URL $PRIV $PRIV/backups/pre-initial-$TS.sql.gz"
