#!/usr/bin/env bash
# Runs local sanity checks. This does not certify the WordPress content/data itself —
# only that the stack is up, reachable on localhost, and that egress isolation holds.
set -euo pipefail
cd "$(dirname "$0")/.."

mkdir -p logs
LOG_FILE="logs/verify-$(date +%Y%m%d-%H%M%S).log"
exec > >(tee "$LOG_FILE") 2>&1
echo "Recording evidence to $LOG_FILE"

echo "== Container status =="
docker compose ps

echo
echo "== Database connectivity =="
if docker compose exec -T db mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; then
    echo "OK: database reachable"
else
    echo "FAIL: database not reachable"
fi

echo
echo "== WordPress via WP-CLI =="
# `core is-installed` is expected to fail (non-zero) before the snapshot is imported.
# Capturing it via $(...) under `set -e` would otherwise abort this whole script on
# that expected failure, skipping every check below — so errexit is suspended just
# for this probe.
set +e
WPCLI_OUTPUT=$(docker compose --profile cli run --rm wpcli core is-installed 2>&1)
WPCLI_STATUS=$?
set -e
if [[ $WPCLI_STATUS -eq 0 ]]; then
    echo "OK: WordPress installed"
else
    echo "WARN: WordPress not installed / not reachable yet (expected before snapshot import)"
    echo "  wp-cli said: ${WPCLI_OUTPUT}"
fi

echo
echo "== Web server (localhost only) =="
curl -sS -o /dev/null -w "HTTP %{http_code}\n" "http://localhost:${WEB_PORT:-8088}" || echo "FAIL: web server not responding on localhost:${WEB_PORT:-8088}"

echo
echo "== Mailpit UI (localhost only) =="
curl -sS -o /dev/null -w "HTTP %{http_code}\n" "http://localhost:${MAILPIT_UI_PORT:-18025}" || echo "FAIL: mailpit UI not responding"

echo
echo "== Egress isolation sanity check =="
RESULT=$(docker compose exec -T web php -r '$c = @file_get_contents("http://example.com", false, stream_context_create(["http" => ["timeout" => 3]])); echo $c !== false ? "reachable" : "blocked";' 2>/dev/null || echo "blocked")
if [[ "$RESULT" == "reachable" ]]; then
    echo "WARNING: web container reached the internet — isolation is NOT working as expected."
else
    echo "OK: web container could not reach the internet (expected)."
fi

echo
echo "Done. Evidence recorded to $LOG_FILE"
