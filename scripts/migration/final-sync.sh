#!/bin/sh
# Action C — final commerce synchronisation (legacy -> new site), selective and repeatable.
# The source is always a lgcy_-prefixed copy of the legacy tables plus a lgcy_dd_snapshot identity
# table; the same files and tools are used locally and on Kinsta (where it lives inside the site DB).
#
# LOCAL (preparation / rehearsal against a copy of the destination):
#   final-sync.sh snapshot  <label> [--from-dump=<f>|--from-legacy-kinsta]  legacy -> recovery/final-sync/<label>/
#                           default source: the DevKinsta legacy DB (pull a fresh copy in DevKinsta first);
#                           --from-legacy-kinsta: read-only export over SSH from legacy Kinsta (use after the freeze)
#   final-sync.sh backup    <label> [--dest=<db>|--hosted]           verified pre-apply backup (required by apply)
#   final-sync.sh pull-dest <label>                                  read-only copy of the hosted DB -> dd_rh_dest_<label>
#   final-sync.sh preflight <label> [--dest=<db>]
#   final-sync.sh plan      <label> [--dest=<db>]                    dry run, writes nothing
#   final-sync.sh apply     <label> [--dest=<db>] --confirm-dest=<db> --backup=<dump.sql.gz>
#   final-sync.sh verify    <label> [--dest=<db>]                    application phase + independent reconcile
#   final-sync.sh resume    <label> ...                              = apply again (idempotent)
# HOSTED (Kinsta review/new site; same commands with --hosted):
#   final-sync.sh upload    <label> --hosted                         source package -> server private dir, loads lgcy_* tables
#   final-sync.sh preflight|plan|apply|verify|resume <label> --hosted [--confirm-dest=<db>]
#   final-sync.sh drop-source <label> --hosted --confirm-dest=<db>   remove lgcy_* tables after acceptance
# Every report includes the source snapshot identity (label, legacy DB, export time, sha256, counts,
# table checksums). apply refuses without a fresh verified backup and an exact --confirm-dest.
set -eu
cd "$(dirname "$0")/../.."
CMD=${1:?command}; LABEL=${2:?label}; shift 2
HOSTED=""; DEST=""; CONFIRM=""; ISOLATE=""; PROFILE=verbatim; BACKUP=""; FROMDUMP=""; FROMLEGACY=""
for a in "$@"; do case "$a" in
  --hosted) HOSTED=1;; --with-uploads) ;; --isolate-product-conflicts) ISOLATE=--isolate-product-conflicts;; --profile=*) PROFILE=${a#*=};; --dest=*) DEST=${a#*=};; --confirm-dest=*) CONFIRM=${a#*=};; --backup=*) BACKUP=${a#*=};; --from-dump=*) FROMDUMP=${a#*=};; --from-legacy-kinsta) FROMLEGACY=1;;
  *) echo "unknown option $a"; exit 2;; esac; done
