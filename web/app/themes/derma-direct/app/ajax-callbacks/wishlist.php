<?php

/**
 * Wishlist AJAX handlers.
 *
 * Supports both logged-in users (user_meta) and guests (cookie).
 * All actions are nonce-verified and inputs are sanitized.
*/

declare(strict_types=1);

use App\Services\WishlistService;

// ─── Toggle ──────────────────────────────────────────────────────────────────

add_action('wp_ajax_dd_toggle_wishlist',        'dd_toggle_wishlist');
add_action('wp_ajax_nopriv_dd_toggle_wishlist', 'dd_toggle_wishlist');

function dd_toggle_wishlist(): void
{
    check_ajax_referer('dd_wishlist_nonce', 'nonce');

    $product_id = absint($_POST['product_id'] ?? 0);

    if (!$product_id) {
        wp_send_json_error(['message' => __('Invalid product.', 'sage')], 400);
    }

    // Verify the product exists and is published/purchasable.
    $product = wc_get_product($product_id);

    if (!$product || !$product->is_visible()) {
        wp_send_json_error(['message' => __('Product not found.', 'sage')], 404);
    }

    $result = WishlistService::toggle($product_id);

    wp_send_json_success([
        'in_wishlist' => $result['in_wishlist'],
        'count'       => $result['count'],
        'product_id'  => $product_id,
    ]);
}

// ─── Get wishlist data ────────────────────────────────────────────────────────

add_action('wp_ajax_dd_get_wishlist_data',        'dd_get_wishlist_data');
add_action('wp_ajax_nopriv_dd_get_wishlist_data', 'dd_get_wishlist_data');

function dd_get_wishlist_data(): void
{
    check_ajax_referer('dd_wishlist_nonce', 'nonce');

    wp_send_json_success([
        'count' => WishlistService::getCount(),
        'items' => WishlistService::getItems(),
    ]);
}

// ─── Merge guest wishlist on login ──────────────────────────────────────────

add_action('wp_login', function ($user_login, $user): void {
    // Temporarily set current user so is_user_logged_in() returns true.
    wp_set_current_user($user->ID);
    WishlistService::mergeGuestWishlist();
}, 10, 2);
