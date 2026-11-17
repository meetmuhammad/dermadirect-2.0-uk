#!/bin/sh
# Local-only DB snapshot/restore/verify for the migration rehearsal (Docker MariaDB "db").
#   snapshot.sh dump    <db> <file.sql.gz>   consistent dump + .sha256
#   snapshot.sh restore <file.sql.gz> <db>   refuses protected DBs; creates <db> fresh
#   snapshot.sh verify  <dbA> <dbB>          CHECKSUM TABLE equality over every table
set -eu
C=dermadirect20uk-db-1
sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
case "${1:-}" in
dump)
  docker exec "$C" sh -c 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --hex-blob --routines --triggers --default-character-set=utf8mb4 "$1"' sh "$2" | gzip -6 > "$3"
  # A failed mariadb-dump piped into gzip still yields a valid (empty) gzip: require the dump trailer
  # and at least one table per table in the source schema before accepting the file.
  want=$(sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$2' AND table_type='BASE TABLE'")
  got=$(gunzip -c "$3" | grep -c '^CREATE TABLE' || true)
  gunzip -c "$3" | tail -c 300 | grep -q -- '-- Dump completed' && [ "$got" -ge "$want" ] || { echo "DUMP INCOMPLETE: $3 ($got of $want tables)"; rm -f "$3"; exit 1; }
  shasum -a 256 "$3" > "$3.sha256"; ls -l "$3"; cat "$3.sha256" ;;
restore)
  case "$3" in dermadirect_local|legacy_snapshot_20260927|httpsdermadirectcom) echo "refusing protected db $3" >&2; exit 2;; esac
  shasum -a 256 -c "$2.sha256"
  sql -e "DROP DATABASE IF EXISTS \`$3\`; CREATE DATABASE \`$3\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL ON \`$3\`.* TO 'dermadirect'@'%';"
  gunzip -c "$2" | docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$3" ;;
verify)
  a=$(mktemp); b=$(mktemp)
  for db in "$2" "$3"; do
    t=$(sql -e "set session group_concat_max_len=1000000; select group_concat(concat('\`',table_schema,'\`.\`',table_name,'\`')) from information_schema.tables where table_schema='$db' and table_type='BASE TABLE'")
    sql -e "CHECKSUM TABLE $t EXTENDED" | sed "s/^[^.]*\.//" | sort > "$([ "$db" = "$2" ] && echo $a || echo $b)"
  done
  # fail closed: a NULL (missing/unreadable table), empty or non-numeric checksum is never "equal"
  for f in "$a" "$b"; do [ -s "$f" ] && awk -F'\t' 'NF!=2 || $2 !~ /^[0-9]+$/ {exit 1}' "$f" || { echo "VERIFY FAILED: missing, NULL or non-numeric checksum"; head -5 "$f"; exit 1; }; done
  if diff "$a" "$b" >/dev/null; then echo "VERIFY OK: $(wc -l < $a | tr -d ' ') tables identical ($2 = $3)"; else echo "VERIFY FAILED"; diff "$a" "$b" | head -20; exit 1; fi ;;
*) sed -n 2,5p "$0"; exit 1 ;;
esac
