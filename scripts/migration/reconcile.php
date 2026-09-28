<?php
/**
 * Independent reconciliation after a sync (read-only). Deliberately does not reuse sync.php's
 * hashing: it recomputes business-level facts on both sides.
 *   php reconcile.php --src=dd_rh_src --dest=dd_rh_dest --before=dd_rh_test_presync
 * Exit 1 if any check fails. Writes migration-reports/reconcile-<ts>.json.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';

$a = args($argv);
$SRC = src_ref($a);
$DST = dest_ref($a['dest'] ?? fail('--dest required'));
$src = $SRC->schema;
$dest = $a['dest'] ?? fail('--dest required');
$before = $a['before'] ?? null;
guard_dbs($dest, $SRC, $a);
$db = connect($dest);
$res = ['checks' => [], 'at' => gmdate('c'), 'source' => source_identity($db, $SRC), 'destination' => $dest];
$since = $a['since'] ?? gmdate('Y-m-d H:i:s', time() - 86400);
$check = function (string $name, bool $ok, $detail = null) use (&$res) {
    $res['checks'][$name] = ['ok' => $ok, 'detail' => $detail];
    out(($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== null ? '  ' . (is_string($detail) ? $detail : json_encode($detail)) : ''));
};
$commercialIn = implode(',', array_map(fn ($k) => e($db, $k), cfg()['product_commercial']));
$legacyOrders = "SELECT ID FROM {$SRC['posts']} WHERE post_type IN ('shop_order','shop_order_refund')";

// 1. Orders/refunds: counts and money by type, status, currency (legacy records only; destination-only kept separately)
$agg = fn ($S, $restrict) => rows($db, "SELECT p.post_type t, p.post_status s, COALESCE(c.meta_value,'') cur, COUNT(*) n,
    ROUND(SUM(CAST(COALESCE(tot.meta_value,'0') AS DECIMAL(18,4))),2) total, ROUND(SUM(CAST(COALESCE(tax.meta_value,'0') AS DECIMAL(18,4))),2) tax,
    ROUND(SUM(CAST(COALESCE(sh.meta_value,'0') AS DECIMAL(18,4))),2) ship, ROUND(SUM(CAST(COALESCE(ds.meta_value,'0') AS DECIMAL(18,4))),2) disc
    FROM {$S['posts']} p LEFT JOIN {$S['postmeta']} c ON c.post_id=p.ID AND c.meta_key='_order_currency'
    LEFT JOIN {$S['postmeta']} tot ON tot.post_id=p.ID AND tot.meta_key='_order_total' LEFT JOIN {$S['postmeta']} tax ON tax.post_id=p.ID AND tax.meta_key='_order_tax'
    LEFT JOIN {$S['postmeta']} sh ON sh.post_id=p.ID AND sh.meta_key='_order_shipping' LEFT JOIN {$S['postmeta']} ds ON ds.post_id=p.ID AND ds.meta_key='_cart_discount'
    WHERE p.post_type IN ('shop_order','shop_order_refund') $restrict GROUP BY 1,2,3 ORDER BY 1,2,3");
$sa = $agg($SRC, '');
$da = $agg($DST, "AND p.ID IN ($legacyOrders)");
$res['order_totals'] = ['legacy' => $sa, 'destination' => $da];
$check('orders/refunds: count and totals by type/status/currency match', $sa == $da, count($sa) . ' groups; ' . array_sum(array_column($sa, 'n')) . ' records');
$destOnly = rows($db, "SELECT ID, post_type, post_status FROM `$dest`.wp_posts WHERE post_type IN ('shop_order','shop_order_refund') AND ID NOT IN ($legacyOrders)");
$check('destination-only orders are kept (reported, not deleted)', true, $destOnly);

// 2. Line items: per-order item count and summed line totals
$items = fn ($S, $restrict) => rows($db, "SELECT COUNT(*) items, ROUND(SUM(CAST(COALESCE(lt.meta_value,'0') AS DECIMAL(18,4))),2) line_total, ROUND(SUM(CAST(COALESCE(tx.meta_value,'0') AS DECIMAL(18,4))),2) line_tax
    FROM {$S['woocommerce_order_items']} oi LEFT JOIN {$S['woocommerce_order_itemmeta']} lt ON lt.order_item_id=oi.order_item_id AND lt.meta_key='_line_total'
    LEFT JOIN {$S['woocommerce_order_itemmeta']} tx ON tx.order_item_id=oi.order_item_id AND tx.meta_key='_line_tax' WHERE oi.order_id IN ($legacyOrders) $restrict")[0];
$si = $items($SRC, '');
$di = $items($DST, '');
$check('order items: count, line totals and line tax match', $si == $di, ['legacy' => $si, 'destination' => $di]);
$check('order item IDs preserved', (int) one($db, "SELECT COUNT(*) FROM {$SRC['woocommerce_order_items']} s LEFT JOIN `$dest`.wp_woocommerce_order_items d ON d.order_item_id=s.order_item_id AND d.order_id=s.order_id WHERE d.order_item_id IS NULL") === 0);

// 3. Notes
$notes = fn ($S) => (int) one($db, "SELECT COUNT(*) FROM {$S['comments']} WHERE comment_post_ID IN ($legacyOrders)");
$check('order notes: count match and IDs preserved', $notes($SRC) === $notes($DST) && (int) one($db, "SELECT COUNT(*) FROM {$SRC['comments']} s LEFT JOIN `$dest`.wp_comments d ON d.comment_ID=s.comment_ID AND d.comment_post_ID=s.comment_post_ID WHERE s.comment_post_ID IN ($legacyOrders) AND d.comment_ID IS NULL") === 0, $notes($DST));

// 4. Ownership: every legacy order's customer is the same user ID and that user exists on the destination with the same login
$own = rows($db, "SELECT COUNT(*) orders, SUM(dc.meta_value <=> sc.meta_value) same_customer, SUM(sc.meta_value>0) registered, SUM(sc.meta_value>0 AND du.ID IS NOT NULL AND du.user_login=su.user_login) owner_ok, SUM(sc.meta_value=0) guest
    FROM {$SRC['posts']} s JOIN {$SRC['postmeta']} sc ON sc.post_id=s.ID AND sc.meta_key='_customer_user' JOIN `$dest`.wp_postmeta dc ON dc.post_id=s.ID AND dc.meta_key='_customer_user'
    LEFT JOIN {$SRC['users']} su ON su.ID=sc.meta_value LEFT JOIN `$dest`.wp_users du ON du.ID=sc.meta_value WHERE s.post_type='shop_order'")[0];
$regWithUser = (int) one($db, "SELECT COUNT(*) FROM {$SRC['posts']} s JOIN {$SRC['postmeta']} sc ON sc.post_id=s.ID AND sc.meta_key='_customer_user' JOIN {$SRC['users']} su ON su.ID=sc.meta_value WHERE s.post_type='shop_order'");
$check('order ownership: same customer ID; owner exists with same login', $own['orders'] == $own['same_customer'] && (int) $own['owner_ok'] === $regWithUser, $own + ['registered_with_existing_legacy_user' => $regWithUser]);

// 5. Refund parents
$check('refunds: every refund parent is an order on the destination', (int) one($db, "SELECT COUNT(*) FROM `$dest`.wp_posts r LEFT JOIN `$dest`.wp_posts o ON o.ID=r.post_parent AND o.post_type='shop_order' WHERE r.post_type='shop_order_refund' AND o.ID IS NULL") === (int) one($db, "SELECT COUNT(*) FROM {$SRC['posts']} r LEFT JOIN {$SRC['posts']} o ON o.ID=r.post_parent AND o.post_type='shop_order' WHERE r.post_type='shop_order_refund' AND o.ID IS NULL"));

// 6. Product links on line items: dangling references must not exceed legacy's own
$dangling = fn ($S) => (int) one($db, "SELECT COUNT(*) FROM {$S['woocommerce_order_itemmeta']} im JOIN {$S['woocommerce_order_items']} oi ON oi.order_item_id=im.order_item_id
    LEFT JOIN {$S['posts']} p ON p.ID=im.meta_value WHERE oi.order_id IN ($legacyOrders) AND im.meta_key IN ('_product_id','_variation_id') AND im.meta_value>0 AND (p.ID IS NULL OR p.post_type NOT IN ('product','product_variation'))");
$wrongType = (int) one($db, "SELECT COUNT(*) FROM `$dest`.wp_woocommerce_order_itemmeta im JOIN `$dest`.wp_posts p ON p.ID=im.meta_value WHERE im.meta_key IN ('_product_id','_variation_id') AND im.meta_value>0 AND p.post_type NOT IN ('product','product_variation')");
$check('line-item product links: no link to a non-product; dangling <= legacy', $wrongType === 0 && $dangling($DST) <= $dangling($SRC), ['legacy_dangling' => $dangling($SRC), 'destination_dangling' => $dangling($DST), 'links_to_non_product' => $wrongType]);

// 7. Payment references
$refKeys = ['_transaction_id', 'super_transaction_id', '_stripe_intent_id', '_payment_intent_id', '_stripe_customer_id', '_wc_shipment_tracking_items', 'marketing_opt_in_consent', 'wc_af_score'];
$pr = fn ($S) => rows($db, "SELECT meta_key, COUNT(*) n, MD5(GROUP_CONCAT(CONCAT(post_id,'=',MD5(meta_value)) ORDER BY post_id, meta_value)) h FROM {$S['postmeta']} WHERE post_id IN ($legacyOrders) AND meta_key IN ('" . implode("','", $refKeys) . "') GROUP BY meta_key ORDER BY meta_key");
$check('payment/shipping/consent/anti-fraud references identical', $pr($SRC) == $pr($DST), array_column($pr($DST), 'n', 'meta_key'));

// 8. Customers: password hashes, roles, addresses for all non-staff legacy users
$cust = fn ($S) => one($db, "SELECT MD5(GROUP_CONCAT(CONCAT(u.ID,':',u.user_login,':',u.user_pass,':',COALESCE(c.meta_value,''),':',COALESCE(b.meta_value,''),':',COALESCE(p.meta_value,'')) ORDER BY u.ID)) FROM {$S['users']} u
    JOIN {$SRC['usermeta']} sc ON sc.user_id=u.ID AND sc.meta_key='wp_capabilities' AND (sc.meta_value LIKE '%\"customer\"%' OR sc.meta_value LIKE '%\"blocked_customer\"%') AND sc.meta_value NOT LIKE '%administrator%'
    LEFT JOIN {$S['usermeta']} c ON c.user_id=u.ID AND c.meta_key='wp_capabilities' LEFT JOIN {$S['usermeta']} b ON b.user_id=u.ID AND b.meta_key='billing_postcode' LEFT JOIN {$S['usermeta']} p ON p.user_id=u.ID AND p.meta_key='shipping_postcode'");
$check('customers: IDs, logins, password hashes, roles, address fields identical', $cust($SRC) === $cust($DST));
$blocked = fn ($S) => (int) one($db, "SELECT COUNT(*) FROM {$S['usermeta']} WHERE meta_key='wp_capabilities' AND meta_value LIKE '%blocked_customer%'");
$check('blocked_customer restrictions preserved (not unblocked)', $blocked($DST) >= $blocked($SRC), ['legacy' => $blocked($SRC), 'destination' => $blocked($DST)]);

// 9. Coupons and usage
$cp = fn ($S) => rows($db, "SELECT COUNT(*) n, SUM(CAST(COALESCE(u.meta_value,'0') AS UNSIGNED)) usage_total FROM {$S['posts']} p LEFT JOIN {$S['postmeta']} u ON u.post_id=p.ID AND u.meta_key='usage_count' WHERE p.post_type='shop_coupon' AND p.ID IN (SELECT ID FROM {$SRC['posts']} WHERE post_type='shop_coupon')")[0];
$check('coupons: count and total usage match; IDs preserved', $cp($SRC) == $cp($DST), $cp($DST));

// 10. Retained plugin tables: row counts and content checksums
$tbl = [];
foreach (['wp_woocommerce_payment_tokens', 'wp_woocommerce_payment_tokenmeta', 'wp_webis_pbet', 'wp_webis_pbet_order', 'wp_webis_pbet_order_item', 'wp_woocommerce_bis_notifications', 'wp_woocommerce_bis_activity', 'wp_gens_raf', 'wp_gens_rafmeta', 'wp_trackship_shipment', 'wp_wdr_order_item_discounts'] as $t) {
    $cs = rows($db, "CHECKSUM TABLE {$SRC[$t]}, `$dest`.`$t`");
    $n = [(int) one($db, "SELECT COUNT(*) FROM {$SRC[$t]}"), (int) one($db, "SELECT COUNT(*) FROM `$dest`.`$t`")];
    $tbl[$t] = ['rows' => $n, 'checksum_equal' => $cs[0]['Checksum'] === $cs[1]['Checksum']];
}
$check('retained plugin tables: legacy rows all present (dest-only rows allowed)', !array_filter($tbl, fn ($x) => $x['rows'][1] < $x['rows'][0]), $tbl);

// 10b. Analytics (derived) mirror legacy for legacy orders
$an = fn ($S) => rows($db, "SELECT COUNT(*) n, ROUND(SUM(net_total),2) net, ROUND(SUM(total_sales),2) sales, SUM(num_items_sold) items FROM {$S['wc_order_stats']} WHERE order_id IN ($legacyOrders)")[0];
$check('analytics order stats equal to legacy', $an($SRC) == $an($DST), $an($DST));

// 11. Product commercial fields match legacy for identity-verified products
$pc = one($db, "SELECT COUNT(*) FROM {$SRC['postmeta']} s JOIN {$SRC['posts']} sp ON sp.ID=s.post_id AND sp.post_type IN ('product','product_variation') JOIN `$dest`.wp_posts dp ON dp.ID=sp.ID AND dp.post_type=sp.post_type
    LEFT JOIN `$dest`.wp_postmeta d ON d.post_id=s.post_id AND d.meta_key=s.meta_key AND d.meta_value<=>s.meta_value
    WHERE s.meta_key IN ($commercialIn) AND d.meta_id IS NULL
    AND (SELECT meta_value FROM {$SRC['postmeta']} WHERE post_id=sp.ID AND meta_key='_sku' LIMIT 1) <=> (SELECT meta_value FROM `$dest`.wp_postmeta WHERE post_id=dp.ID AND meta_key='_sku' LIMIT 1)");
$check('product commercial fields equal to legacy (identity-verified products)', (int) $pc === 0, "$pc mismatching values");

// 12. Protected destination content unchanged by the sync (compared with the pre-sync copy)
if ($before) {
    $commerce = "'shop_order','shop_order_refund','shop_coupon'";
    $placeholders = "SELECT post_id FROM `$dest`.wp_postmeta WHERE meta_key='_dd_migration_placeholder'";
    $postsDiff = (int) one($db, "SELECT COUNT(*) FROM `$before`.wp_posts b LEFT JOIN `$dest`.wp_posts a ON a.ID=b.ID AND a.post_modified_gmt=b.post_modified_gmt AND a.post_content=b.post_content AND a.post_title=b.post_title AND a.post_status=b.post_status AND a.post_name=b.post_name AND a.post_parent=b.post_parent AND a.post_excerpt=b.post_excerpt AND a.guid=b.guid WHERE b.post_type NOT IN ($commerce) AND a.ID IS NULL");
    $newPosts = rows($db, "SELECT a.post_type, COUNT(*) n FROM `$dest`.wp_posts a LEFT JOIN `$before`.wp_posts b ON b.ID=a.ID WHERE a.post_type NOT IN ($commerce) AND b.ID IS NULL AND a.ID NOT IN ($placeholders) GROUP BY 1");
    $commercial = $commercialIn;
    $metaSide = fn ($X) => "SELECT pm.post_id, pm.meta_key, MD5(pm.meta_value) h FROM `$X`.wp_postmeta pm JOIN `$before`.wp_posts p ON p.ID=pm.post_id AND p.post_type NOT IN ($commerce) WHERE NOT (p.post_type IN ('product','product_variation') AND pm.meta_key IN ($commercial)) AND pm.meta_key NOT IN ('_edit_lock')";  // _edit_lock: editor-session stamp, not content
    $metaDiff = (int) one($db, "SELECT COUNT(*) FROM (SELECT post_id, meta_key, h, COUNT(*) c FROM ({$metaSide($before)}) x GROUP BY 1,2,3) b LEFT JOIN (SELECT post_id, meta_key, h, COUNT(*) c FROM ({$metaSide($dest)}) y GROUP BY 1,2,3) a ON a.post_id=b.post_id AND a.meta_key=b.meta_key AND a.h=b.h AND a.c=b.c WHERE a.post_id IS NULL");
    $metaAdded = (int) one($db, "SELECT COUNT(*) FROM (SELECT post_id, meta_key, h FROM ({$metaSide($dest)}) y) a LEFT JOIN (SELECT post_id, meta_key, h FROM ({$metaSide($before)}) x) b ON b.post_id=a.post_id AND b.meta_key=a.meta_key AND b.h=a.h WHERE b.post_id IS NULL");
    $optVolatile = "b.option_name NOT LIKE '\\_transient%' AND b.option_name NOT LIKE '\\_site\\_transient%' AND b.option_name NOT IN ('cron','action_scheduler_lock_async-request-runner','dd_safety_blocked_log','rewrite_rules','generateblocks_dynamic_css_time') AND b.option_name NOT LIKE 'wc\\_%\\_session%'";
    $optDiff = array_column(rows($db, "SELECT b.option_name FROM `$before`.wp_options b LEFT JOIN `$dest`.wp_options a ON a.option_name=b.option_name AND a.option_value<=>b.option_value WHERE a.option_id IS NULL AND $optVolatile LIMIT 50"), 'option_name');
    $termDiff = (int) one($db, "SELECT COUNT(*) FROM `$before`.wp_terms b LEFT JOIN `$dest`.wp_terms a ON a.term_id=b.term_id AND a.name=b.name AND a.slug=b.slug WHERE a.term_id IS NULL")
        + (int) one($db, "SELECT COUNT(*) FROM `$before`.wp_termmeta b LEFT JOIN `$dest`.wp_termmeta a ON a.meta_id=b.meta_id AND a.meta_value=b.meta_value WHERE a.meta_id IS NULL");
    $relDiff = (int) one($db, "SELECT COUNT(*) FROM `$before`.wp_term_relationships b JOIN `$before`.wp_term_taxonomy tt ON tt.term_taxonomy_id=b.term_taxonomy_id LEFT JOIN `$dest`.wp_term_relationships a ON a.object_id=b.object_id AND a.term_taxonomy_id=b.term_taxonomy_id WHERE a.object_id IS NULL AND tt.taxonomy<>'product_visibility'");
    $check('protected content: destination-owned posts unchanged', $postsDiff === 0 && !$newPosts, ['changed_or_missing' => $postsDiff, 'unexpected_new' => $newPosts]);
    $check('protected content: presentation meta unchanged (commercial allowlist excluded)', $metaDiff === 0 && $metaAdded <= (int) one($db, "SELECT COUNT(*) FROM `$dest`.wp_postmeta WHERE post_id IN ($placeholders)"), ['changed_or_missing' => $metaDiff, 'added' => $metaAdded]);
    // Options written by the approved application phase (retained plugins activated, Decision 3).
    $appPhase = ['active_plugins', 'recently_activated', 'woocommerce_gateway_order', 'wc_advanced_shipment_tracking', 'user_role_editor', 'ptk_patterns',
        'action_scheduler_migration_status', 'woocommerce_product_lookup_table_is_generating'];
    $res['options_changed_by_application_phase'] = array_values(array_intersect($optDiff, $appPhase));
    $optDiff = array_values(array_diff($optDiff, $appPhase));
    $check('protected content: options unchanged (caches/queues excluded; approved plugin-activation options listed)', !$optDiff, ['unexpected' => $optDiff, 'approved_application_phase' => $res['options_changed_by_application_phase']]);
    $check('protected content: terms, term meta, non-visibility term relationships unchanged', $termDiff === 0 && $relDiff === 0, ['terms' => $termDiff, 'relationships' => $relDiff]);
}

// 13. No side effects: no queued/sent external actions created since the rehearsal copy was taken
$as = rows($db, "SELECT status, COUNT(*) n FROM `$dest`.wp_actionscheduler_actions WHERE last_attempt_gmt > '$since' OR scheduled_date_gmt > '$since' GROUP BY status");
$check('no Action Scheduler work attempted during rehearsal (webhooks, emails, jobs)', !array_filter($as, fn ($r) => $r['status'] !== 'pending'), $as);
$ok = !array_filter($res['checks'], fn ($c) => !$c['ok']);
file_put_contents(report_dir() . '/reconcile-' . gmdate('Ymd\THis') . '.json', json_encode($res, JSON_PRETTY_PRINT));
out($ok ? 'RECONCILE OK' : 'RECONCILE FAILED');
exit($ok ? 0 : 1);