DIR=recovery/final-sync/$LABEL; mkdir -p "$DIR/reports"; chmod 700 recovery/final-sync "$DIR"
C=dermadirect20uk-db-1; SRCDB=dd_rh_src_$(echo "$LABEL" | tr -c 'a-z0-9_\n' '_'); PFX=lgcy_
sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
# Tables the sync and reconcile read (nothing else leaves the legacy site). Normalised to single
# spaces: membership tests below rely on " name " matching.
TABLES=$(echo "posts postmeta users usermeta comments commentmeta woocommerce_order_items woocommerce_order_itemmeta terms term_taxonomy term_relationships
 wc_order_stats wc_order_product_lookup wc_order_tax_lookup wc_order_coupon_lookup wc_customer_lookup woocommerce_payment_tokens woocommerce_payment_tokenmeta
 webis_pbet webis_pbet_order webis_pbet_order_item woocommerce_bis_notifications woocommerce_bis_notificationsmeta woocommerce_bis_activity
 gens_raf gens_rafmeta gens_raf_guest_referrals wt_sc_coupon_lookup wdr_order_item_discounts pp_order_actions_log trackship_shipment
 trackship_shipment_meta zorem_email_sms_log stock_log woocommerce_downloadable_product_permissions wdr_order_discounts options" | tr -s ' \n\t' '   ')
# wp_options is reduced to config.php 'source_options' (role definitions, versions, home): no credentials.
SRCOPTS=$(docker compose --profile cli run --rm -T --no-deps --entrypoint php wpcli -r 'echo "\x27" . implode("\x27,\x27", (require "scripts/migration/config.php")["source_options"]) . "\x27";' 2>/dev/null)
if [ -n "$HOSTED" ]; then
  . ./.deploy-targets
  SSH="ssh -p $KINSTA_REVIEW_PORT -o BatchMode=yes $KINSTA_REVIEW_SSH"; R=$KINSTA_REVIEW_ROOT; PRIV=$KINSTA_REVIEW_PRIVATE; RD=$PRIV/final-sync/$LABEL
  HDB=$($SSH "cd '$R' && wp db query --skip-column-names 'SELECT DATABASE()'")   # the DB WordPress uses (Kinsta SERVER_SECRET_*)
  DEST=$HDB
  hphp() { $SSH "cd '$R' && MIG_HOSTED=1 MIG_BEDROCK_ROOT='$R' MIG_REPORT_DIR='$RD/reports' php scripts/migration/$*"; }
  hwp() { $SSH "cd '$R' && wp $*"; }
fi
case "$CMD" in snapshot|pull-dest|upload) ;; *) [ -n "$HOSTED" ] || [ -n "$DEST" ] || { echo "$CMD needs an explicit --dest=<db> (or --hosted)"; exit 2; };; esac
SRCARGS="--src=${HOSTED:+$DEST}${HOSTED:-$SRCDB} --src-prefix=$PFX --dest=$DEST"
# Commerce values are copied byte for byte (profile verbatim) unless another profile is named explicitly.
SYNCARGS="$SRCARGS --profile=$PROFILE $ISOLATE"
run() { # tool args... (local: docker runner; hosted: php over SSH)
  if [ -n "$HOSTED" ]; then hphp "$* --confirm-dest=$DEST"; else ./scripts/migration/run.sh "$@"; fi
}
pull_reports() { [ -n "$HOSTED" ] && scp -q -P "$KINSTA_REVIEW_PORT" "$KINSTA_REVIEW_SSH:$RD/reports/*" "$DIR/reports/" 2>/dev/null || true; [ -z "$HOSTED" ] && cp migration-reports/sync-*.json migration-reports/reconcile-*.json "$DIR/reports/" 2>/dev/null || true; }

