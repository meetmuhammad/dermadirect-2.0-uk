#!/bin/sh
# Promote a verified rehearsal database to the canonical local site (DB dermadirect_local, :8088).
#   scripts/migration/promote.sh <source_db> <source_base_url> --confirm=dermadirect_local
# Steps: backup current dermadirect_local (+sha256) -> dump source -> load into dermadirect_local ->
# restore preserved local admin password hashes -> serialization-safe URL rewrite, one WP-CLI run
# per table (a single --all-tables run can die silently on large tables) -> residual scan (fails if
# any non-GUID old URL remains) -> cache/rewrite flush -> restart web. Code/uploads are the shared
# bind mount, so nothing else needs copying.
set -eu
cd "$(dirname "$0")/../.."
SRC=$1; FROM=$2; CONFIRM=${3:-}
TARGET=dermadirect_local; TO=http://localhost:8088
[ "$CONFIRM" = "--confirm=$TARGET" ] || { echo "refusing: pass --confirm=$TARGET"; exit 2; }
case "$SRC" in dd_rh_*) ;; *) echo "refusing: source must be a dd_rh_* rehearsal database"; exit 2;; esac
PRESERVE_USERS=${PRESERVE_USERS:-10224}   # local admin logins whose current password hash is kept
TS=$(date -u +%Y%m%dT%H%M%SZ)
C=dermadirect20uk-db-1
sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
wpq() { DB_NAME=$TARGET docker compose --profile cli run --rm -T --no-deps --entrypoint sh wpcli -c "php -d memory_limit=4G /usr/local/bin/wp $* --skip-plugins --skip-themes 2>&1 | grep -v -e WC_SA_Email -e FS_Plugin" 2>/dev/null; }

echo "== stop web"; docker compose stop web >/dev/null 2>&1 || true
echo "== backup $TARGET"; ./scripts/migration/snapshot.sh dump "$TARGET" "recovery/pre-promote-$TARGET-$TS.sql.gz"
HASHES=$(sql -e "SELECT GROUP_CONCAT(CONCAT(ID,'=',user_pass) SEPARATOR ' ') FROM $TARGET.wp_users WHERE ID IN ($PRESERVE_USERS)")
echo "== dump $SRC"; ./scripts/migration/snapshot.sh dump "$SRC" "recovery/promote-src-$SRC-$TS.sql.gz"
echo "== load into $TARGET"
sql -e "DROP DATABASE \`$TARGET\`; CREATE DATABASE \`$TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL ON \`$TARGET\`.* TO 'dermadirect'@'%';"
gunzip -c "recovery/promote-src-$SRC-$TS.sql.gz" | docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$TARGET"
for kv in $HASHES; do id=${kv%%=*}; h=${kv#*=}; sql -e "UPDATE $TARGET.wp_users SET user_pass='$h' WHERE ID=$id"; echo "   preserved local login hash for user $id"; done
if [ "$FROM" != "$TO" ]; then
  echo "== URL rewrite $FROM -> $TO (per table)"
  TABLES=$(sql -e "SELECT DISTINCT c.table_name FROM information_schema.columns c WHERE c.table_schema='$TARGET' AND c.data_type IN ('char','varchar','tinytext','text','mediumtext','longtext')")
  for t in $TABLES; do
    n=$(sql -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$TARGET' AND table_name='$t' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext')")
    [ "$n" -gt 0 ] || continue
    hit=$(sql -e "SELECT CONCAT('SELECT 1 FROM \`$TARGET\`.\`$t\` WHERE ', GROUP_CONCAT(CONCAT('\`',column_name,'\` LIKE ''%$FROM%''') SEPARATOR ' OR '), ' LIMIT 1') FROM information_schema.columns WHERE table_schema='$TARGET' AND table_name='$t' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext') AND column_name<>'guid'" | sql)
    [ -n "$hit" ] || continue
    wpq search-replace "'$FROM'" "'$TO'" "$t" --all-tables --skip-columns=guid --precise --report-changed-only | grep -E "Success|Error" | sed "s/^/   $t: /"
  done
fi
if [ "$FROM" != "$TO" ]; then
echo "== residual scan for $FROM (non-GUID)"
Q=$(sql -e "SELECT GROUP_CONCAT(CONCAT('SELECT ''',table_name,'.',column_name,''' c, COUNT(*) n FROM \`$TARGET\`.\`',table_name,'\` WHERE \`',column_name,'\` LIKE ''%$FROM%''') SEPARATOR ' UNION ALL ') FROM information_schema.columns WHERE table_schema='$TARGET' AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext') AND column_name<>'guid'")
LEFT=$(echo "SELECT * FROM ($Q) x WHERE n>0" | sql)
[ -z "$LEFT" ] || { echo "RESIDUAL $FROM FOUND:"; echo "$LEFT"; exit 1; }
echo "   none"
fi
echo "== flush + start web"; wpq cache flush >/dev/null; wpq rewrite flush >/dev/null
docker compose up -d --no-deps --force-recreate web >/dev/null 2>&1
sleep 4; echo "   web serves DB: $(docker exec dermadirect20uk-web-1 printenv DB_NAME)"
echo "PROMOTED $SRC -> $TARGET (backup: recovery/pre-promote-$TARGET-$TS.sql.gz)"
