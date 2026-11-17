<?php
/**
 * Plugin Name: DermaDirect commerce rules (Nexfill Switch offer, PRODERMIS code restrictions)
 * Description: Business rules approved and live on dermadirect.co.uk since 2026-10-07 (Paul's fixes 1, 2, 3, 5),
 *              carried into the new site. Rendering stays in the Sage theme (Helper::getProductPricingInfo).
 *
 * Source of truth for the offer PRICE and END DATE: the Discount Rules PRO rule "Nexfill Switch - Prosculpt
 * Buy 5 Get 5 £205 ex VAT" (fixed price 246 inc VAT, role nexfill_switch, ends 2027-01-31 23:59:59 Europe/London).
 * This plugin only asks that rule (it never hard-codes the price) and:
 *   1. exposes the logged-in customer's offer price to the theme (filter dd_customer_offer_price);
 *   2. stops discount codes reducing PRODERMIS products (category slug below) or the offer bundle, for anyone,
 *      while codes keep working on the rest of a mixed basket — WooCommerce coupons and code-triggered
 *      Discount Rules rules alike; a PRODERMIS-only basket gets a clear refusal message;
 *   3. Mix and Match fixed-price bundles: no false "was" price (fix 2) and the offer price in the selector (fix 1).
 * Not included (decisions pending): corrected "was" prices on bundles, header greeting, shipping insurance,
 * Blocked Customer enforcement.
 */

declare(strict_types=1);

namespace DermaDirect\CommerceRules;

defined('ABSPATH') || exit;

const NEXFILL_BUNDLE_ID = 667263;          // Prosculpt Buy 5 Get 5 Launch Bundle (same ID on legacy and new site)
const NEXFILL_ROLE = 'nexfill_switch';
const NO_CODE_CATEGORY_SLUG = 'prodermis%ef%b8%8f'; // PRODERMIS product_cat (term 634 on both sites; slug verified 2026-10-20)

/** term_id of the PRODERMIS category, resolved by slug (IDs are never assumed to match across sites). */
function no_code_category_id(): int
{
    static $id = null;
    if ($id === null) {
        $t = get_term_by('slug', NO_CODE_CATEGORY_SLUG, 'product_cat');
        $id = $t ? (int) $t->term_id : 0;
        if (!$id && function_exists('wc_get_logger')) {
            wc_get_logger()->error('PRODERMIS category not found by slug: code restriction covers the bundle only', ['source' => 'dd-commerce-rules']);
        }
    }
    return $id;
}

/** Offer price (as stored, inc VAT) for the logged-in customer, or null when the offer does not apply. */
function offer_price($product): ?float
{
    static $cache = [];
    if (!$product instanceof \WC_Product || !is_user_logged_in() || (int) $product->get_id() !== NEXFILL_BUNDLE_ID) {
        return null;
    }
    if (!in_array(NEXFILL_ROLE, (array) wp_get_current_user()->roles, true)) {
        return null;
    }
    $key = get_current_user_id();
    if (!array_key_exists($key, $cache)) {
        $regular = (float) $product->get_price();
        $price = apply_filters('advanced_woo_discount_rules_get_product_discount_price_from_custom_price', $regular, $product, 1, $regular, 'discounted_price', true, false);
        $cache[$key] = ($price === false || $price === '' || (float) $price >= $regular) ? null : (float) $price;
    }
    return $cache[$key];
}

add_filter('dd_customer_offer_price', fn ($price, $product) => offer_price($product) ?? $price, 10, 2);

function is_code_excluded_product($product): bool
{
    if (!is_object($product)) {
        return false;
    }
    $id = $product->get_parent_id() ?: $product->get_id();
    if ((int) $id === NEXFILL_BUNDLE_ID) {
        return true;
    }
    $cat = no_code_category_id();
    return $cat > 0 && has_term($cat, 'product_cat', $id);
}

// WooCommerce coupons: PRODERMIS items are left out of whatever a code applies to.
add_filter('woocommerce_coupon_get_items_to_apply', function ($items) {
    return array_values(array_filter((array) $items, fn ($item) => empty($item->product) || !is_code_excluded_product($item->product)));
}, 20);

// Refuse a code (clear message) only when nothing in the basket can take a discount.
add_filter('woocommerce_coupon_is_valid', function ($valid) {
    if (!$valid || !function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
        return $valid;
    }
    foreach (WC()->cart->get_cart() as $item) {
        if (!empty($item['mnm_container'])) {
            continue; // a bundle's own boxes are judged by the bundle itself
        }
        if (empty($item['data']) || !is_code_excluded_product($item['data'])) {
            return $valid;
        }
    }
    throw new \Exception(esc_html__('Discount codes can\'t be used on Prodermis products.', 'derma-direct'), 100);
}, 20);

// Discount Rules: rules triggered by a code skip PRODERMIS items.
add_filter('advanced_woo_discount_rules_filter_passed', function ($passed, $rule, $product) {
    if (!$passed || !is_object($rule) || !method_exists($rule, 'getConditions')) {
        return $passed;
    }
    if (!is_code_excluded_product(is_object($product) ? $product : wc_get_product($product))) {
        return $passed;
    }
    foreach ((array) $rule->getConditions() as $condition) {
        if (is_object($condition) && isset($condition->type) && $condition->type === 'cart_coupon') {
            return false;
        }
    }
    return $passed;
}, 20, 3);

// Mix and Match fixed-price bundles: no false "was" (items' prices added on top of the bundle price), and the
// offer price for eligible customers.
add_filter('wc_mnm_container_price_data', function ($data, $product) {
    if (!is_array($data) || !$product) {
        return $data;
    }
    if (method_exists($product, 'is_priced_per_product') && !$product->is_priced_per_product() && !empty($data['regular_prices'])) {
        foreach ($data['regular_prices'] as $id => $p) {
            $data['regular_prices'][$id] = 0;
        }
    }
    $offer = offer_price($product);
    $regular = (float) $product->get_price();
    if ($offer !== null && $regular > 0) {
        $ratio = $offer / $regular; // same units whether the data is ex or inc VAT
        foreach (['base_price', 'base_regular_price', 'price', 'regular_price', 'raw_container_price_min', 'raw_container_price_max', 'raw_container_min_price', 'raw_container_price', 'raw_container_min_regular_price', 'raw_container_regular_price'] as $k) {
            if (isset($data[$k]) && $data[$k] !== '') {
                $data[$k] = round((float) $data[$k] * $ratio, 4);
            }
        }
    }
    return $data;
}, 20, 2);