case "$CMD" in
snapshot)
  # 1) consistent dump of the allowlisted legacy tables (DevKinsta DB, or a given dump), 2) load into a
  # local schema, 3) rename to lgcy_*, 4) add identity table, 5) dump that as the portable source package.
  TS=$(date -u +%FT%TZ)
  sql -e "DROP DATABASE IF EXISTS \`$SRCDB\`; CREATE DATABASE \`$SRCDB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  if [ -n "$FROMDUMP" ]; then
    shasum -a 256 -c "$FROMDUMP.sha256" >/dev/null; SHA=$(cut -d' ' -f1 "$FROMDUMP.sha256"); ORIGIN="dump:$(basename "$FROMDUMP")"
    gunzip -c "$FROMDUMP" | docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$SRCDB"
  elif [ -n "$FROMLEGACY" ]; then
    # Read-only on production: wp db query/export only (WP-CLI loads wp-config, never WordPress or
    # plugins), compressed on the server, streamed over SSH; no file is written on the server.
    # One mysqldump --single-transaction over InnoDB tables = one consistent point, no table locks.
    . ./.deploy-targets   # LEGACY_SSH / LEGACY_PORT / LEGACY_ROOT (read-only use)
    LS="ssh -p ${LEGACY_PORT:?} -o BatchMode=yes ${LEGACY_SSH:?}"
    lq() { $LS "cd '${LEGACY_ROOT:?}' && wp db query --skip-column-names \"$1\" 2>/dev/null"; }
    WT=""; for t in $TABLES; do [ "$t" = options ] || WT="$WT,wp_$t"; done
    HOME_URL=$(lq "SELECT option_value FROM wp_options WHERE option_name='home'"); THEME=$(lq "SELECT option_value FROM wp_options WHERE option_name='stylesheet'")
    IDENT="$($LS hostname) $(lq "SELECT CONCAT(DATABASE(),' / ',@@hostname,' / ',@@version)")"
    NONINNODB=$(lq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('$(echo "${WT#,}" | sed "s/,/','/g")') AND engine<>'InnoDB'")
    [ "$NONINNODB" = 0 ] || { echo "non-InnoDB source tables ($NONINNODB): a single-transaction export would not be consistent — refusing"; exit 1; }
    T0=$(date -u +%FT%TZ)
    $LS "cd '$LEGACY_ROOT' && set -o pipefail && wp db export - --tables=${WT#,} --single-transaction --quick --skip-triggers --hex-blob --default-character-set=utf8mb4 | gzip -1" > "$DIR/legacy-raw.sql.gz"
    T1=$(date -u +%FT%TZ)
    $LS "cd '$LEGACY_ROOT' && set -o pipefail && wp db export - --tables=wp_options --where=\"option_name IN ($SRCOPTS)\" --single-transaction --skip-triggers --hex-blob --default-character-set=utf8mb4 | gzip -1" > "$DIR/legacy-options.sql.gz"
    chmod 600 "$DIR"/legacy-*.sql.gz
    for f in legacy-raw legacy-options; do gunzip -t "$DIR/$f.sql.gz" && gunzip -c "$DIR/$f.sql.gz" | tail -c 300 | grep -q -- '-- Dump completed' || { echo "$f dump incomplete — refusing"; exit 1; }; done
    [ "$(gunzip -c "$DIR/legacy-raw.sql.gz" | grep -c '^CREATE TABLE')" -ge "$(echo "${WT#,}" | tr ',' '\n' | grep -c .)" ] || { echo "raw dump is missing tables — refusing"; exit 1; }
    shasum -a 256 "$DIR/legacy-raw.sql.gz" > "$DIR/legacy-raw.sql.gz.sha256"; shasum -a 256 "$DIR/legacy-options.sql.gz" > "$DIR/legacy-options.sql.gz.sha256"
    SHA=$(cut -d' ' -f1 "$DIR/legacy-raw.sql.gz.sha256"); OSHA=$(cut -d' ' -f1 "$DIR/legacy-options.sql.gz.sha256"); ORIGIN="legacy-kinsta:$LEGACY_SSH"
    METHOD="mysqldump via wp db export --single-transaction --quick (InnoDB, no locks); options rows exported separately ($OSHA)"
    gunzip -c "$DIR/legacy-raw.sql.gz" | docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$SRCDB"
    gunzip -c "$DIR/legacy-options.sql.gz" | docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$SRCDB"
  else
    LP=$(sed -n "s/.*'DB_PASSWORD', *'\([^']*\)'.*/\1/p" /Users/Ammar/development/DevKinsta/public/httpsdermadirectcom/wp-config.php)
    LDB=$(sed -n "s/.*'DB_NAME', *'\([^']*\)'.*/\1/p" /Users/Ammar/development/DevKinsta/public/httpsdermadirectcom/wp-config.php)
    WT=""; for t in $TABLES; do WT="$WT wp_$t"; done
    HOME_URL=$(docker exec -e MYSQL_PWD="$LP" devkinsta_db mysql -uroot -N -e "SELECT option_value FROM $LDB.wp_options WHERE option_name='home'")
    THEME=$(docker exec -e MYSQL_PWD="$LP" devkinsta_db mysql -uroot -N -e "SELECT option_value FROM $LDB.wp_options WHERE option_name='stylesheet'")
    docker exec -e MYSQL_PWD="$LP" devkinsta_db sh -c "mysqldump -uroot --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --skip-triggers $LDB $WT" | gzip -1 > "$DIR/legacy-raw.sql.gz"
    SHA=$(shasum -a 256 "$DIR/legacy-raw.sql.gz" | cut -d' ' -f1); ORIGIN="devkinsta:$LDB"
    gunzip -c "$DIR/legacy-raw.sql.gz" | docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$SRCDB"
    rm -f "$DIR/legacy-raw.sql.gz"
  fi
  # Whatever the origin, only the allowlisted option rows stay in the source (no credentials).
  [ -n "$(sql -e "SELECT 1 FROM information_schema.tables WHERE table_schema='$SRCDB' AND table_name='wp_options'")" ] && sql -e "DELETE FROM \`$SRCDB\`.wp_options WHERE option_name NOT IN ($SRCOPTS)"
  [ -n "${HOME_URL:-}" ] || HOME_URL=$(sql -e "SELECT option_value FROM \`$SRCDB\`.wp_options WHERE option_name='home'" 2>/dev/null || true)
  [ -n "${THEME:-}" ] || THEME=$(sql -e "SELECT option_value FROM \`$SRCDB\`.wp_options WHERE option_name='stylesheet'" 2>/dev/null || true)
  [ -n "$HOME_URL" ] || { echo "cannot determine the legacy home URL of the source — refusing"; exit 1; }
  case "$THEME" in porto*) ;; *) echo "source stylesheet is '$THEME', not the legacy Porto theme — refusing"; exit 1;; esac
  for t in $(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$SRCDB'"); do
    case " $TABLES " in *" ${t#wp_} "*) sql -e "RENAME TABLE \`$SRCDB\`.\`$t\` TO \`$SRCDB\`.\`$PFX${t#wp_}\`";; *) sql -e "DROP TABLE \`$SRCDB\`.\`$t\`";; esac
  done
  for t in $TABLES; do [ -n "$(sql -e "SELECT 1 FROM information_schema.tables WHERE table_schema='$SRCDB' AND table_name='$PFX$t'")" ] || { echo "snapshot is missing legacy table wp_$t — refusing"; exit 1; }; done
  sql -e "CREATE TABLE \`$SRCDB\`.${PFX}dd_snapshot (k VARCHAR(64) PRIMARY KEY, v TEXT) ENGINE=InnoDB;
    INSERT INTO \`$SRCDB\`.${PFX}dd_snapshot VALUES ('label','$LABEL'),('origin','$ORIGIN'),('exported_at_utc','$TS'),('raw_sha256','$SHA'),
    ('legacy_home','$HOME_URL'),('legacy_stylesheet','${THEME:-unknown}'),
    ('export_started_utc','${T0:-}'),('export_finished_utc','${T1:-}'),('source_db_identity','${IDENT:-}'),('snapshot_method','${METHOD:-local copy}'),
    ('max_user_id',(SELECT MAX(ID) FROM \`$SRCDB\`.${PFX}users)),('max_post_id',(SELECT MAX(ID) FROM \`$SRCDB\`.${PFX}posts)),
    ('max_order_id',(SELECT MAX(ID) FROM \`$SRCDB\`.${PFX}posts WHERE post_type IN ('shop_order','shop_order_refund'))),
    ('latest_order',(SELECT MAX(post_date) FROM \`$SRCDB\`.${PFX}posts WHERE post_type='shop_order'));"
  sql -e "GRANT SELECT ON \`$SRCDB\`.* TO dd_migrate@'%'" 2>/dev/null || true
  docker exec "$C" sh -c 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --skip-triggers "$1"' sh "$SRCDB" | gzip -6 > "$DIR/source-lgcy.sql.gz"
  shasum -a 256 "$DIR/source-lgcy.sql.gz" > "$DIR/source-lgcy.sql.gz.sha256"
  # Portability guard: the package may only create lgcy_ tables.
  if gunzip -c "$DIR/source-lgcy.sql.gz" | grep -E '^(CREATE TABLE|DROP TABLE IF EXISTS|INSERT INTO) `' | grep -vqE "^(CREATE TABLE|DROP TABLE IF EXISTS|INSERT INTO) \`$PFX"; then echo "package contains non-$PFX tables — refusing"; exit 1; fi
  sql -e "SELECT k, v FROM \`$SRCDB\`.${PFX}dd_snapshot" | tee "$DIR/snapshot-identity.tsv"
  echo "SNAPSHOT READY: local source $SRCDB (prefix $PFX), package $DIR/source-lgcy.sql.gz" ;;
