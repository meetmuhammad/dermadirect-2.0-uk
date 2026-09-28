<?php
/**
 * One-time correction of legacy-owned records that carry DevKinsta's local domain
 * (httpsdermadirectcom.local) instead of the real legacy domain (dermadirect.co.uk).
 * The September import came from a DevKinsta copy; the Kinsta baseline therefore already contains
 * these values. The sync compares both sides URL-normalised, so it cannot see them — hence this step.
 *   php restore-legacy-domain.php plan|apply --dest=<db>     [MIG_HOSTED=1 --confirm-dest=<db> on Kinsta]
 * Scope (legacy-owned only): orders/refunds/coupons (posts, meta, notes, note meta), order items and
 * item meta, customer accounts (customer / blocked_customer roles) and their meta, migrated plugin and
 * analytics tables. NOT touched: staff accounts, destination settings/options, pages/products/content.
 * Serialization-safe; values that cannot be safely rewritten are reported and left unchanged.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/refs.php';

const FROM_HOST = 'httpsdermadirectcom.local';
const TO_HOST = 'dermadirect.co.uk';

$a = args($argv);
$mode = $a['_'][0] ?? fail('mode required: plan|apply');
$dest = $a['dest'] ?? fail('--dest required');
guard_dbs($dest, null, $a);
$db = connect($dest);
$D = dest_ref($dest);
if ((int) one($db, "SELECT GET_LOCK('dd_migration', 0)") !== 1) {
    fail('another migration run holds the dd_migration lock');
}
if (one($db, "SELECT option_value FROM {$D['options']} WHERE option_name='stylesheet'") !== 'derma-direct') {
    fail("$dest is not the new site — refusing");
}
// Same ordering as the sync: the full DevKinsta site URL maps to this destination's home first
// (longest key wins), then the bare host maps to the real legacy domain. The ':8443' repair pair fixes
// values produced by an earlier bare-host-only run of this tool (never present in authentic data).
$destHome = (string) one($db, "SELECT option_value FROM {$D['options']} WHERE option_name='home'");
$pairs = \DDMig\Refs\url_pairs([['https://' . FROM_HOST . ':8443', $destHome], ['https://' . TO_HOST . ':8443', $destHome], [FROM_HOST, TO_HOST]]);
$match = fn (string $col) => "(`$col` LIKE '%" . FROM_HOST . "%' OR `$col` LIKE '%" . TO_HOST . ":8443%')";
$commerce = "SELECT ID FROM {$D['posts']} WHERE post_type IN ('shop_order','shop_order_refund','shop_coupon')";
$customers = "SELECT user_id FROM {$D['usermeta']} WHERE meta_key='wp_capabilities' AND (meta_value LIKE '%\"customer\"%' OR meta_value LIKE '%\"blocked_customer\"%')";
$textCols = function (string $table) use ($db, $dest): array {
    return array_column(rows($db, 'SELECT column_name c FROM information_schema.columns WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, $table)
        . " AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext')"), 'c');
};
// [table, primary key, row filter]
$targets = [
    ['wp_posts', 'ID', "ID IN ($commerce)"],
    ['wp_postmeta', 'meta_id', "post_id IN ($commerce)"],
    ['wp_comments', 'comment_ID', "comment_post_ID IN ($commerce)"],
    ['wp_commentmeta', 'meta_id', "comment_id IN (SELECT comment_ID FROM {$D['comments']} WHERE comment_post_ID IN ($commerce))"],
    ['wp_users', 'ID', "ID IN ($customers)"],
    ['wp_usermeta', 'umeta_id', "user_id IN ($customers)"],
];
foreach (['wp_woocommerce_order_items' => 'order_item_id', 'wp_woocommerce_order_itemmeta' => 'meta_id', 'wp_woocommerce_payment_tokens' => 'token_id',
    'wp_woocommerce_payment_tokenmeta' => 'meta_id', 'wp_webis_pbet' => 'id', 'wp_webis_pbet_order' => 'id', 'wp_webis_pbet_order_item' => 'id',
    'wp_woocommerce_bis_notifications' => 'id', 'wp_woocommerce_bis_notificationsmeta' => 'meta_id', 'wp_woocommerce_bis_activity' => 'id',
    'wp_gens_raf' => 'id', 'wp_gens_rafmeta' => 'meta_id', 'wp_gens_raf_guest_referrals' => 'id', 'wp_wt_sc_coupon_lookup' => 'id',
    'wp_wdr_order_item_discounts' => 'id', 'wp_pp_order_actions_log' => 'id', 'wp_trackship_shipment' => 'id', 'wp_trackship_shipment_meta' => 'meta_id',
    'wp_zorem_email_sms_log' => 'id', 'wp_stock_log' => 'ID', 'wp_wc_order_stats' => 'order_id', 'wp_wc_order_product_lookup' => 'order_item_id',
    'wp_wc_order_tax_lookup' => 'order_id', 'wp_wc_order_coupon_lookup' => 'order_id', 'wp_wc_customer_lookup' => 'customer_id'] as $t => $pk) {
    if ((int) one($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, $t))) {
        $targets[] = [$t, $pk, '1=1'];
    }
}
$report = ['destination' => $dest, 'mode' => $mode, 'at' => gmdate('c'), 'changed' => [], 'unresolved' => []];
foreach ($targets as [$t, $pk, $filter]) {
    // composite-key lookup tables: address rows by all key columns
    $pkCols = array_column(rows($db, 'SELECT column_name c FROM information_schema.key_column_usage WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, $t) . " AND constraint_name='PRIMARY' ORDER BY ordinal_position"), 'c') ?: [$pk];
    foreach ($textCols($t) as $col) {
        $sel = implode(',', array_map(fn ($c) => "`$c`", $pkCols));
        $res = q($db, "SELECT $sel, `$col` AS __v FROM `$dest`.`$t` WHERE ($filter) AND " . $match($col));
        $n = 0;
        $batch = 0;
        if ($mode === 'apply') {
            q($db, 'START TRANSACTION');
        }
        while ($r = $res->fetch_assoc()) {
            $new = \DDMig\Refs\rewrite_urls((string) $r['__v'], $pairs);
            if ($new === null) {
                $report['unresolved'][] = "$t.$col " . json_encode(array_intersect_key($r, array_flip($pkCols)));
                continue;
            }
            if ($mode === 'apply') {
                $where = implode(' AND ', array_map(fn ($c) => "`$c`=" . e($db, $r[$c]), $pkCols));
                q($db, "UPDATE `$dest`.`$t` SET `$col`=" . e($db, $new) . " WHERE $where");
                if (++$batch % 5000 === 0) {
                    q($db, 'COMMIT');
                    q($db, 'START TRANSACTION');
                }
            }
            $n++;
        }
        if ($mode === 'apply') {
            q($db, 'COMMIT');
        }
        if ($n) {
            $report['changed']["$t.$col"] = $n;
        }
    }
}
$report['total'] = array_sum($report['changed']);
file_put_contents(report_dir() . "/restore-legacy-domain-$mode-" . gmdate('Ymd\THis') . '.json', json_encode($report, JSON_PRETTY_PRINT));
out(json_encode(['total' => $report['total'], 'changed' => $report['changed'], 'unresolved' => count($report['unresolved'])]));
exit($report['unresolved'] ? 1 : 0);
