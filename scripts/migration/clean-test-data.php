<?php
/**
 * Removes positively identified synthetic rehearsal records (tests/fixtures-manifest.json).
 *   php clean-test-data.php plan  --dest=<db>
 *   php clean-test-data.php apply --dest=<db>
 * Afterwards run sync.php apply from the UNMODIFIED legacy snapshot to restore real records the
 * tests touched, then postsync + reconcile. Idempotent: a second run finds nothing.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';

$a = args($argv);
$mode = $a['_'][0] ?? fail('mode required: plan|apply');
$dest = $a['dest'] ?? fail('--dest required');
guard_dbs($dest, null, $a);
$db = connect($dest);
$D = dest_ref($dest);
$m = json_decode(file_get_contents(__DIR__ . '/tests/fixtures-manifest.json'), true);
$like = fn ($prefix) => e($db, addcslashes($prefix, '%_\\') . '%');
$suffix = fn ($s) => e($db, '%' . addcslashes($s, '%_\\'));

$users = array_map('intval', array_column(rows($db, "SELECT ID FROM {$D['users']} WHERE user_login LIKE {$like($m['users']['login_prefix'])} AND user_email LIKE {$suffix($m['users']['email_suffix'])}"), 'ID'));
$types = implode(',', array_map(fn ($t) => e($db, $t), $m['orders']['post_types']));
$orders = array_map('intval', array_column(rows($db, "SELECT ID FROM {$D['posts']} WHERE post_type IN ($types) AND post_title LIKE {$like($m['orders']['title_prefix'])}"), 'ID'));
$noteWhere = "comment_type='order_note' AND comment_content LIKE {$like($m['notes']['content_prefix'])} AND comment_author_email=" . e($db, $m['notes']['author_email']);
$notes = array_map('intval', array_column(rows($db, "SELECT comment_ID FROM {$D['comments']} WHERE $noteWhere"), 'comment_ID'));
$bis = array_map('intval', array_column(rows($db, "SELECT id FROM {$D['woocommerce_bis_notifications']} WHERE user_email LIKE {$suffix($m['back_in_stock']['email_suffix'])}"), 'id'));
// Safety: a test order must not be owned by a real customer, and every refund must belong to a test order.
$u = $users ? implode(',', $users) : '0';
$o = $orders ? implode(',', $orders) : '0';
$realOwned = (int) one($db, "SELECT COUNT(*) FROM {$D['postmeta']} WHERE post_id IN ($o) AND meta_key='_customer_user' AND meta_value NOT IN ('0',$u)");
$orphanRefund = (int) one($db, "SELECT COUNT(*) FROM {$D['posts']} WHERE ID IN ($o) AND post_type='shop_order_refund' AND post_parent NOT IN ($o)");
if ($realOwned || $orphanRefund) {
    fail("identification not clean: $realOwned test orders owned by real customers, $orphanRefund refunds of non-test orders");
}
$plan = ['users' => $users, 'orders_and_refunds' => $orders, 'notes' => $notes, 'back_in_stock' => $bis];
out('plan: ' . json_encode($plan));
if ($mode === 'apply' && ($users || $orders || $notes || $bis)) {
    $n = [];
    $b = $bis ? implode(',', $bis) : '0';
    $del = function (string $sql) use ($db, &$n) { q($db, $sql); $n[] = $db->affected_rows; };
    q($db, 'START TRANSACTION');
    // orders/refunds and everything keyed by them
    $del("DELETE im FROM {$D['woocommerce_order_itemmeta']} im JOIN {$D['woocommerce_order_items']} oi ON oi.order_item_id=im.order_item_id WHERE oi.order_id IN ($o)");
    $del("DELETE FROM {$D['woocommerce_order_items']} WHERE order_id IN ($o)");
    $del("DELETE cm FROM {$D['commentmeta']} cm JOIN {$D['comments']} c ON c.comment_ID=cm.comment_id WHERE c.comment_post_ID IN ($o) OR ($noteWhere)");
    $del("DELETE FROM {$D['comments']} WHERE comment_post_ID IN ($o) OR ($noteWhere)");
    $del("DELETE FROM {$D['postmeta']} WHERE post_id IN ($o)");
    $del("DELETE FROM {$D['posts']} WHERE ID IN ($o)");
    foreach (['wc_orders' => 'id', 'wc_order_addresses' => 'order_id', 'wc_order_operational_data' => 'order_id', 'wc_orders_meta' => 'order_id',
        'wc_order_stats' => 'order_id', 'wc_order_product_lookup' => 'order_id', 'wc_order_tax_lookup' => 'order_id', 'wc_order_coupon_lookup' => 'order_id',
        'pp_order_actions_log' => 'order_id', 'wdr_order_item_discounts' => 'order_id', 'webis_pbet_order' => 'order_id', 'zorem_email_sms_log' => 'order_id',
        'woocommerce_downloadable_product_permissions' => 'order_id'] as $t => $col) {
        $del("DELETE FROM {$D[$t]} WHERE `$col` IN ($o)");
    }
    // queued work referencing test records (e.g. webhook deliveries for a test order)
    foreach (array_merge($orders, $users) as $id) {
        $del("DELETE FROM {$D['actionscheduler_actions']} WHERE status='pending' AND (args LIKE '%\"arg\":$id}%' OR args LIKE '%,$id,%' OR args LIKE '%[$id]%')");
    }
    // test customers
    $del("DELETE FROM {$D['usermeta']} WHERE user_id IN ($u)");
    $del("DELETE FROM {$D['users']} WHERE ID IN ($u)");
    $del("DELETE FROM {$D['wc_customer_lookup']} WHERE user_id IN ($u)");
    $del("DELETE FROM {$D['woocommerce_sessions']} WHERE session_key IN (" . implode(',', array_map(fn ($x) => e($db, (string) $x), $users ?: [0])) . ')');
    $del("DELETE FROM {$D['woocommerce_bis_activity']} WHERE notification_id IN ($b) OR user_id IN ($u)");
    $del("DELETE FROM {$D['woocommerce_bis_notificationsmeta']} WHERE bis_notifications_id IN ($b)");
    $del("DELETE FROM {$D['woocommerce_bis_notifications']} WHERE id IN ($b)");
    $del("DELETE FROM `$dest`.dd_mig_ledger WHERE (entity IN ('orders','coupons') AND entity_id IN ($o)) OR (entity='users' AND entity_id IN ($u))");
    q($db, 'COMMIT');
    out('deleted rows per statement: ' . json_encode($n));
}
file_put_contents(report_dir() . "/clean-test-data-$mode-" . gmdate('Ymd\THis') . '.json', json_encode($plan, JSON_PRETTY_PRINT));
