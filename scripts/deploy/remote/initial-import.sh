#!/bin/sh
# Runs ON the Kinsta review server (Action A apply). Replaces the review site's database with the
# migrated package after guards and a backup; preserves hosted staff access and hosted credentials.
#   initial-import.sh <project-root> <expected-url> <package-dir> <private-dir> <mykinsta-backup-label>
set -eu
ROOT=$1; URL=$2; PKG=$3; PRIV=$4; KBK=$5
TS=$(date -u +%Y%m%dT%H%M%SZ); LOG="$PRIV/logs/initial-import-$TS.log"; mkdir -p "$PRIV/logs" "$PRIV/backups"; chmod 700 "$PRIV"
mkdir "$PRIV/lock" 2>/dev/null || { echo "LOCKED: $(cat "$PRIV/lock/owner" 2>/dev/null)"; exit 4; }
echo "initial-import $TS" > "$PRIV/lock/owner"; trap 'rm -rf "$PRIV/lock"' EXIT
cd "$ROOT"
sh "$PKG/remote/remote-guard.sh" "$ROOT" "$URL" initial
(cd "$PKG" && sha256sum -c SHA256SUMS >/dev/null) || { echo "package checksum mismatch"; exit 5; }
[ -f web/app/mu-plugins/dd-migration-safety.php ] && grep -q woocommerce_superpayments_settings web/app/mu-plugins/dd-migration-safety.php || { echo "patched safety harness not deployed — run Action B first"; exit 6; }
P=$(sed -n "s/^DB_PREFIX=['\"]\{0,1\}\([^'\"]*\)['\"]\{0,1\}$/\1/p" .env | tail -1); P=${P:-wp_}
echo "== MyKinsta backup declared by operator: $KBK"
echo "== own database backup"
avail=$(df -Pk "$PRIV" | awk 'NR==2{print $4}'); need=$(( $(stat -c %s "$PKG/db.sql.gz") / 1024 * 4 ))
[ "$avail" -gt "$need" ] || { echo "not enough disk for backup+import (${avail}K free, need ~${need}K)"; exit 7; }
wp db export - --single-transaction --quick | gzip -6 > "$PRIV/backups/pre-initial-$TS.sql.gz"
gzip -t "$PRIV/backups/pre-initial-$TS.sql.gz"; sha256sum "$PRIV/backups/pre-initial-$TS.sql.gz" > "$PRIV/backups/pre-initial-$TS.sql.gz.sha256"
echo "   backup: $PRIV/backups/pre-initial-$TS.sql.gz ($(du -h "$PRIV/backups/pre-initial-$TS.sql.gz" | cut -f1))"
echo "== save hosted staff accounts and hosted credentials/settings"
STAFF=$(wp db query --skip-column-names "SELECT GROUP_CONCAT(user_id) FROM ${P}usermeta WHERE meta_key='${P}capabilities' AND meta_value NOT LIKE '%\"customer\"%' AND meta_value NOT LIKE '%\"blocked_customer\"%' AND meta_value<>'a:0:{}'")
OPTS="option_name IN ('home','siteurl','admin_email','blog_public','wp_mail_smtp','woocommerce_email_from_address') OR option_name LIKE 'woocommerce\\_stripe%' OR option_name LIKE 'wc\\_stripe%' OR option_name LIKE 'woocommerce\\_superpayments%' OR option_name LIKE 'wcsp\\_%' OR option_name LIKE 'omnisend\\_%' OR option_name LIKE 'trackship%' OR option_name LIKE 'derma\\_crm%' OR option_name LIKE 'kinsta%' OR option_name LIKE 'wp\\_mail\\_smtp%'"
[ -n "$STAFF" ] && [ "$STAFF" != NULL ] || { echo "no hosted staff accounts found — refusing (would lock you out)"; exit 8; }
wp db export "$PRIV/backups/hosted-staff-users-$TS.sql" --tables=${P}users --where="ID IN ($STAFF)" --no-create-info=true --replace=true --skip-triggers=true
wp db export "$PRIV/backups/hosted-staff-usermeta-$TS.sql" --tables=${P}usermeta --where="user_id IN ($STAFF)" --no-create-info=true --replace=true --skip-triggers=true
wp db export "$PRIV/backups/hosted-options-$TS.sql" --tables=${P}options --where="$OPTS" --no-create-info=true --replace=true --skip-triggers=true
echo "   staff users preserved: $(echo "$STAFF" | tr ',' '\n' | wc -l)"
echo "== retained plugins + renumbered uploads"
tar -xzf "$PKG/plugins-retained.tar.gz" -C web/app/plugins
cp -Rp "$PKG/uploads/." web/app/uploads/
echo "== import migrated database"
gunzip -c "$PKG/db.sql.gz" | wp db import -
echo "== re-apply hosted staff access and hosted settings"
wp db query "DELETE FROM ${P}usermeta WHERE user_id IN ($STAFF)"
wp db import "$PRIV/backups/hosted-staff-users-$TS.sql"
wp db import "$PRIV/backups/hosted-staff-usermeta-$TS.sql"
wp db import "$PRIV/backups/hosted-options-$TS.sql"
echo "== neutralise integrations (again, after settings re-apply)"
DBN=$(sed -n "s/^DB_NAME=['\"]\{0,1\}\([^'\"]*\)['\"]\{0,1\}$/\1/p" .env | tail -1)
MIG_HOSTED=1 MIG_DOTENV="$ROOT/.env" MIG_REPORT_DIR="$PRIV/reports" php scripts/migration/neutralize.php apply --dest="$DBN" --confirm-dest="$DBN"
echo "== caches (WordPress boots with WP_ENV!=production and the safety harness on)"
wp cache flush; wp rewrite flush; (wp kinsta cache purge --all 2>/dev/null || true)
echo "IMPORTED $(basename "$PKG") at $TS. Restore with: gunzip -c $PRIV/backups/pre-initial-$TS.sql.gz | wp db import -"