backup)
  if [ -n "$HOSTED" ]; then
    B="$RD/pre-apply-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
    $SSH "mkdir -p '$RD' && cd '$R' && set -o pipefail && wp db export - --single-transaction --quick | gzip -6 > '$B' && N=\$(wp db query --skip-column-names \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'\") && gunzip -c '$B' | tail -c 300 | grep -q -- '-- Dump completed' && [ \$(gunzip -c '$B' | grep -c '^CREATE TABLE') -ge \$N ] && sha256sum '$B' > '$B.sha256' && ls -l '$B'" || { echo "hosted backup incomplete — do not apply"; exit 3; }
  else
    B="$DIR/pre-apply-$DEST-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"; ./scripts/migration/snapshot.sh dump "$DEST" "$B"
  fi
  echo "backup ready — use: --backup=$B" ;;
pull-dest)
  # Read-only copy of the hosted new site, preserved as a protected baseline (dd_rh_test_base_<label>).
  # Fingerprint (remote/dest-fingerprint.sh) before and after one --single-transaction export; any
  # difference means the site changed during the pull and the copy is refused. The fingerprint is kept
  # for Action A's drift guard. --with-uploads also pulls web/app/uploads read-only (rsync).
  . ./.deploy-targets
  RS="ssh -p $KINSTA_REVIEW_PORT -o BatchMode=yes $KINSTA_REVIEW_SSH"
  T=dd_rh_test_base_$(echo "$LABEL" | tr -c 'a-z0-9_\n' '_')
  fp() { $RS "bash -s -- '$KINSTA_REVIEW_ROOT'" < scripts/deploy/remote/dest-fingerprint.sh; }
  fp > "$DIR/dest-fingerprint-before.tsv"
  T0=$(date -u +%FT%TZ)
  $RS "cd '$KINSTA_REVIEW_ROOT' && set -o pipefail && wp db export - --single-transaction --quick --skip-triggers --hex-blob --default-character-set=utf8mb4 | gzip -1" > "$DIR/hosted-dest.sql.gz"
  T1=$(date -u +%FT%TZ)
  fp > "$DIR/dest-fingerprint-after.tsv"
  bash scripts/deploy/remote/dest-fingerprint.sh --validate "$DIR/dest-fingerprint-before.tsv" "$DIR/dest-fingerprint-after.tsv" || { echo "fingerprint has NULL/non-numeric or missing values — refusing this copy"; exit 1; }
  cmp -s "$DIR/dest-fingerprint-before.tsv" "$DIR/dest-fingerprint-after.tsv" || { echo "destination changed during the pull (fingerprints differ) — refusing this copy; pull again during a content freeze"; diff "$DIR/dest-fingerprint-before.tsv" "$DIR/dest-fingerprint-after.tsv" | head; exit 1; }
  cp "$DIR/dest-fingerprint-after.tsv" "$DIR/dest-fingerprint.tsv"
  gunzip -t "$DIR/hosted-dest.sql.gz" && gunzip -c "$DIR/hosted-dest.sql.gz" | tail -c 300 | grep -q -- '-- Dump completed' || { echo "hosted dump incomplete — refusing"; exit 1; }
  chmod 600 "$DIR/hosted-dest.sql.gz"; shasum -a 256 "$DIR/hosted-dest.sql.gz" > "$DIR/hosted-dest.sql.gz.sha256"
  { echo "label	$LABEL"; echo "export_started_utc	$T0"; echo "export_finished_utc	$T1"
    echo "host	$($RS hostname)"; echo "root	$KINSTA_REVIEW_ROOT"; echo "code_commit	$($RS "cd '$KINSTA_REVIEW_ROOT' && git rev-parse HEAD && git status --porcelain | wc -l" | tr '\n' ' ')"
    echo "database	$(printf 'SELECT CONCAT(DATABASE()," / ",@@version);\n' | $RS "cd '$KINSTA_REVIEW_ROOT' && wp db query --skip-column-names 2>/dev/null")"
    echo "method	wp db export --single-transaction --quick (InnoDB), fingerprint identical before/after"
    echo "dump_sha256	$(cut -d' ' -f1 "$DIR/hosted-dest.sql.gz.sha256")"; echo "fingerprint_sha256	$(shasum -a 256 "$DIR/dest-fingerprint.tsv" | cut -d' ' -f1)"; echo "tables	$(gunzip -c "$DIR/hosted-dest.sql.gz" | grep -c '^CREATE TABLE')"; } > "$DIR/destination-identity.tsv"
  $RS "cd '$KINSTA_REVIEW_ROOT/web/app/plugins' && for d in */; do f=\$(grep -l 'Plugin Name' \$d*.php 2>/dev/null | head -1); echo \"\${d%/}	\$(grep -m1 -i 'Version:' \$f 2>/dev/null | sed 's/.*Version: *//')\"; done" > "$DIR/hosted-plugins.tsv"
  case " $* " in *" --with-uploads "*) rsync -a --delete -e "ssh -p $KINSTA_REVIEW_PORT -o BatchMode=yes" "$KINSTA_REVIEW_SSH:$KINSTA_REVIEW_ROOT/web/app/uploads/" "$DIR/uploads/" && echo "uploads: $(du -sh "$DIR/uploads" | cut -f1)";; esac
  ./scripts/migration/snapshot.sh restore "$DIR/hosted-dest.sql.gz" "$T"
  cat "$DIR/destination-identity.tsv"
  echo "protected baseline: $T. Make a working copy with: scripts/migration/snapshot.sh restore $DIR/hosted-dest.sql.gz dd_rh_dest_<name>" ;;
