<?php

namespace App\Helper;

class Helper
{
    public function __construct($property1, $property2)
    {
        // Hear the silence.
    }

    public static function getAvailableMenus(): array
    {
        /**
         * This method is used to get all the available menus from the database.
         * The return format is like this
         * [
         *   [ menu_slug => Menu Name ],
         *   [ menu_slug => Menu Name ],
         * ]
        */
        $menus = wp_get_nav_menus();

        $available_menus = array_reduce($menus, function($acc, $menu) {
            $acc[$menu->slug] = $menu->name;
            return $acc;
        }, []);

        return $available_menus;
    }

    public static function getMenuItems(string $menu_field_name, string $type='menu_name' ): array
    {
        /**
         * This method gets the selected menu from Theme Options page
         * and if the menu exists, it returns menu items, else an empty array.
        */
        $menu = $menu_field_name;

        if ('acf_field_name' === $type) {
            $menu = (string) get_field($menu_field_name, 'option') ?? '';
        }
        if (!is_nav_menu($menu)) return [];

        $items = wp_get_nav_menu_items($menu) ?: [];

        // Build hierarchical tree
        $menu_tree = [];
        $item_map  = [];

        foreach ($items as $item) {
            $item->children = [];
            $item_map[$item->ID] = $item;
        }

        foreach ($item_map as $item) {
            if ($item->menu_item_parent && isset($item_map[$item->menu_item_parent])) {
                $item_map[$item->menu_item_parent]->children[] = $item;
            } else {
                $menu_tree[] = $item;
            }
        }

        return $menu_tree;
    }

    public static function getMenuItemsByLocation(string $location): array
    {
        // Get all registered menu locations
        $locations = get_nav_menu_locations();
        if (empty($locations[$location])) return [];

        // Get menu object from location
        $menu_id = $locations[$location];
        $menu_items = wp_get_nav_menu_items($menu_id);
        if (!$menu_items) return [];

        // Build parent-child hierarchy
        $menu_tree = [];
        $item_map = [];

        foreach ($menu_items as $item) {
            $item->children = [];
            $item_map[$item->ID] = $item;
        }

        foreach ($item_map as $item) {
            if ($item->menu_item_parent && isset($item_map[$item->menu_item_parent])) {
                $item_map[$item->menu_item_parent]->children[] = $item;
            } else {
                $menu_tree[] = $item;
            }
        }

        return $menu_tree;
    }

    public static function getArrayItems( string $field_name, string $source='acf_field', int $page_id=0 ): array
    {
        /**
         * This helper method takes in an ACF field name of a repeater field and gets its items.
         * Then it applies array_filter to it so that any empty item from the array can be removed.
         *
         * The $source can be ACF field 'acf_field' OR Option field 'option'.
        */
        if ('' === $field_name) return [];

        $array_items = [];

        if ('option' === $source) {
            $array_items = (array) (get_field($field_name, 'option') ?? []);
        } else if ('acf_field' === $source) {
            $array_items = (array) (get_field($field_name) ?? []);
        } else if ('page_acf_field' === $source) {
            $array_items = (array) (get_field($field_name, $page_id) ?? []);
        }

        return array_filter($array_items);
    }

    public static function getBlockSectionClasses(): string
    {
        /**
         * This function gets fields from BlockSettings field group and processes the selected values.
         * Then it uses those values to make tailwind classes for the block's section element.
        */

        $padding_top = (string) (get_field('section_padding_top') ?? 'pt-10');
        $padding_bottom = (string) (get_field('section_padding_bottom') ?? 'pb-10');

        $background_color = (string) (get_field('section_background_color') ?? 'bg-white');

        // Addiing the classes into array. Later the array elements will be joined in the return statement.
        $tailwind_classes = [
            $padding_top,
            $padding_bottom,
            $background_color,
            'overflow-hidden',
        ];

        return join(' ', $tailwind_classes);
    }

    public static function getSliderControlFields(): array
    {
        /**
         * This function should be used with the SliderControls partial field.
         * It gets the slider control fields to pass to the block's template file.
        */
        $autoplay_slides = (bool) (get_field('autoplay_slides') ?? false);
        $run_slider_in_loop = (bool) (get_field('run_slider_in_loop') ?? false);

        return [
            'autoplay_slides' => $autoplay_slides ? 'autoplay' : 'no_autoplay',
            'slide_switching_delay' => (int) (get_field('slide_switching_delay') ?? '6000'),
            'run_slider_in_loop' => $run_slider_in_loop ? 'loop' : 'no_loop',
        ];
    }

