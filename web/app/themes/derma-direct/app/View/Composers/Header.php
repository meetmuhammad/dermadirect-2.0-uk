<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;
use App\Helper\Helper;
use App\Services\WishlistService;

class Header extends Composer
{
    protected static $views = [
        'sections.header',
    ];

    public function override(): array
    {
        $this->enqueueAssets();

        return [
            'top_menu_items' => Helper::getMenuItems('header_top_menu', 'acf_field_name'),
            'top_banner_text' => (string) get_field('top_banner_text', 'option'),
            'need_help_contact' => (array) get_field('need_help_contact', 'option'),

            'website_logo' => (int) get_field('website_logo', 'option'),
            'woocommerce_menu_items' => Helper::getMenuItems('woocommerce_menu', 'acf_field_name'),
            'cart_count' => WC()->cart->get_cart_contents_count(),

            'main_navigation_menu_items' => Helper::getMenuItems('main_navigation_menu', 'acf_field_name'),
            'support_center_number' => (array) get_field('support_center_number', 'option'),
            'training_button_link' => (array) get_field('training_button_link', 'option'),
        ];
    }

    private function enqueueAssets(): void
    {
        $script_uri = \Vite::asset('resources/js/header.js');
        wp_enqueue_script( 'header-section-js', $script_uri, [], null, true );

        /**
         * Getting Megamenu icon from Appearence > Menus > menu item ACF field.
         * Adding that icon url dynamically in the style
         * JS is not used because it runs after page load and it causes a jerk.
        */
        $all_cat_menu = Helper::getMenuItemsByLocation('all_categories') ?? [];
        $icon_image_id = (!empty($all_cat_menu) && is_array($all_cat_menu)) ? $all_cat_menu[0]->icon : '';
        $icon_url =  !empty($icon_image_id ?? '') ? wp_get_attachment_image_url($icon_image_id, 'full') : '';

        $chevron_icon_url = \Vite::asset('resources/images/chevron.svg');

        wp_enqueue_style( 'derma-dynamic-style', get_stylesheet_uri() );

        $custom_css = "
            button.mega-toggle-animated {
                background-image: url('$icon_url') !important;
                background-repeat: no-repeat !important;
                background-size: contain !important;
                background-position: center !important;
            }

            #mega-menu-all_categories > li > a .mega-indicator {
                background-image: url('$chevron_icon_url') !important;
                height: 8px !important;
                width: 8px !important;
                background-repeat: no-repeat !important;
            }
        ";

        wp_add_inline_style( 'derma-dynamic-style', $custom_css );
    }

    public static function getNotificationBadge( Object $menu_item ): array
    {
        /**
         * This method gets the notification badge count for woocommerce menu only.
         * This includes cart and wishlist menu items.
         *
         * Note:
         * In filters.php file, a filter is written which updates the cart count in the header
         * when a product is added into the cart.
        */
        $title = strtolower(trim($menu_item->title));

        // Detect if item is cart or wishlist.
        $is_cart = str_contains($title, 'cart');
        $is_wishlist = str_contains($title, 'wishlist');

        return [
            'class' => $is_cart ? 'cart-count' : ($is_wishlist ? 'wishlist-count' : ''),
            'count' => $is_cart ? WC()->cart->get_cart_contents_count() : ($is_wishlist ? WishlistService::getCount() : ''),
            'id' => $is_cart ? 'woo_cart_count' : ($is_wishlist ? 'woo_wishlist_count' : ''),
        ];
    }
}
