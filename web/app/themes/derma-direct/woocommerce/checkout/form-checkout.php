<?php
/**
 * Checkout Form — theme override (2-column layout)
 *
 * Left  : customer billing / shipping details
 * Right : order summary (top) + payment methods (bottom)
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout dd-checkout-layout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

	<div class="dd-checkout-left">

		<?php if ( $checkout->get_checkout_fields() ) : ?>
			<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

			<div id="customer_details">
				<?php do_action( 'woocommerce_checkout_billing' ); ?>
				<?php do_action( 'woocommerce_checkout_shipping' ); ?>
			</div>

			<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
		<?php endif; ?>

	</div>

	<div class="dd-checkout-right">

		<div class="dd-checkout-order-review">
			<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
			<h3 id="order_review_heading"><?php esc_html_e( 'Your order', 'woocommerce' ); ?></h3>
			<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

			<div id="order_review" class="woocommerce-checkout-review-order">
				<?php woocommerce_order_review(); ?>
			</div>

			<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
		</div>

		<div class="dd-checkout-payment">
			<?php
			if ( ! wp_doing_ajax() ) {
				do_action( 'woocommerce_review_order_before_payment' );
			}
			?>
			<div id="payment" class="woocommerce-checkout-payment">
				<?php if ( WC()->cart && WC()->cart->needs_payment() ) : ?>
					<ul class="wc_payment_methods payment_methods methods">
						<?php
						$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
						if ( ! empty( $available_gateways ) ) {
							foreach ( $available_gateways as $gateway ) {
								wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
							}
						} else {
							echo '<li>';
							wc_print_notice(
								apply_filters(
									'woocommerce_no_available_payment_methods_message',
									WC()->customer->get_billing_country()
										? esc_html__( 'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' )
										: esc_html__( 'Please fill in your details above to see available payment methods.', 'woocommerce' )
								),
								'notice'
							);
							echo '</li>';
						}
						?>
					</ul>
				<?php endif; ?>

				<div class="form-row place-order">
					<noscript>
						<?php printf( esc_html__( 'Since your browser does not support JavaScript, or it is disabled, please ensure you click the %1$sUpdate Totals%2$s button before placing your order.', 'woocommerce' ), '<em>', '</em>' ); ?>
						<br/>
						<button type="submit" class="button alt" name="woocommerce_checkout_update_totals" value="<?php esc_attr_e( 'Update totals', 'woocommerce' ); ?>">
							<?php esc_html_e( 'Update totals', 'woocommerce' ); ?>
						</button>
					</noscript>

					<?php wc_get_template( 'checkout/terms.php' ); ?>
					<?php do_action( 'woocommerce_review_order_before_submit' ); ?>

					<?php
					$order_button_text = apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) );
					echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'woocommerce_order_button_html',
						'<button type="submit" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>'
					);
					?>

					<?php do_action( 'woocommerce_review_order_after_submit' ); ?>
					<?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
				</div>
			</div>
		</div>

	</div>

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
