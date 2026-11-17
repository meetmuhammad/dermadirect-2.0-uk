#!/bin/sh
# Reproducible code artifact for Action B (and the code half of Action A).
#   scripts/deploy/build.sh <git-ref> <out-dir>     (BUILD_MODE=native|docker, default native)
# Exports exactly <git-ref> with `git archive` (never the working tree), installs root and theme
# Composer dependencies from their lockfiles (--no-dev), builds the Sage theme with `npm ci` from
# package-lock.json, and writes a tarball + manifest (commit, sha256). Premium/non-Composer plugins
# and uploads are NOT part of the artifact: they live on the server and are preserved by deploy.sh.
set -eu
REF=$1; OUT=$2; MODE=${BUILD_MODE:-native}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
COMMIT=$(git -C "$ROOT" rev-parse --verify "$REF^{commit}")
WORK=$(mktemp -d "${TMPDIR:-/tmp}/dd-build.XXXXXX"); trap 'rm -rf "$WORK"' EXIT
SRC=$WORK/src; mkdir -p "$SRC" "$OUT"
git -C "$ROOT" archive "$COMMIT" | tar -x -C "$SRC"
THEME=web/app/themes/derma-direct
run_composer() { # $1 = dir relative to SRC
  if [ "$MODE" = docker ]; then
    docker run --rm -v "$SRC:/app" -w "/app/$1" -e COMPOSER_AUTH composer:2 install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --ignore-platform-req=php
  else
    (cd "$SRC/$1" && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader)
  fi
}
run_npm() {
  if [ "$MODE" = docker ]; then
    docker run --rm -v "$SRC:/app" -w "/app/$THEME" node:20-alpine sh -c "npm ci --no-audit --no-fund && npm run build"
  else
    (cd "$SRC/$THEME" && npm ci --no-audit --no-fund && npm run build)
  fi
}
echo "== composer (root)"; run_composer .
echo "== composer (theme)"; run_composer "$THEME"
echo "== theme assets (npm ci + vite build)"; run_npm
rm -rf "$SRC/$THEME/node_modules"
test -f "$SRC/$THEME/public/build/manifest.json" || { echo "missing Vite manifest"; exit 1; }
test -f "$SRC/web/wp/wp-settings.php" || { echo "missing WordPress core"; exit 1; }
# Never ship local-only or secret files, even if someone commits them by mistake.
for f in .env web/.htaccess web/app/mu-plugins/000-local-safety.php web/app/mu-plugins/001-rehearsal-banner.php docker-compose.yml; do rm -rf "$SRC/$f"; done
rm -rf "$SRC/docker" "$SRC/local" "$SRC/docs" "$SRC/scripts/migration/tests"
printf '%s\n' "$COMMIT" > "$SRC/REVISION"
NAME=dd-code-$(printf %.12s "$COMMIT")
COPYFILE_DISABLE=1 tar --no-mac-metadata --no-xattrs -C "$SRC" -czf "$OUT/$NAME.tar.gz" .   # no macOS ._ metadata entries (Linux would extract them as files)
( cd "$OUT" && shasum -a 256 "$NAME.tar.gz" > "$NAME.tar.gz.sha256" )
WPV=$(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$SRC/web/wp/wp-includes/version.php")
printf '{"commit":"%s","artifact":"%s.tar.gz","sha256":"%s","wordpress":"%s","built_at_utc":"%s","mode":"%s"}\n' \
  "$COMMIT" "$NAME" "$(cut -d' ' -f1 "$OUT/$NAME.tar.gz.sha256")" "$WPV" "$(date -u +%FT%TZ)" "$MODE" > "$OUT/$NAME.json"
cat "$OUT/$NAME.json"
