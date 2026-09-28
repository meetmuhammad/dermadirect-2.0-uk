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
HOSTED=""; DEST=dd_rh_dest; CONFIRM=""; BACKUP=""; FROMDUMP=""; FROMLEGACY=""
for a in "$@"; do case "$a" in
  --hosted) HOSTED=1;; --dest=*) DEST=${a#*=};; --confirm-dest=*) CONFIRM=${a#*=};; --backup=*) BACKUP=${a#*=};; --from-dump=*) FROMDUMP=${a#*=};; --from-legacy-kinsta) FROMLEGACY=1;;
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
 trackship_shipment_meta zorem_email_sms_log stock_log woocommerce_downloadable_product_permissions" | tr -s ' \n\t' '   ')
if [ -n "$HOSTED" ]; then
  . ./.deploy-targets
  SSH="ssh -p $KINSTA_REVIEW_PORT -o BatchMode=yes $KINSTA_REVIEW_SSH"; R=$KINSTA_REVIEW_ROOT; PRIV=$KINSTA_REVIEW_PRIVATE; RD=$PRIV/final-sync/$LABEL
  HDB=$($SSH "cd '$R' && sed -n \"s/^DB_NAME=['\\\"]\\{0,1\\}\\([^'\\\"]*\\)['\\\"]\\{0,1\\}\$/\\1/p\" .env | tail -1")
  DEST=$HDB
  hphp() { $SSH "cd '$R' && MIG_HOSTED=1 MIG_DOTENV='$R/.env' MIG_REPORT_DIR='$RD/reports' php scripts/migration/$*"; }
  hwp() { $SSH "cd '$R' && wp $*"; }
fi
SRCARGS="--src=${HOSTED:+$DEST}${HOSTED:-$SRCDB} --src-prefix=$PFX --dest=$DEST"
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
    . ./.deploy-targets   # LEGACY_SSH / LEGACY_PORT / LEGACY_ROOT (read-only use)
    LS="ssh -p ${LEGACY_PORT:?} -o BatchMode=yes ${LEGACY_SSH:?}"
    WT=""; for t in $TABLES; do WT="$WT,wp_$t"; done
    HOME_URL=$($LS "cd '${LEGACY_ROOT:?}' && wp option get home --skip-plugins --skip-themes"); THEME=$($LS "cd '$LEGACY_ROOT' && wp option get stylesheet --skip-plugins --skip-themes")
    $LS "cd '$LEGACY_ROOT' && wp db export - --tables=${WT#,} --single-transaction --quick --skip-triggers" | gzip -1 > "$DIR/legacy-raw.sql.gz"
    SHA=$(shasum -a 256 "$DIR/legacy-raw.sql.gz" | cut -d' ' -f1); ORIGIN="legacy-kinsta:$LEGACY_SSH"
    gunzip -c "$DIR/legacy-raw.sql.gz" | docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$SRCDB"
    rm -f "$DIR/legacy-raw.sql.gz"
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
    $SSH "mkdir -p '$RD' && cd '$R' && wp db export - --single-transaction --quick | gzip -6 > '$B' && gzip -t '$B' && sha256sum '$B' > '$B.sha256' && ls -l '$B'"
  else
    B="$DIR/pre-apply-$DEST-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"; ./scripts/migration/snapshot.sh dump "$DEST" "$B"
  fi
  echo "backup ready — use: --backup=$B" ;;
pull-dest)
  [ -n "$HOSTED" ] || { . ./.deploy-targets; }
  T=dd_rh_dest_$(echo "$LABEL" | tr -c 'a-z0-9_\n' '_')
  ssh -p "$KINSTA_REVIEW_PORT" -o BatchMode=yes "$KINSTA_REVIEW_SSH" "cd '$KINSTA_REVIEW_ROOT' && wp db export - --single-transaction --quick" | gzip -6 > "$DIR/hosted-dest.sql.gz"
  shasum -a 256 "$DIR/hosted-dest.sql.gz" > "$DIR/hosted-dest.sql.gz.sha256"
  ./scripts/migration/snapshot.sh restore "$DIR/hosted-dest.sql.gz" "$T"
  echo "local copy of the hosted destination: $T (use --dest=$T)" ;;