upload)
  [ -n "$HOSTED" ] || { echo "upload is --hosted only"; exit 2; }
  shasum -a 256 -c "$DIR/source-lgcy.sql.gz.sha256" >/dev/null
  $SSH "mkdir -p '$RD/reports' && chmod 700 '$PRIV'"
  scp -q -P "$KINSTA_REVIEW_PORT" "$DIR/source-lgcy.sql.gz" "$DIR/source-lgcy.sql.gz.sha256" "$KINSTA_REVIEW_SSH:$RD/"
  $SSH "cd '$RD' && sha256sum -c source-lgcy.sql.gz.sha256 && cd '$R' && gunzip -c '$RD/source-lgcy.sql.gz' | wp db import - && wp db query --skip-column-names \"SELECT k,v FROM ${PFX}dd_snapshot\"" ;;
preflight)
  run headroom.php check $SRCARGS
  run renumber.php audit $SRCARGS ${ISOLATE:+--skip-products}   # products are never relocated
  # schema: every source table used must have the same columns as the destination's
  if [ -z "$HOSTED" ]; then S=$SRCDB; else S=$DEST; fi
  Q="SELECT CONCAT(s.table_name,'.',s.column_name,' ',s.column_type,' vs ',COALESCE(d.column_type,'MISSING')) FROM information_schema.columns s LEFT JOIN information_schema.columns d ON d.table_schema='$DEST' AND d.table_name=CONCAT('wp_',SUBSTRING(s.table_name,${#PFX}+1)) AND d.column_name=s.column_name WHERE s.table_schema='$S' AND s.table_name LIKE '${PFX}%' AND s.table_name<>'${PFX}dd_snapshot' AND NOT (d.column_type <=> s.column_type)"
  if [ -n "$HOSTED" ]; then DIFF=$(hwp db query --skip-column-names "\"$Q\""); else DIFF=$(sql -e "$Q"); fi
  [ -z "$DIFF" ] && echo "schema: source and destination columns identical" || { echo "SCHEMA MISMATCH:"; echo "$DIFF"; exit 1; }
  run sync.php plan $SYNCARGS --only=users --batch=500 >/dev/null && echo "identity + collision preflight OK" ;;