    public static function getSliderControlFieldsGroup(string $field_group_name, string $field_prefix): array
    {
        /**
         * The purpose of making this function is to get the slider control fields where the
         * control fields are using more than one time in the block.
         *
         * For reference, check `blocks/InThePress.php` file to see how the field partial
         * is used more than one time in the same block.
        */
        if (empty($field_group_name ?? '')) return [];

        $slider_control_group = get_field($field_group_name) ?? [];


        $autoplay_slides = $slider_control_group['autoplay_slides'] ?? false;
        $slider_in_loop = $slider_control_group['run_slider_in_loop'] ?? false;
        $switching_delay = $slider_control_group['slide_switching_delay'] ?? '6000';

        return [
            "{$field_prefix}autoplay_slides" => $autoplay_slides ? 'autoplay' : 'no_autoplay',
            "{$field_prefix}slide_switching_delay" => $switching_delay,
            "{$field_prefix}run_slider_in_loop" => $slider_in_loop ? 'loop' : 'no_loop',
        ];
    }

    public static function getProductPricingInfo( int $product_id ) {
        $product = wc_get_product($product_id);
        if (!$product) return [];

        $regular = (float) $product->get_regular_price();
        $sale    = (float) $product->get_sale_price();
        $on_sale = $product->is_on_sale();

        // A variable product's parent carries no price of its own - the prices live on its
        // variations - so get_regular_price() returns '' and every variable product rendered as
        // "£ 0.00 ex. VAT". Fall back to the variation price range, which is what WooCommerce's own
        // price HTML uses. `false` skips the display-tax conversion so these stay raw values like
        // the simple-product branch, and the tax maths below is applied to them identically.
        if ($regular <= 0 && $product->is_type('variable')) {
            $regular = (float) $product->get_variation_regular_price('min', false);
            $min_price = (float) $product->get_variation_price('min', false);

            if ($on_sale && $min_price > 0 && $min_price < $regular) {
                $sale = $min_price;
            }
        }

        $purchase_raw = min($regular, $sale ?: $regular);

        $discount = $on_sale && $regular > 0
            ? round((($regular - $sale) / $regular) * 100, 2)
            : 0;

        // Derive both figures through WooCommerce's own tax API rather than a multiplier.
        //
        // The previous implementation summed WC_Tax rate columns, fell back to a hardcoded 20%, and
        // treated get_regular_price() as an ex-VAT figure. On the UK store that is wrong twice over:
        // `woocommerce_prices_include_tax` is "yes", so the entered price ALREADY includes VAT.
        // Teosyal RHA Kiss Volume is entered at 154.79 inc VAT; the old maths advertised
        // "154.79 ex VAT / 185.75 inc VAT" when the correct pair is 128.99 / 154.79 - a 20%
        // overstatement on every product in the catalogue.
        //
        // wc_get_price_excluding_tax()/wc_get_price_including_tax() honour prices_include_tax, the
        // product's tax class and tax status, compound and multi-rate setups, and customer-specific
        // rates. No hardcoded percentage, and correct on zero-rated and reduced-rate lines.
        $regular_ex_vat   = (float) wc_get_price_excluding_tax($product, ['price' => $regular]);
        $regular_inc_vat  = (float) wc_get_price_including_tax($product, ['price' => $regular]);
        $purchase_ex_vat  = (float) wc_get_price_excluding_tax($product, ['price' => $purchase_raw]);
        $purchase_inc_vat = (float) wc_get_price_including_tax($product, ['price' => $purchase_raw]);

        // Savings (based on Ex VAT prices)
        $saving_raw     = $on_sale ? ($regular_ex_vat - $purchase_ex_vat) : 0;
        $saving_amount  = $on_sale ? wc_price($saving_raw) : '';
        $saving_percent = ($on_sale && $regular_ex_vat > 0)
                            ? round((($regular_ex_vat - $purchase_ex_vat) / $regular_ex_vat) * 100)
                            : 0;

        return [
            'regular_price_raw'  => $regular,
            'sale_price_raw'     => $sale ?: $regular,
            'purchase_price_raw' => $purchase_raw,

            'regular_price_ex_vat'   => wc_price($regular_ex_vat),
            'regular_price_inc_vat'  => wc_price($regular_inc_vat),
            'purchase_price_ex_vat'  => wc_price($purchase_ex_vat),
            'purchase_price_inc_vat' => wc_price($purchase_inc_vat),

            'saving_amount'  => $saving_amount,
            'saving_percent' => $saving_percent,

            // Legacy fields
            'regular_price'  => wc_price($regular),
            'sale_price'     => wc_price($sale ?: $regular),
            'purchase_price' => wc_price($purchase_raw),

            'discount_pct' => $discount,
            'is_on_sale'   => $on_sale,
        ];
    }

