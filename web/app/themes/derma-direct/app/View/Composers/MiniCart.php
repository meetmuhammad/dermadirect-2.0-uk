<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;
use function Roots\bundle;

class MiniCart extends Composer
{
    protected static $views = [
        'woocommerce.mini-cart',
        'woocommerce.mini-cart-content',
    ];

    public function override(): array
    {
        $this->enqueueAssets();
        if (WC()->cart->get_cart_contents_count() < 1) return [];
        return self::buildData();
    }

    public static function buildData(): array
    {
        /**
         * Build the full data array for the mini-cart view.
         * All prices are computed from wc_get_price_to_display() so they always
         * reflect the active WOOCS display currency, not the base-currency values
         * stored in the WooCommerce cart session.
        */
        $cart_items      = self::getCartItems();
        $applied_coupons = self::getAppliedCoupons();
        $subtotal_raw    = self::getConvertedSubtotal();
        $coupon_raw      = self::getConvertedCouponTotal();
        $total_raw       = max(0.0, $subtotal_raw - $coupon_raw);

        return [
            'cart_items'          => $cart_items,
            'cart_subtotal_price' => wc_price($subtotal_raw),
            'cart_total_price'    => wc_price($total_raw),
            'applied_coupons'     => $applied_coupons,
        ];
    }

    public static function getCartItems(): array
    {
        $items = [];

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product    = $cart_item['data'];
            $product_id = $product->get_id();
            $quantity   = (int) $cart_item['quantity'];

            // wc_get_price_to_display() routes through apply_filters('woocommerce_product_get_price', ...)
            // which WOOCS hooks to return the active display-currency price — safe for every currency.
            $regular_unit = (float) wc_get_price_to_display($product, ['price' => $product->get_regular_price()]);
            $sale_unit    = (float) wc_get_price_to_display($product); // get_price() = sale price when on sale

            $regular_total = $regular_unit * $quantity;
            $sale_total    = $sale_unit * $quantity;

            $items[] = [
                'id'                        => $product_id,
                'quantity'                  => $quantity,
                'name'                      => $product->get_name(),
                'thumbnail_image'           => $product->get_image_id() ?: '',
                'is_discounted'             => ($regular_total - $sale_total) > 0.001,
                'regular_price_total'       => wc_price($regular_total),
                'price_for_customers_total' => wc_price($sale_total),
            ];
        }

        return $items;
    }

    public static function getAppliedCoupons(): array
    {
        $rate            = self::getWoocsRate();
        $applied_coupons = [];

        foreach (WC()->cart->get_coupons() as $code => $coupon) {
            $base_discount   = (float) WC()->cart->get_coupon_discount_amount($code);
            $applied_coupons[] = [
                'code'   => $code,
                'amount' => wc_price($base_discount * $rate),
            ];
        }

        return $applied_coupons;
    }

    private static function getConvertedSubtotal(): float
    {
        /**
         * Sum of (display-currency sale price × qty) for every cart item.
        */
        $subtotal = 0.0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $subtotal += (float) wc_get_price_to_display($cart_item['data']) * (int) $cart_item['quantity'];
        }

        return $subtotal;
    }

    private static function getConvertedCouponTotal(): float
    {
        /**
         * Total coupon discount converted to the active display currency.
         * Stored discount amounts in the cart session are always in base currency.
        */
        $rate  = self::getWoocsRate();
        $total = 0.0;

        foreach (WC()->cart->get_coupons() as $code => $_coupon) {
            $total += (float) WC()->cart->get_coupon_discount_amount($code) * $rate;
        }

        return $total;
    }

    private static function getWoocsRate(): float
    {
        /**
         * WOOCS exchange rate for the current display currency (defaults to 1.0).
        */
        global $WOOCS;

        if (!is_object($WOOCS) || empty($WOOCS->current_currency)) {
            return 1.0;
        }

        $currencies = $WOOCS->get_currencies();

        return (float)($currencies[$WOOCS->current_currency]['rate'] ?? 1.0);
    }

    private function enqueueAssets(): void
    {
        $mini_cart_script_uri = \Vite::asset('resources/js/mini-cart.js');
        $mini_cart_style_uri = \Vite::asset('resources/css/mini-cart.css');

        wp_enqueue_script('mini-cart-js', $mini_cart_script_uri, ['jquery'], null, true);
        wp_enqueue_style('mini-cart-css', $mini_cart_style_uri, [], null, 'all');

        wp_localize_script('mini-cart-js', 'minicart_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ajax-nonce'),
        ]);
    }
}
