#!/bin/sh
# Application phase after the SQL sync (WordPress IS bootstrapped here, so it runs only against a
# rehearsal DB with the local safety mu-plugin active: egress blocked, mail to Mailpit, gateways
# empty, Action Scheduler runner off). Each step is timed.
#   scripts/migration/postsync.sh dd_rh_dest <run_id|all>
set -eu
cd "$(dirname "$0")/../.."
DB=$1; RUN=${2:-all}
W="./scripts/migration/wp.sh $DB"
ADMIN=$(sed -n 's/^RH_ADMIN_LOGIN=//p' secrets/rehearsal-admin.env)
t() { s=$(date +%s); "$@"; echo "  [$(( $(date +%s)-s ))s] $*" | cut -c1-160; }

echo "== retained legacy capabilities (Decision 3)"
t $W plugin deactivate woo-advanced-shipment-tracking --quiet || true
t $W plugin activate super-payments woocommerce-gateway-rules-manager product-batch-expiry-tracking-for-woocommerce woocommerce-back-in-stock-notifications wpgens-refer-a-friend-premium advanced-shipment-tracking-pro --quiet
echo "== HPOS mirror (posts authoritative, sync on): rebuild rows for synced orders, then verify"
t $W wc hpos count_unmigrated
t $W wc hpos sync --batch-size=500
t $W wc hpos verify_data --batch-size=1000 --order-types=shop_order,shop_order_refund || echo "  HPOS verify reported mismatches: compare with the pre-sync baseline (docs/migration/PLAN.md §9.5)"
echo "== product derived data"
t $W wc tool run regenerate_product_lookup_tables --user="$ADMIN"
t $W wc tool run clear_transients --user="$ADMIN"
t $W wc tool run recount_terms --user="$ADMIN"
# Analytics rows are mirrored from legacy by sync.php (entity "analytics"); no PHP import needed.
echo "== caches"
t $W rewrite flush
t $W cache flush
