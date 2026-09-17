<?php
/**
 * ============================================================================
 * Returns the current mini-cart HTML/count without changing anything. Used as a fallback when a
 * product is added via a plain page navigation (?add-to-cart=ID) instead of WooCommerce's own
 * ajax_add_to_cart click handler — e.g. when that delegated click handler doesn't intercept the
 * click in time — so the drawer can still open with accurate contents. See the
 * dd_clean_up_fallback_add_to_cart() redirect in filters.php for the server-side half of this.
*/
add_action('wp_ajax_refresh_minicart', 'refresh_minicart');
add_action('wp_ajax_nopriv_refresh_minicart', 'refresh_minicart');
function refresh_minicart() {
    check_ajax_referer('ajax-nonce', 'nonce');

    ob_start();
    echo view('woocommerce.mini-cart-content', \App\View\Composers\MiniCart::buildData())->render();
    $html = ob_get_clean();

    wp_send_json([
        'fragments' => [
            'updated_minicart_content' => $html,
            'cart_count'              => WC()->cart->get_cart_contents_count(),
        ],
    ]);
}

/**
 * ============================================================================
 * Below Ajax callback is used to update the product qty in minicart when
 * the user clicks +/- buttons.
*/
add_action('wp_ajax_update_minicart_qty', 'update_minicart_qty');
add_action('wp_ajax_nopriv_update_minicart_qty', 'update_minicart_qty');
function update_minicart_qty() {
    check_ajax_referer('ajax-nonce', 'nonce');

    if ( empty($_POST['product_id']) || empty($_POST['quantity']) ) {
        wp_send_json_error(['message' => 'Invalid data']);
    }

    $product_id = intval($_POST['product_id']);
    $new_qty    = intval($_POST['quantity']);

    // Hard cap — flat 50, matches the cart page and single product page.
    $hard_cap = 50;
    if ($new_qty < 1) $new_qty = 1;

    // Find cart item by product id
    $cart_item_key    = null;
    $current_quantity = null;
    foreach (WC()->cart->get_cart() as $key => $cart_item) {
        if ($cart_item['product_id'] === $product_id) {
            $cart_item_key    = $key;
            $current_quantity = $cart_item['quantity'];
            break;
        }
    }

    if ( null === $cart_item_key ) {
        wp_send_json_error(['message' => __('This item is no longer in your cart.', 'sage')]);
    }

    if ($new_qty > $hard_cap) {
        wp_send_json_error([
            'message'        => __('Maximum order quantity per product is 50.', 'sage'),
            'reset_quantity' => $current_quantity,
        ]);
    }

    // Stock check — generic message, product name only, never the number. Only applies when
    // increasing quantity: a decrease can never exceed stock, and skipping the check here
    // matters because it must still be possible to reduce a line item even if its existing
    // quantity is already at or above current stock (e.g. stock dropped after the item was
    // added) — otherwise the customer would get stuck, unable to reduce it at all, since every
    // failed attempt resets the input back to that same over-stock quantity.
    $product = WC()->cart->get_cart_item($cart_item_key)['data'];
    if ($new_qty > $current_quantity && $product && $product->managing_stock() && ! $product->backorders_allowed()) {
        if (! $product->has_enough_stock($new_qty)) {
            wp_send_json_error([
                /* translators: %s: product name */
                'message'        => sprintf(__('"%s" is not available in the quantity you requested.', 'sage'), $product->get_name()),
                'reset_quantity' => $current_quantity,
            ]);
        }
    }

    WC()->cart->set_quantity($cart_item_key, $new_qty, true);

    // Return the same custom response as the other mini-cart handlers so the
    // JS can update #minicart_content and the cart badge via explicit keys.
    ob_start();
    echo view('woocommerce.mini-cart-content', \App\View\Composers\MiniCart::buildData())->render();
    $html = ob_get_clean();

    wp_send_json([
        'fragments' => [
            'updated_minicart_content' => $html,
            'cart_count'              => WC()->cart->get_cart_contents_count(),
        ],
    ]);
}