plan)
  run sync.php plan $SYNCARGS; pull_reports ;;
apply|resume)
  [ "$CONFIRM" = "$DEST" ] || { echo "apply needs --confirm-dest=$DEST"; exit 2; }
  [ -n "$BACKUP" ] || { echo "apply needs --backup=<fresh dump of $DEST>"; exit 2; }
  if [ -n "$HOSTED" ]; then
    $SSH "test -s '$BACKUP' && gunzip -c '$BACKUP' | tail -c 300 | grep -q -- '-- Dump completed' && test \$(( \$(date +%s) - \$(stat -c %Y '$BACKUP') )) -lt 21600" || { echo "backup $BACKUP missing, corrupt or older than 6h"; exit 3; }
  else
    shasum -a 256 -c "$BACKUP.sha256" >/dev/null && [ $(( $(date +%s) - $(stat -f %m "$BACKUP") )) -lt 21600 ] || { echo "backup $BACKUP missing, unverified or older than 6h"; exit 3; }
  fi
  [ "$CMD" = resume ] && echo "resuming: completed batches are already equal and will be skipped"
  # An interrupted run can leave its DB connection (and the dd_migration lock) behind until the server
  # notices; wait up to 3 minutes for it to clear rather than failing at once. Never kill it blindly.
  lockq="SELECT COALESCE(IS_USED_LOCK('dd_migration'),'')"
  for i in $(seq 1 36); do
    if [ -n "$HOSTED" ]; then h=$(hwp "db query --skip-column-names \"$lockq\""); else h=$(sql -e "$lockq"); fi
    [ -z "$h" ] && break; echo "   dd_migration lock still held by DB connection $h — waiting ($i/36)"; sleep 5
  done
  [ -z "$h" ] || { echo "lock still held by connection $h after 3 min: confirm no migration process is running, then end that connection (KILL $h) and resume"; exit 4; }
  run sync.php apply $SYNCARGS
  # Only for DevKinsta-derived sources (non-verbatim profiles): restores the real legacy domain in legacy-owned
  # values. A verbatim production snapshot carries the real values already, so nothing is rewritten.
  [ "$PROFILE" = verbatim ] || run restore-legacy-domain.php apply --dest="$DEST"; pull_reports ;;
