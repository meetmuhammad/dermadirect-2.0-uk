#!/bin/sh
# Outbound isolation of the canonical local site (:8088, dermadirect_local): network, application,
# mail, cron/queue. Browser-level blocking is asserted by qa/qa.mjs (every non-local request aborted).
set -u
cd "$(dirname "$0")/../../.."
fails=0; t() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fails=$((fails+1)); fi; }
W() { docker compose --profile cli run --rm -T --no-deps --entrypoint sh wpcli -c "php -d memory_limit=2G /usr/local/bin/wp $1 2>/dev/null | grep -v -e WC_SA_Email -e a-email.php" 2>/dev/null; }
C=dermadirect20uk-db-1; sql() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$@"' sh "$@"; }
t "web container cannot reach the internet (network egress blocked)" "! docker exec dermadirect20uk-web-1 php -r 'exit(@file_get_contents(\"http://example.com\", false, stream_context_create([\"http\"=>[\"timeout\"=>5]])) === false ? 1 : 0);'"
t "WordPress HTTP API blocked during bootstrap (harness)" "[ \"\$(W 'eval \"echo is_wp_error(wp_remote_get(\\\"https://api.stripe.com\\\")) ? \\\"BLOCKED\\\" : \\\"OPEN\\\";\"')\" = BLOCKED ]"
MP_BEFORE=$(curl -s http://localhost:18025/api/v1/messages | python3 -c 'import sys,json;print(json.load(sys.stdin).get("total",0))')
t "wp_mail short-circuited (nothing handed to a transport)" "W 'eval \"wp_mail(\\\"isolation-test@example.invalid\\\", \\\"dd isolation test\\\", \\\"x\\\");\"' >/dev/null; [ \"\$(curl -s http://localhost:18025/api/v1/messages | python3 -c 'import sys,json;print(json.load(sys.stdin).get(\"total\",0))')\" = \"$MP_BEFORE\" ]"
t "blocked mail recorded by the harness" "W 'option get dd_safety_blocked_log --format=json' | grep -q 'dd isolation test'"
t "WP-Cron disabled and Action Scheduler runner off" "[ \"\$(W 'eval \"echo (defined(\\\"DISABLE_WP_CRON\\\") && DISABLE_WP_CRON ? 1 : 0), (apply_filters(\\\"action_scheduler_allow_async_request_runner\\\", true) ? 1 : 0);\"')\" = 10 ]"
T0=$(date -u '+%Y-%m-%d %H:%M:%S'); curl -s -o /dev/null http://localhost:8088/wp/wp-cron.php; curl -s -o /dev/null "http://localhost:8088/wp/wp-admin/admin-ajax.php?action=as_async_request_queue_runner"; sleep 3
t "hitting wp-cron.php / queue runner executes no scheduled action" "[ \"\$(sql -e \"SELECT COUNT(*) FROM dermadirect_local.wp_actionscheduler_actions WHERE last_attempt_gmt >= '$T0'\")\" = 0 ]"
t "no active webhooks and no queued webhook deliveries" "[ \"\$(sql -e \"SELECT (SELECT COUNT(*) FROM dermadirect_local.wp_wc_webhooks WHERE status='active') + (SELECT COUNT(*) FROM dermadirect_local.wp_actionscheduler_actions WHERE status='pending' AND hook='woocommerce_deliver_webhook_async')\")\" = 0 ]"
t "payment gateways forced to test mode, live keys blank in memory" "[ \"\$(W 'eval \"\\\$s=get_option(\\\"woocommerce_superpayments_settings\\\"); \\\$t=get_option(\\\"woocommerce_stripe_settings\\\"); echo (\\\$s[\\\"test_mode\\\"]??\\\"\\\"), (empty(\\\$s[\\\"api_key\\\"]??\\\"\\\")?\\\"blank\\\":\\\"KEY\\\"), (\\\$t[\\\"testmode\\\"]??\\\"\\\");\"')\" = yesblankyes ]"
[ $fails = 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
