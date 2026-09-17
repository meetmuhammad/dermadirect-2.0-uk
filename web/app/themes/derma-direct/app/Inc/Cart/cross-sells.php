<?php 
// Smarter cross-sells function for the cart page but currently cross sell has to be filled in the product edit page for each product. 
// This function will show cross-sells based on the categories of the products in the cart if no cross-sells are set for the products in the cart.
function ddce_smarter_cross_sells( $cross_sell_ids ) {
	if ( ! empty( $cross_sell_ids ) || WC()->cart->is_empty() ) {
		return $cross_sell_ids;
	}

	$cart_product_ids = array();
	$category_ids     = array();

	foreach ( WC()->cart->get_cart() as $item ) {
		$cart_product_ids[] = $item['product_id'];
		$terms = get_the_terms( $item['product_id'], 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$category_ids[] = $term->term_id;
			}
		}
	}

	if ( empty( $category_ids ) ) {
		return $cross_sell_ids;
	}

	return wc_get_products(
		array(
			'category' => array_unique( $category_ids ),
			'exclude'  => $cart_product_ids,
			'limit'    => 4,
			'orderby'  => 'popularity',
			'status'   => 'publish',
			'return'   => 'ids',
		)
	);
}
add_filter( 'woocommerce_cart_crosssell_ids', 'ddce_smarter_cross_sells' );