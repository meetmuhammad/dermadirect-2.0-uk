<?php
add_action('wp_ajax_set_currency_preference', 'set_currency_preference');
add_action('wp_ajax_nopriv_set_currency_preference', 'set_currency_preference');
function set_currency_preference() {
    check_ajax_referer('ajax-nonce', 'nonce');

    $currency = strtoupper(sanitize_text_field($_POST['currency'] ?? ''));

    // Must match currencies configured in WooCommerce > Currency (FOX plugin settings)
    $allowed = ['GBP', 'EUR', 'USD'];

    if (!in_array($currency, $allowed, true)) {
        wp_send_json_error(['message' => 'Invalid currency selected']);
    }

    // Tell FOX Currency Switcher to use this currency for prices, cart & checkout
    global $WOOCS;
    if (is_object($WOOCS)) {
        $WOOCS->set_currency($currency);
    }

    wp_send_json_success([
        'selected' => $currency,
        'message'  => 'Currency updated',
    ]);
}

add_action('wp_ajax_search_product_brands', __NAMESPACE__ . '\\search_product_brands');
add_action('wp_ajax_nopriv_search_product_brands', __NAMESPACE__ . '\\search_product_brands');

function search_product_brands() {

    $term = sanitize_text_field($_GET['term'] ?? '');
    if (!$term) {
        wp_send_json([]);
    }
    
    $brands = get_terms([
        'taxonomy'   => 'product_brand',
        'hide_empty' => true,
        'search'     => $term,
        'number'     => 5,
    ]);

    $results = [];

    foreach ($brands as $brand) {
        $results[] = [
            'name' => $brand->name,
            'url'  => get_term_link($brand),
        ];
    }

    wp_send_json($results);
}



/**
 * Toggle a product as "favourite" (pinned) for the current customer.
 * Stored in user meta as an array of product IDs.
 */
add_action('wp_ajax_toggle_favourite_product', 'handle_toggle_favourite_product');

function handle_toggle_favourite_product() {

    check_ajax_referer('favourite_products_nonce', 'nonce');

    if (! is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in'], 401);
    }

    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

    if (! $product_id || ! wc_get_product($product_id)) {
        wp_send_json_error(['message' => 'Invalid product'], 400);
    }

    $user_id = get_current_user_id();

    $favourites = get_user_meta($user_id, '_favourite_products', true);

    if (! is_array($favourites)) {
        $favourites = [];
    }

    $key = array_search($product_id, $favourites, true);

    if ($key !== false) {
        // Already favourited — remove it (unpin)
        unset($favourites[$key]);
        $favourites = array_values($favourites);
        $is_favourite = false;
    } else {
        // Not favourited yet — add it (pin), most recent pin first
        array_unshift($favourites, $product_id);
        $is_favourite = true;
    }

    update_user_meta($user_id, '_favourite_products', $favourites);

    wp_send_json_success([
        'is_favourite' => $is_favourite,
        'product_id'   => $product_id,
    ]);
}

/**
 * Helper: get the current customer's favourite product IDs.
 */
function get_customer_favourite_products($user_id = null) {

    if (! $user_id) {
        $user_id = get_current_user_id();
    }

    $favourites = get_user_meta($user_id, '_favourite_products', true);

    return is_array($favourites) ? $favourites : [];
}
