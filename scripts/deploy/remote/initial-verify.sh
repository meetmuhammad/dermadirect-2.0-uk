#!/bin/sh
# Runs ON the Kinsta review server after Action A apply. Every check can fail the deployment.
#   initial-verify.sh <project-root> <package-dir> <expected-url> <private-dir>
# Checks: counts vs manifest; stored AND effective URLs are the expected host (never localhost, :8088,
# :8089 or another host); no local URL left in settings or content; hosted staff restored by identity with
# every migrated account identical to the package and no unexpected privileged account; integrations
# neutralised; payment gateways in test mode with keys blank; safety harness active; outbound HTTP and
# mail blocked.
set -eu
ROOT=$1; PKG=$2; URL=$3; PRIV=$4; cd "$ROOT"
P=$(sed -n "s/^DB_PREFIX=['\"]\{0,1\}\([^'\"]*\)['\"]\{0,1\}$/\1/p" .env | tail -1); P=${P:-wp_}
HOST=$(echo "$URL" | sed -E 's#^https?://([^/:]+).*#\1#')
q() { wp db query --skip-column-names "$1"; }
fails=0; ck() { if [ "$2" = "$3" ]; then echo "ok   $1 ($2)"; else echo "FAIL $1: got '$2', expected '$3'"; fails=$((fails+1)); fi; }
man() { sed -n "s/.*\"$1\":\([0-9]*\).*/\1/p" "$PKG/manifest.json" | head -1; }

echo "== counts vs manifest"
ck "max order ID" "$(q "SELECT MAX(ID) FROM ${P}posts WHERE post_type='shop_order'")" "$(man max_order_id)"
ck "products + variations" "$(q "SELECT COUNT(*) FROM ${P}posts WHERE post_type IN ('product','product_variation')")" "$(man products)"
ck "coupons" "$(q "SELECT COUNT(*) FROM ${P}posts WHERE post_type='shop_coupon'")" "$(man coupons)"
ck "renumbering map rows (posts+users)" "$(q "SELECT COUNT(*) FROM dd_mig_idmap WHERE ns<>'staff'")" "$(man idmap)"

echo "== URLs (stored settings and what WordPress actually resolves)"
ck "stored home" "$(q "SELECT option_value FROM ${P}options WHERE option_name='home'")" "$URL"
ck "stored siteurl" "$(q "SELECT option_value FROM ${P}options WHERE option_name='siteurl'")" "$URL"
EFF=$(wp eval 'echo home_url("/"), " ", site_url("/"), " ", content_url("/"), " ", admin_url("/"), " ", wc_get_page_permalink("myaccount");' 2>/dev/null)
BADHOSTS=$(for u in $EFF; do echo "$u" | sed -E 's#^https?://([^/]+).*#\1#'; done | grep -vx "$HOST" || true)
ck "effective home/site/content/admin/my-account URLs all on $HOST" "${BADHOSTS:-none}" none
# URL-shaped only (plain or JSON-escaped): IP addresses and plugin fields such as "storage server: localhost" are not URLs.
LOCAL='https?:(\\\\?/){2}(localhost|127\\.0\\.0\\.1)|https?:(\\\\?/){2}[^/"]*:808[89]'
ck "settings with a URL to localhost / :8088 / :8089" "$(q "SELECT COUNT(*) FROM ${P}options WHERE option_value REGEXP '$LOCAL'")" 0
ck "content with a URL to localhost / :8088 / :8089 (non-GUID)" "$(q "SELECT (SELECT COUNT(*) FROM ${P}posts WHERE post_content REGEXP '$LOCAL' OR post_excerpt REGEXP '$LOCAL') + (SELECT COUNT(*) FROM ${P}postmeta WHERE meta_value REGEXP '$LOCAL')")" 0
# DevKinsta domain in destination-owned content is pre-existing on Kinsta (pending decision, residuals-reported.tsv):
# the import must not add any. Settings are reported only (destination-owned; decision pending).
rep() { awk -F'\t' -v c="$1" '$1=="httpsdermadirectcom.local" && $2==c {print $3}' "$PKG/residuals-reported.tsv"; }
ck "DevKinsta domain in post content = package" "$(q "SELECT COUNT(*) FROM ${P}posts WHERE post_content LIKE '%httpsdermadirectcom.local%'")" "$(rep wp_posts.post_content)"
ck "DevKinsta domain in postmeta = package" "$(q "SELECT COUNT(*) FROM ${P}postmeta WHERE meta_value LIKE '%httpsdermadirectcom.local%'")" "$(rep wp_postmeta.meta_value)"
echo "info DevKinsta domain in settings (destination-owned, decision pending): $(q "SELECT COUNT(*) FROM ${P}options WHERE option_value LIKE '%httpsdermadirectcom.local%'") (package: $(rep wp_options.option_value))"

