#!/bin/sh
# Application phase after the SQL sync (WordPress IS bootstrapped here, so it runs only against a
# rehearsal DB with the local safety mu-plugin active: egress blocked, mail to Mailpit, gateways
# empty, Action Scheduler runner off). Each step is timed.
#   scripts/migration/postsync.sh dd_rh_dest <run_id|all>
set -eu
cd "$(dirname "$0")/../.."
DB=$1; RUN=${2:-all}
W="./scripts/migration/wp.sh $DB"
# an administrator that exists in THIS database (a hosted-derived baseline has no local-only accounts)
ADMIN=$($W user list --role=administrator --field=user_login | grep -E "^[A-Za-z0-9._@-]+$" | head -1)
[ -n "$ADMIN" ] || { echo "no administrator account in $DB"; exit 2; }
t() { s=$(date +%s); "$@"; echo "  [$(( $(date +%s)-s ))s] $*" | cut -c1-160; }

# Scope 2026-10-19: the commerce-data refresh changes no destination configuration and no product
# data. Plugin activation (Decision 3) and product-derived regeneration run only when asked for.
if [ "${ACTIVATE_RETAINED:-0}" = 1 ]; then
echo "== retained legacy capabilities (Decision 3)"
t $W plugin deactivate woo-advanced-shipment-tracking --quiet || true
t $W plugin activate super-payments woocommerce-gateway-rules-manager product-batch-expiry-tracking-for-woocommerce woocommerce-back-in-stock-notifications wpgens-refer-a-friend-premium advanced-shipment-tracking-pro --quiet
fi
echo "== HPOS mirror (posts authoritative, sync on): rebuild rows for synced orders, then verify"
t $W wc hpos count_unmigrated
t $W wc hpos sync --batch-size=500
t $W wc hpos verify_data --batch-size=1000 --order-types=shop_order,shop_order_refund || echo "  HPOS verify reported mismatches: compare with the pre-sync baseline (docs/migration/PLAN.md §9.5)"
if [ "${PRODUCT_DERIVED:-0}" = 1 ]; then
PS=$(docker compose --profile cli run --rm -T --no-deps --entrypoint php wpcli -r 'echo (require "scripts/migration/config.php")["product_sync"] ? 1 : 0;' 2>/dev/null)
[ "$PS" = 1 ] || { echo "refusing PRODUCT_DERIVED=1: product_sync is off (destination products are never written)"; exit 2; }
echo "== product derived data"
t $W wc tool run regenerate_product_lookup_tables --user="$ADMIN"
t $W wc tool run recount_terms --user="$ADMIN"
fi
t $W wc tool run clear_transients --user="$ADMIN"
# Analytics rows are mirrored from legacy by sync.php (entity "analytics"); no PHP import needed.
echo "== caches"
t $W rewrite flush
t $W cache flush
