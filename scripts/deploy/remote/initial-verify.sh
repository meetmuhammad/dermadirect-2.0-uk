#!/bin/sh
# Runs ON the Kinsta review server after Action A apply: counts vs manifest, isolation checks.
set -eu
ROOT=$1; PKG=$2; cd "$ROOT"
P=$(sed -n "s/^DB_PREFIX=['\"]\{0,1\}\([^'\"]*\)['\"]\{0,1\}$/\1/p" .env | tail -1); P=${P:-wp_}
q() { wp db query --skip-column-names "$1"; }
echo "manifest counts: $(sed -n 's/.*"counts":\({[^}]*}\).*/\1/p' "$PKG/manifest.json" | head -c 400)"
echo "orders by status: $(q "SELECT GROUP_CONCAT(CONCAT(post_type,'/',post_status,'=',n)) FROM (SELECT post_type,post_status,COUNT(*) n FROM ${P}posts WHERE post_type IN ('shop_order','shop_order_refund') GROUP BY 1,2) z")"
echo "users=$(q "SELECT COUNT(*) FROM ${P}users") idmap=$(q "SELECT COUNT(*) FROM dd_mig_idmap") max_order=$(q "SELECT MAX(ID) FROM ${P}posts WHERE post_type='shop_order'")"
echo "home=$(q "SELECT option_value FROM ${P}options WHERE option_name='home'")"
AW=$(q "SELECT COUNT(*) FROM ${P}wc_webhooks WHERE status='active'"); PD=$(q "SELECT COUNT(*) FROM ${P}actionscheduler_actions WHERE status='pending' AND hook='woocommerce_deliver_webhook_async'")
echo "active webhooks=$AW pending webhook deliveries=$PD"
wp eval 'foreach (["woocommerce_superpayments_settings","woocommerce_stripe_settings","woocommerce_stripe_cc_settings"] as $o) { $v = get_option($o); echo $o, " test_mode=", $v["test_mode"] ?? $v["testmode"] ?? "?", " keys_blank=", empty($v["api_key"] ?? $v["secret_key"] ?? "") ? "yes" : "NO", "\n"; } echo "harness=", function_exists("DermaDirect\\MigrationSafety\\is_active") && DermaDirect\MigrationSafety\is_active() ? "ACTIVE" : "INACTIVE", "\n"; $r = wp_remote_get("https://example.com"); echo "outbound http: ", is_wp_error($r) ? "BLOCKED" : "OPEN", "\n";'
[ "$AW" = 0 ] && [ "$PD" = 0 ] || { echo "VERIFY FAILED: integrations not neutralised"; exit 1; }
echo "VERIFY OK (server side)"
