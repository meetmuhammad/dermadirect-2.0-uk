<?php
// Free delivery threshold function for the cart page and threshold value from theme settings
function ddce_get_free_delivery_threshold(): float
{
    if ( function_exists( 'get_field' ) ) {
        $value = get_field( 'free_delivery_threshold', 'option' );

        if ( is_numeric( $value ) ) {
            return (float) $value;
        }
    }

    return 50.00; // Fallback
}
// Cutoff time function for the cart page and cutoff time value from dermadirect-tools-menu plugin settings
function ddce_get_cutoff_time(): array {
	$default = array( 'hour' => 22, 'minute' => 0 );

	if ( ! function_exists( 'get_field' ) || ! class_exists( '\App\DermadirectToolsMenu\NextDayDeliveryTimer\NextDayDeliveryTimerManager' ) ) {
		return $default;
	}

	$manager_class = '\App\DermadirectToolsMenu\NextDayDeliveryTimer\NextDayDeliveryTimerManager';
	$cutoff_time   = get_field( $manager_class::FIELD_CUTOFF_TIME, 'option' );

	if ( ! is_array( $cutoff_time ) ) {
		return $default;
	}

	return array(
		'hour'   => isset( $cutoff_time['hour'] ) ? max( 0, min( 23, (int) $cutoff_time['hour'] ) ) : $default['hour'],
		'minute' => isset( $cutoff_time['minute'] ) ? max( 0, min( 59, (int) $cutoff_time['minute'] ) ) : $default['minute'],
	);
}

// Site notifications function for the account dashboard, filtered by active start/end datetime from theme settings
function ddce_get_active_notifications(): array {
	if ( ! function_exists( 'get_field' ) ) {
		return [];
	}
	$notifications = get_field( 'site_notifications', 'option' );
	if ( ! is_array( $notifications ) || empty( $notifications ) ) {
		return [];
	}
	$now = current_time( 'timestamp' );
	return array_values( array_filter( $notifications, function ( $notification ) use ( $now ) {
		$start = ! empty( $notification['start_datetime'] ) ? strtotime( $notification['start_datetime'] ) : null;
		$end   = ! empty( $notification['end_datetime'] ) ? strtotime( $notification['end_datetime'] ) : null;
		if ( $start && $now < $start ) {
			return false;
		}
		if ( $end && $now > $end ) {
			return false;
		}
		return true;
	} ) );
}

// Sums the quantity of all cart line items matching a given product id — the single source of
// truth for "how much of this product is already in the cart", used to keep the 50-per-product
// cap consistent across the single product page, mini-cart, and cart page.
function ddce_get_cart_quantity_for_product( int $product_id ): int {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0;
	}

	$quantity = 0;

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( (int) $cart_item['product_id'] === $product_id ) {
			$quantity += (int) $cart_item['quantity'];
		}
	}

	return $quantity;
}

// Maps a notification type to its Tailwind border/background classes for the account dashboard
function ddce_notification_type_classes( string $type ): string {
	$map = [
		'warning' => 'border-yellow-200 bg-yellow-50',
		'info'    => 'border-blue-200 bg-blue-50',
		'offer'   => 'border-green-200 bg-green-50',
		'error'   => 'border-red-200 bg-red-50',
	];
	return $map[ $type ] ?? $map['info'];
}
?>