upload)
  [ -n "$HOSTED" ] || { echo "upload is --hosted only"; exit 2; }
  shasum -a 256 -c "$DIR/source-lgcy.sql.gz.sha256" >/dev/null
  $SSH "mkdir -p '$RD/reports' && chmod 700 '$PRIV'"
  scp -q -P "$KINSTA_REVIEW_PORT" "$DIR/source-lgcy.sql.gz" "$DIR/source-lgcy.sql.gz.sha256" "$KINSTA_REVIEW_SSH:$RD/"
  $SSH "cd '$RD' && sha256sum -c source-lgcy.sql.gz.sha256 && cd '$R' && gunzip -c '$RD/source-lgcy.sql.gz' | wp db import - && wp db query --skip-column-names \"SELECT k,v FROM ${PFX}dd_snapshot\"" ;;
preflight)
  run headroom.php check $SRCARGS
  run renumber.php audit $SRCARGS
  # schema: every source table used must have the same columns as the destination's
  if [ -z "$HOSTED" ]; then S=$SRCDB; else S=$DEST; fi
  Q="SELECT CONCAT(s.table_name,'.',s.column_name,' ',s.column_type,' vs ',COALESCE(d.column_type,'MISSING')) FROM information_schema.columns s LEFT JOIN information_schema.columns d ON d.table_schema='$DEST' AND d.table_name=CONCAT('wp_',SUBSTRING(s.table_name,${#PFX}+1)) AND d.column_name=s.column_name WHERE s.table_schema='$S' AND s.table_name LIKE '${PFX}%' AND s.table_name<>'${PFX}dd_snapshot' AND NOT (d.column_type <=> s.column_type)"
  if [ -n "$HOSTED" ]; then DIFF=$(hwp db query --skip-column-names "\"$Q\""); else DIFF=$(sql -e "$Q"); fi
  [ -z "$DIFF" ] && echo "schema: source and destination columns identical" || { echo "SCHEMA MISMATCH:"; echo "$DIFF"; exit 1; }
  run sync.php plan $SRCARGS --only=users --batch=500 >/dev/null && echo "identity + collision preflight OK" ;;
plan)
  run sync.php plan $SRCARGS; pull_reports ;;
apply|resume)
  [ "$CONFIRM" = "$DEST" ] || { echo "apply needs --confirm-dest=$DEST"; exit 2; }
  [ -n "$BACKUP" ] || { echo "apply needs --backup=<fresh dump of $DEST>"; exit 2; }
  if [ -n "$HOSTED" ]; then
    $SSH "test -s '$BACKUP' && gzip -t '$BACKUP' && test \$(( \$(date +%s) - \$(stat -c %Y '$BACKUP') )) -lt 21600" || { echo "backup $BACKUP missing, corrupt or older than 6h"; exit 3; }
  else
    shasum -a 256 -c "$BACKUP.sha256" >/dev/null && [ $(( $(date +%s) - $(stat -f %m "$BACKUP") )) -lt 21600 ] || { echo "backup $BACKUP missing, unverified or older than 6h"; exit 3; }
  fi
  [ "$CMD" = resume ] && echo "resuming: completed batches are already equal and will be skipped"
  run sync.php apply $SRCARGS; pull_reports ;;
verify)
  if [ -n "$HOSTED" ]; then
    hwp "wc hpos sync --batch-size=500"; hwp "wc hpos verify_data --batch-size=1000 --order-types=shop_order,shop_order_refund" || true
    ADMIN=$(hwp "user list --role=administrator --field=user_login --number=1"); hwp "wc tool run regenerate_product_lookup_tables --user=$ADMIN"; hwp "wc tool run clear_transients --user=$ADMIN"; hwp "cache flush"
  else
    ./scripts/migration/postsync.sh "$DEST"
  fi
  run reconcile.php $SRCARGS
  run neutralize.php plan --dest="$DEST"
  run sync.php plan $SRCARGS; pull_reports ;;
drop-source)
  [ -n "$HOSTED" ] && [ "$CONFIRM" = "$DEST" ] || { echo "drop-source is --hosted with --confirm-dest=<db>"; exit 2; }
  $SSH "cd '$R' && for t in \$(wp db query --skip-column-names \"SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX}%'\"); do wp db query \"DROP TABLE \\\`\$t\\\`\"; done" ;;
*) echo "unknown command $CMD"; exit 2 ;;
esac