/**
 * ============================================================================
 * Delete item from mini-cart (AJAX)
*/
add_action('wp_ajax_delete_minicart_item', 'delete_minicart_item');
add_action('wp_ajax_nopriv_delete_minicart_item', 'delete_minicart_item');
function delete_minicart_item() {
    if (empty($_POST['product_id'])) {
        wp_send_json_error(['message' => 'Invalid product']);
    }

    $product_id = intval($_POST['product_id']);

    // Remove item from cart
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if ($cart_item['product_id'] === $product_id) {
            WC()->cart->remove_cart_item($cart_item_key);
            break;
        }
    }

    // Return updated mini-cart content
    ob_start();
    echo view('woocommerce.mini-cart-content', \App\View\Composers\MiniCart::buildData())->render();
    $html = ob_get_clean();

    wp_send_json([
        'fragments' => [
            'updated_minicart_content' => $html,
        ],
    ]);
}

/**
 * ============================================================================
 * Apply promocode to the cart items.
*/
add_action('wp_ajax_apply_minicart_coupon', 'apply_minicart_coupon');
add_action('wp_ajax_nopriv_apply_minicart_coupon', 'apply_minicart_coupon');
function apply_minicart_coupon() {
    check_ajax_referer('ajax-nonce', 'nonce');

    if (empty($_POST['coupon_code'])) {
        wp_send_json_error(['message' => __('Please enter a coupon code', 'sage')]);
    }

    $coupon_code = sanitize_text_field($_POST['coupon_code']);
    $coupon      = new WC_Coupon($coupon_code);

    // 1. Coupon does not exist
    if (!$coupon->get_id()) {
        wp_send_json_error(['message' => __('This coupon does not exist.', 'sage')]);
    }

    // 2. Validate coupon using WC_Discounts
    $discounts = new WC_Discounts(WC()->cart);
    $validation = $discounts->is_coupon_valid($coupon);

    if (is_wp_error($validation)) {
        wp_send_json_error(['message' => $validation->get_error_message()]);
    }

    // 3. Apply coupon
    $applied = WC()->cart->apply_coupon($coupon_code);

    if (!$applied || !WC()->cart->has_discount($coupon_code)) {
        wp_send_json_error(['message' => __('Unable to apply this coupon.', 'sage')]);
    }

    // 4. Rebuild mini-cart
    ob_start();
    echo view('woocommerce.mini-cart-content', \App\View\Composers\MiniCart::buildData())->render();
    $html = ob_get_clean();

    wp_send_json([
        'success' => true,
        'message' => sprintf(__('Coupon "%s" applied!', 'sage'), $coupon_code),
        'fragments' => [
            'updated_minicart_content' => $html,
        ],
    ]);
}

/**
 * ============================================================================
 * Remove coupon from mini-cart (AJAX)
 */
add_action('wp_ajax_remove_minicart_coupon', 'remove_minicart_coupon');
add_action('wp_ajax_nopriv_remove_minicart_coupon', 'remove_minicart_coupon');
function remove_minicart_coupon() {
    check_ajax_referer('ajax-nonce', 'nonce');

    if (empty($_POST['coupon_code'])) {
        wp_send_json_error(['message' => 'Invalid coupon']);
    }

    $coupon_code = sanitize_text_field($_POST['coupon_code']);

    // Remove coupon
    WC()->cart->remove_coupon($coupon_code);
    WC()->cart->calculate_totals();

    // Rebuild mini-cart content using your view composer
    ob_start();
    echo view('woocommerce.mini-cart-content', \App\View\Composers\MiniCart::buildData())->render();
    $html = ob_get_clean();

    wp_send_json([
        'success' => true,
        'message' => sprintf(__('Coupon "%s" removed!', 'sage'), $coupon_code),
        'fragments' => [
            'updated_minicart_content' => $html,
        ],
    ]);
}
