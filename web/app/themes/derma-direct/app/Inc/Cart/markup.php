<?php
// Trust strip markup for the cart page along with the proceed to checkout button
function ddce_trust_strip() {
	$items = get_field( 'trust_strip_items', 'option' );
	if ( empty( $items ) ) {
		return;
	}
	?>
	<ul class="ddce-trust-strip">
		<?php foreach ( $items as $item ) : ?>
			<li><span class="ddce-bullet" aria-hidden="true">&bull;</span> <?php echo esc_html( $item['strip_item_text'] ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php
}
add_action( 'woocommerce_proceed_to_checkout', 'ddce_trust_strip', 25 );

// ---- Countdown + progress bar markup -----------------------------
function ddce_cart_top_notices() {
	if ( WC()->cart->is_empty() ) {
		return;
	}
	?>
	<div id="ddce-delivery-countdown" class="ddce-countdown"></div>
	<div
		id="ddce-free-delivery-bar"
		class="ddce-free-delivery-bar"
		data-total="<?php echo esc_attr( WC()->cart->get_cart_contents_total() ); ?>"
	></div>
	<?php
}
add_action( 'woocommerce_before_cart_table', 'ddce_cart_top_notices' );