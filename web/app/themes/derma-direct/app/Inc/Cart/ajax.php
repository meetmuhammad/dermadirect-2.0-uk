<?php
// AJAX handler for updating cart item quantity
function ddce_ajax_update_qty() {
    check_ajax_referer( 'ddce_cart_nonce', 'nonce' );
    if ( empty( $_POST['cart_item_key'] ) ) {
        wp_send_json_error();
    }
    $cart_item_key = sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) );
    $quantity      = isset( $_POST['quantity'] ) ? max( 0, intval( $_POST['quantity'] ) ) : 0;
    $cart = WC()->cart;
    if ( ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
        wp_send_json_error( array( 'message' => __( 'This item is no longer in your cart.', 'sage' ) ) );
    }
    $current_item     = $cart->cart_contents[ $cart_item_key ];
    $current_quantity = $current_item['quantity'];
    // Removal (qty 0) always allowed — skip cap/stock checks
    if ( 0 === $quantity ) {
        $cart->remove_cart_item( $cart_item_key );
        $cart->calculate_totals();
        wp_send_json_success(
            array(
                'cart_subtotal'  => wc_price( $cart->get_subtotal() ),
                'cart_total'     => wc_price( $cart->get_total( 'edit' ) ),
                'cart_total_raw' => (float) $cart->get_cart_contents_total(),
                'cart_is_empty'  => $cart->is_empty(),
            )
        );
    }
    $hard_cap = 50;
    $product  = $current_item['data'];
    // Hard cap — flat 50, never tied to stock
    if ( $quantity > $hard_cap ) {
        wp_send_json_error(
            array(
                'message'         => __( 'Maximum order quantity per product is 50.', 'sage' ),
                'reset_quantity'  => $current_quantity,
            )
        );
    }
    // Stock check — generic message, product name only, never the number. Only applies when
    // increasing quantity: a decrease can never exceed stock, and skipping the check here
    // matters because it must still be possible to reduce a line item even if its existing
    // quantity is already at or above current stock (e.g. stock dropped after the item was
    // added) — otherwise the customer would get stuck, unable to reduce it at all, since every
    // failed attempt resets the input back to that same over-stock quantity.
    if ( $quantity > $current_quantity && $product && $product->managing_stock() && ! $product->backorders_allowed() ) {
        if ( ! $product->has_enough_stock( $quantity ) ) {
            wp_send_json_error(
                array(
                    /* translators: %s: product name */
                    'message'        => sprintf( __( '"%s" is not available in the quantity you requested.', 'sage' ), $product->get_name() ),
                    'reset_quantity' => $current_quantity,
                )
            );
        }
    }
    $cart->set_quantity( $cart_item_key, $quantity, true );
    $cart->calculate_totals();
    $line_subtotal = '';
    if ( isset( $cart->cart_contents[ $cart_item_key ] ) ) {
        $item          = $cart->cart_contents[ $cart_item_key ];
        $line_subtotal = $cart->get_product_subtotal( $item['data'], $item['quantity'] );
    }
    wp_send_json_success(
        array(
            'line_subtotal'  => $line_subtotal,
            'cart_subtotal'  => wc_price( $cart->get_subtotal() ),
            'cart_total'     => wc_price( $cart->get_total( 'edit' ) ),
            'cart_total_raw' => (float) $cart->get_cart_contents_total(),
            'cart_is_empty'  => $cart->is_empty(),
        )
    );
}
add_action( 'wp_ajax_ddce_update_qty', 'ddce_ajax_update_qty' );
add_action( 'wp_ajax_nopriv_ddce_update_qty', 'ddce_ajax_update_qty' );