#!/usr/bin/env bash
# Safely import a SQL dump into the local database container.
# Usage: scripts/db-import.sh path/to/dump.sql[.gz]
set -euo pipefail
cd "$(dirname "$0")/.."

DUMP_FILE="${1:-}"

if [[ -z "$DUMP_FILE" ]]; then
    echo "Usage: $0 <path-to-dump.sql|dump.sql.gz>" >&2
    exit 1
fi

if [[ ! -f "$DUMP_FILE" ]]; then
    echo "Error: dump file not found: $DUMP_FILE" >&2
    exit 1
fi

echo "This will REPLACE the contents of the local database with:"
echo "  $DUMP_FILE"
read -r -p "Type 'yes' to continue: " CONFIRM
if [[ "$CONFIRM" != "yes" ]]; then
    echo "Aborted."
    exit 1
fi

docker compose up -d db

echo "Waiting for database to be ready..."
DB_WAIT_TIMEOUT=60
DB_WAIT_ELAPSED=0
until docker compose exec -T db mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; do
    sleep 1
    DB_WAIT_ELAPSED=$((DB_WAIT_ELAPSED + 1))
    if [[ "$DB_WAIT_ELAPSED" -ge "$DB_WAIT_TIMEOUT" ]]; then
        echo "Error: database did not become ready within ${DB_WAIT_TIMEOUT}s." >&2
        exit 1
    fi
done

echo "Importing..."
# Credentials are read from the db container's own environment, never passed on the
# host command line or written to shell history. CREATE DATABASE / DROP DATABASE / USE
# statements are stripped from the dump so it always lands in the local $DB_NAME
# regardless of what database name the source snapshot was exported from — otherwise a
# dump carrying its own USE/CREATE DATABASE could fail (the local DB user has no
# privileges outside $DB_NAME) or silently import into the wrong database, and a
# DROP DATABASE (which mysqldump --add-drop-database emits wrapped in a version
# comment, e.g. `/*!40000 DROP DATABASE IF EXISTS \`name\`*/;`) would abort the mysql
# session partway through the import (mysql stops on first error) or, worse, drop a
# database that happens to share the local name. The pattern below matches these
# statements whether or not they're wrapped in a `/*!NNNNN ... */` version comment.
FILTER='^(/\*![0-9]+[[:space:]]+)?(CREATE|DROP)[[:space:]]+DATABASE\b|^(/\*![0-9]+[[:space:]]+)?USE[[:space:]]'
if [[ "$DUMP_FILE" == *.gz ]]; then
    gunzip -c "$DUMP_FILE" | grep -viE "$FILTER" | docker compose exec -T db sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
else
    grep -viE "$FILTER" "$DUMP_FILE" | docker compose exec -T db sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
fi

echo "Import complete."
echo "Next: run ./scripts/search-replace.sh <old-domain> <new-local-url> to fix URLs for local use."