    public static function renderStarRating($rating, $max_stars = 5) {
        $full_stars = floor($rating);
        $half_star  = ($rating - $full_stars) >= 0.5;
        $empty_stars = $max_stars - $full_stars - ($half_star ? 1 : 0);

        ob_start(); ?>
        <div class="flex items-center">
            <?php
            // Full stars
            for ($i = 0; $i < $full_stars; $i++): ?>
                <svg class="w-4 h-4 text-yellow-300 ms-1" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 22 20" aria-hidden="true">
                    <path d="M20.924 7.625a1.523 1.523 0 0 0-1.238-1.044l-5.051-.734-2.259-4.577a1.534 1.534 0 0 0-2.752 0L7.365 5.847l-5.051.734A1.535 1.535 0 0 0 1.463 9.2l3.656 3.563-.863 5.031a1.532 1.532 0 0 0 2.226 1.616L11 17.033l4.518 2.375a1.534 1.534 0 0 0 2.226-1.617l-.863-5.03L20.537 9.2a1.523 1.523 0 0 0 .387-1.575Z"/>
                </svg>
            <?php endfor; ?>

            <?php if ($half_star): ?>
                <div class="relative w-4 h-4 ms-1">
                    <!-- Half yellow overlay -->
                    <svg class="absolute top-0 left-0 w-1/2 h-full text-yellow-300" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 22 20" fill="currentColor">
                        <path d="M20.924 7.625a1.523 1.523 0 0 0-1.238-1.044l-5.051-.734-2.259-4.577a1.534 1.534 0 0 0-2.752 0L7.365 5.847l-5.051.734A1.535 1.535 0 0 0 1.463 9.2l3.656 3.563-.863 5.031a1.532 1.532 0 0 0 2.226 1.616L11 17.033l4.518 2.375a1.534 1.534 0 0 0 2.226-1.617l-.863-5.03L20.537 9.2a1.523 1.523 0 0 0 .387-1.575Z"/>
                    </svg>
                    <!-- Gray background star -->
                    <svg class="w-full h-full text-gray-300 dark:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 22 20" fill="currentColor">
                        <path d="M20.924 7.625a1.523 1.523 0 0 0-1.238-1.044l-5.051-.734-2.259-4.577a1.534 1.534 0 0 0-2.752 0L7.365 5.847l-5.051.734A1.535 1.535 0 0 0 1.463 9.2l3.656 3.563-.863 5.031a1.532 1.532 0 0 0 2.226 1.616L11 17.033l4.518 2.375a1.534 1.534 0 0 0 2.226-1.617l-.863-5.03L20.537 9.2a1.523 1.523 0 0 0 .387-1.575Z"/>
                    </svg>
                </div>
            <?php endif; ?>

            <?php
            // Empty stars
            for ($i = 0; $i < $empty_stars; $i++): ?>
                <svg class="w-4 h-4 ms-1 text-gray-300 dark:text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 22 20" aria-hidden="true">
                    <path d="M20.924 7.625a1.523 1.523 0 0 0-1.238-1.044l-5.051-.734-2.259-4.577a1.534 1.534 0 0 0-2.752 0L7.365 5.847l-5.051.734A1.535 1.535 0 0 0 1.463 9.2l3.656 3.563-.863 5.031a1.532 1.532 0 0 0 2.226 1.616L11 17.033l4.518 2.375a1.534 1.534 0 0 0 2.226-1.617l-.863-5.03L20.537 9.2a1.523 1.523 0 0 0 .387-1.575Z"/>
                </svg>
            <?php endfor; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function formatPrice($price): string
    {
        /**
         * Format price to show 2 decimal places unless it's a whole number
         *
         * @param float|string $price The price to format
         * @return string Formatted price (e.g., "19.99" or "20")
        */
        $price = floatval($price);

        // If price is 0 or negative, return "0"
        if ($price <= 0) {
            return '0';
        }

        // If price is a whole number, don't show decimals
        if ($price == floor($price)) {
            return number_format($price, 0);
        }

        // Otherwise, show 2 decimal places
        return number_format($price, 2);
    }
}
