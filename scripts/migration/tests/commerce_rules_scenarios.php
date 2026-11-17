<?php
/**
 * One commerce-rules scenario per process (wp eval-file this file <scenario> <user_login|->), so no
 * per-request cache can leak between customers. Prints one JSON line. DISPOSABLE COPY ONLY (it builds carts).
 * Scenarios:
 *   price    Sage price helper output for the Nexfill bundle (purchase ex/inc VAT, on-sale flag)
 *   mixed    cart: PRODERMIS product + ordinary product, WooCommerce code ddtest10 (10%)
 *   prodonly cart: PRODERMIS product only, code ddtest10 (must be refused with the PRODERMIS message)
 *   wdrcode  cart: PRODERMIS + ordinary, Discount Rules code welcome10
 *   bundle   cart: Nexfill bundle (10 boxes) + ordinary product, code ddtest10
 */
const BUNDLE = 667263;
const PRODERMIS_ITEM = 644571;
const ORDINARY = 4416;
$scenario = $args[0] ?? 'price';
$login = $args[1] ?? '-';
if ($login !== '-') {
    $u = get_user_by('login', $login) ?: exit(json_encode(['error' => "no user $login"]) . "\n");
    wp_set_current_user($u->ID);
}
$out = ['scenario' => $scenario, 'user' => $login, 'roles' => $login === '-' ? [] : array_values(wp_get_current_user()->roles)];
if ($scenario === 'price') {
    $p = \App\Helper\Helper::getProductPricingInfo(BUNDLE);
    $plain = fn ($html) => html_entity_decode(trim(wp_strip_all_tags((string) $html)));
    $out += ['ex' => $plain($p['purchase_price_ex_vat']), 'inc' => $plain($p['purchase_price_inc_vat']), 'regular_ex' => $plain($p['regular_price_ex_vat']), 'on_sale' => (bool) $p['is_on_sale']];
    exit(json_encode($out) . "\n");
}
wc_load_cart();
WC()->cart->empty_cart();
wc_clear_notices();
$add = function (int $id, int $qty = 1, array $data = []) {
    $k = WC()->cart->add_to_cart($id, $qty, 0, [], $data);
    return $k !== false;
};
if ($scenario === 'bundle') {
    $children = $GLOBALS['wpdb']->get_results('SELECT child_item_id, product_id FROM ' . $GLOBALS['wpdb']->prefix . 'wc_mnm_child_items WHERE container_id=' . BUNDLE . ' ORDER BY menu_order', ARRAY_A);
    $config = [];
    $left = 10;
    foreach ($children as $i => $c) {
        $q = $i === count($children) - 1 ? $left : intdiv(10, count($children));
        $left -= $q;
        $config[(int) $c['child_item_id']] = ['product_id' => (int) $c['product_id'], 'child_item_id' => (int) $c['child_item_id'], 'quantity' => $q];
    }
    $out['bundle_added'] = $add(BUNDLE, 1, ['mnm_config' => $config]);
    $out['ordinary_added'] = $add(ORDINARY);
    $code = 'ddtest10';
} else {
    if ($scenario !== 'prodonly') {
        $out['ordinary_added'] = $add(ORDINARY);
    }
    $out['prodermis_added'] = $add(PRODERMIS_ITEM);
    $code = $scenario === 'wdrcode' ? 'welcome10' : 'ddtest10';
}
$out['code'] = $code;
$out['code_accepted'] = WC()->cart->apply_coupon($code);
$out['notices'] = array_map(fn ($n) => wp_strip_all_tags($n['notice']), wc_get_notices('error'));
wc_clear_notices();
WC()->cart->calculate_totals();
$lines = [];
foreach (WC()->cart->get_cart() as $item) {
    $pid = $item['product_id'];
    $role = $pid === BUNDLE ? 'bundle' : ($pid === ORDINARY ? 'ordinary' : (!empty($item['mnm_container']) ? 'bundle-child' : 'prodermis'));
    $lines[] = ['role' => $role, 'product_id' => $pid, 'qty' => $item['quantity'], 'subtotal_ex' => round((float) $item['line_subtotal'], 2),
        'total_ex' => round((float) $item['line_total'], 2), 'tax' => round((float) $item['line_tax'], 2), 'unit_price_stored' => (float) $item['data']->get_price()];
}
$out['lines'] = $lines;
$out['discount_ex'] = round((float) WC()->cart->get_discount_total(), 2);
$out['total_inc'] = round((float) WC()->cart->get_total('edit'), 2);
WC()->cart->empty_cart();
echo json_encode($out) . "\n";