verify)
  if [ -n "$HOSTED" ]; then
    hwp "wc hpos sync --batch-size=500"; hwp "wc hpos verify_data --batch-size=1000 --order-types=shop_order,shop_order_refund" || true
    # No product writes (scope 2026-10-19): no product lookup regeneration or term recount here.
    ADMIN=$(hwp "user list --role=administrator --field=user_login --number=1"); hwp "wc tool run clear_transients --user=$ADMIN"; hwp "cache flush"
  else
    ./scripts/migration/postsync.sh "$DEST"
  fi
  # every check runs even if one fails; the command still exits non-zero when any failed
  RF=0; run reconcile.php $SRCARGS || RF=1
  run neutralize.php plan --dest="$DEST" || RF=1
  run sync.php plan $SYNCARGS || RF=1; pull_reports
  [ $RF = 0 ] || { echo "VERIFY: at least one check failed (see above)"; exit 1; } ;;
drop-source)
  [ -n "$HOSTED" ] && [ "$CONFIRM" = "$DEST" ] || { echo "drop-source is --hosted with --confirm-dest=<db>"; exit 2; }
  $SSH "cd '$R' && for t in \$(wp db query --skip-column-names \"SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX}%'\"); do wp db query \"DROP TABLE \\\`\$t\\\`\"; done" ;;
*) echo "unknown command $CMD"; exit 2 ;;
esac
