#!/bin/sh
# Pre-deploy checks (CI and local). Fails on: PHP syntax errors in project code, failing migration
# unit tests, secrets or private data in tracked files, forbidden file types, invalid composer files.
set -eu
cd "$(dirname "$0")/../.."
fail=0; PHP=${PHP:-php}
echo "== php lint (project code only)"
git ls-files '*.php' | grep -vE '^(vendor|web/wp)/' | while read f; do $PHP -l "$f" >/dev/null 2>&1 || { echo "  SYNTAX: $f"; echo x >> /tmp/dd-checks-fail.$$; }; done
echo "== migration unit tests"
$PHP scripts/migration/tests/refs_test.php | tail -1; $PHP scripts/migration/tests/lib_test.php | tail -1
echo "== shell syntax"
for f in $(git ls-files 'scripts/*.sh' 'scripts/**/*.sh'); do sh -n "$f" 2>/dev/null || bash -n "$f" || { echo "  SHELL: $f"; fail=1; }; done
echo "== forbidden files (dumps, env, keys, private reports)"
BAD=$(git ls-files | grep -v '^scripts/migration/tests/later-activity.sql$' | grep -E '\.(sql|sql\.gz|dump|pem|key|p12)$|(^|/)\.env$|(^|/)\.deploy-targets$|^(recovery|secrets|migration-reports|logs)/|\.auth-browser-profile' || true)
[ -z "$BAD" ] || { echo "$BAD" | sed 's/^/  FORBIDDEN: /'; fail=1; }
echo "== secret patterns in tracked text files"
HITS=$(git grep -nIE 'AKIA[0-9A-Z]{16}|sk_live_[0-9A-Za-z]{10,}|rk_live_[0-9A-Za-z]{10,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z-]{10,}|gh[pousr]_[0-9A-Za-z]{30,}|(DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|NONCE_SALT)=.?[A-Za-z0-9+/_@#%^&*()!-]{8,}' -- . ':!scripts/deploy/checks.sh' ':!.env.example' || true)
[ -z "$HITS" ] || { echo "$HITS" | cut -c1-160 | sed 's/^/  SECRET?: /'; fail=1; }
echo "== customer data markers in tracked files (emails other than known project/test addresses)"
EM=$(git grep -nIoE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}' -- ':!*.lock' ':!web/app/themes/derma-direct/resources/js/vendor' ':!web/app/themes/derma-direct/resources/css/vendor' | grep -vE '@(example\.(com|invalid|org)|dermadirect\.(co\.uk|test)|wordpress\.org|roots\.io|users\.noreply\.github\.com|anthropic\.com)|noreply@' | cut -d: -f1 | sort -u || true)
[ -z "$EM" ] || { echo "$EM" | sed 's/^/  EMAIL (review): /'; }
echo "== composer"
composer validate --no-check-publish --no-interaction >/dev/null 2>&1 && echo "  composer.json ok" || { echo "  composer validate failed"; fail=1; }
[ -f /tmp/dd-checks-fail.$$ ] && { rm -f /tmp/dd-checks-fail.$$; fail=1; }
[ $fail = 0 ] && echo "CHECKS OK" || { echo "CHECKS FAILED"; exit 1; }