echo "== accounts (staff restored by identity; no migrated account changed or elevated)"
TS=$(cat "$PRIV/last-import")
if MIG_HOSTED=1 MIG_BEDROCK_ROOT="$ROOT" MIG_REPORT_DIR="$PRIV/reports" php scripts/migration/restore-staff.php verify \
    --dest="$(q "SELECT DATABASE()")" --confirm-dest="$(q "SELECT DATABASE()")" --staff="$PRIV/backups/hosted-staff-$TS.json" --identity="$PKG/users-identity.tsv"; then
  echo "ok   staff/accounts"; else echo "FAIL staff/accounts"; fails=$((fails+1)); fi

echo "== isolation"
ck "active webhooks" "$(q "SELECT COUNT(*) FROM ${P}wc_webhooks WHERE status='active'")" 0
MAXA=$(cat "$PRIV/backups/max-action-$TS.txt")
ck "carried-over scheduled actions still cancelled (pending with ID <= $MAXA)" "$(q "SELECT COUNT(*) FROM ${P}actionscheduler_actions WHERE status='pending' AND action_id<=$MAXA")" 0
ck "queued webhook deliveries" "$(q "SELECT COUNT(*) FROM ${P}actionscheduler_actions WHERE status='pending' AND hook='woocommerce_deliver_webhook_async'")" 0
ck "scheduled actions executed since the import" "$(q "SELECT COUNT(*) FROM ${P}actionscheduler_actions WHERE action_id>$MAXA AND status IN ('complete','failed','in-progress')")" 0
echo "info self-scheduled since the import (pending, cannot run while the harness is on): $(q "SELECT COALESCE(GROUP_CONCAT(DISTINCT hook),'none') FROM ${P}actionscheduler_actions WHERE action_id>$MAXA AND status='pending'")"
R=$(wp eval '
  $bad = [];
  foreach (["woocommerce_superpayments_settings","woocommerce_stripe_settings","woocommerce_stripe_cc_settings"] as $o) {
    $v = get_option($o);
    if (!is_array($v)) { continue; }
    if (($v["test_mode"] ?? $v["testmode"] ?? "") !== "yes") { $bad[] = "$o:not-test-mode"; }
    foreach (["api_key","secret_key","publishable_key","webhook_secret"] as $k) { if (!empty($v[$k])) { $bad[] = "$o:$k"; } }
  }
  if (!(function_exists("DermaDirect\\MigrationSafety\\is_active") && DermaDirect\MigrationSafety\is_active())) { $bad[] = "harness-inactive"; }
  if (!is_wp_error(wp_remote_get("https://example.com"))) { $bad[] = "outbound-http-open"; }
  if (wp_mail("dd-verify@example.invalid", "dd verify", "x") !== false && !has_filter("pre_wp_mail")) { $bad[] = "mail-open"; }
  echo $bad ? implode(" ", $bad) : "none";' 2>/dev/null)
ck "gateways test mode + keys blank, harness active, outbound HTTP and mail blocked" "$R" none

[ $fails = 0 ] && echo "VERIFY OK (server side)" || { echo "VERIFY FAILED: $fails check(s)"; exit 1; }
