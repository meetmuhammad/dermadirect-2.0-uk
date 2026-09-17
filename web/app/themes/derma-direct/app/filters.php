<?php

/**
 * Theme filters.
 */

namespace App;

/**
 * Add "… Continued" to the excerpt.
 *
 * @return string
 */
\add_filter('excerpt_more', function () {
    return sprintf(' &hellip; <a href="%s">%s</a>', get_permalink(), __('Continued', 'sage'));
});

/**
 * ===============================================================================================
 * This filter is used to remove the title from pages.
 * ===============================================================================================
*/
\add_filter('the_title', function ($title, $id = null) {
    if (\is_page() && \in_the_loop() && !\is_admin()) {
        return ''; // Return empty title for pages
    }
    return $title;
}, 10, 2);

/**
 * ===============================================================================================
 * Adding support for SVG uploads.
 * ===============================================================================================
*/
\add_filter('upload_mimes', function ($mimes) {
    $mimes['svg']  = 'image/svg+xml';
    return $mimes;
});

/**
 * ===============================================================================================
 * This filter adds images to the menu items.
 * ===============================================================================================
*/
\add_filter('wp_setup_nav_menu_item', function($menu_item) {
    $menu_item->icon = get_field('icon', $menu_item->ID) ?? '';
    return $menu_item;
});

/**
 * ===============================================================================================
 * Filter to render icons in mega menu items.
 * ===============================================================================================
*/
\add_filter('wp_nav_menu_objects', function( $items, $args ) {
    // Only on primary menu
    if ( $args->theme_location !== 'all_categories' ) return $items;

    foreach( $items as &$item ) {

        // ACF icon field on the menu item
        $icon = get_field('icon', $item->ID);
        if ( ! $icon ) continue;

        $icon_url = wp_get_attachment_image_url($icon, 'thumbnail');

        if ( $icon_url ) {
            $image = '<img class="menu-icon" src="' . esc_url($icon_url) . '" alt=""> ';
            $item->title = $image . '<span>' . esc_html( $item->title ) . '</span>';
        }
    }

    return $items;
}, 10, 2);

/**
 * ===============================================================================================
 * Removing "View cart" link added after AJAX add to cart.
 * ===============================================================================================
*/
\add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    foreach ($fragments as $key => $fragment) {
        // Fragments are NOT all markup strings. Payment Plugins Stripe 4.x adds
        // $fragments['wc_stripe_data'] as an array of cart data, and passing that to strpos() is a
        // fatal TypeError on PHP 8 - which took out every AJAX add-to-cart on the shop and archive
        // pages with a 500.
        //
        // Neither source site hit this: EU keeps Payment Plugins inactive, and legacy UK ran 3.3.103,
        // which did not add the fragment. The new UK site is the first place the theme filter and
        // Payment Plugins 4.x run together.
        if (!is_string($fragment)) {
            continue;
        }

        // Remove the added_to_cart link from the fragment
        if (strpos($fragment, 'added_to_cart wc-forward') !== false) {
            $fragments[$key] = preg_replace('/<a.*?added_to_cart wc-forward.*?<\/a>/', '', $fragment);
        }
    }

    return $fragments;
});

/**
 * ===============================================================================================
 * Adding script type module so import/export of functions could work.
 * ===============================================================================================
*/
\add_filter('script_loader_tag', function ( $tag, $handle, $src ) {
    $script_handlers = [
        'woocommerce-common-js',
        'product-archive-page-js',
        'product-single-page-js',
    ];

    if ( in_array($handle, $script_handlers) ) {
        return '<script type="module" src="' . esc_url( $src ) . '"></script>';
    }
    return $tag;
}, 10, 3);

/**
 * ===============================================================================================
 * Filter to dynamically populate Select field choices from repeater field in Theme Settings.
 * ===============================================================================================
 * To make the treatment areas name and icons consistent acorss all the products, a repeater field
 * is made in the Theme Settings page. The user will fill our the area name and add icon to it.
 * This repeater field will be dunamically used as Select field options in the product single page.
 * This filter populates the select field options.
*/
\add_filter('acf/load_field/name=treatment_areas', function($field) {
    // Get repeater from Theme Options (use option page key here)
    $list = get_field('treatment_areas_list', 'option');

    // Reset choices
    $field['choices'] = [];

    if ($list && is_array($list)) {
        foreach ($list as $row) {
            $field['choices'][$row['area_name']] = $row['area_name'];
        }
    }
    return $field;
});

\add_filter('acf/load_field/name=ingredients', function($field) {
    // Get repeater from Theme Options (use option page key here)
    $list = get_field('product_ingredients_list', 'option');

    // Reset choices
    $field['choices'] = [];

    if ($list && is_array($list)) {
        foreach ($list as $row) {
            $field['choices'][$row['ingredient']] = $row['ingredient'];
        }
    }
    return $field;
});

\add_filter('acf/load_field/name=product_gauge', function($field) {
    // Get repeater from Theme Options (use option page key here)
    $list = get_field('product_gauge_list', 'option');

    // Reset choices
    $field['choices'] = [];

    if ($list && is_array($list)) {
        foreach ($list as $row) {
            $field['choices'][$row['gauge']] = $row['gauge'];
        }
    }
    return $field;
});

\add_filter('acf/load_field/name=product_length', function($field) {
    // Get repeater from Theme Options (use option page key here)
    $list = get_field('product_length_list', 'option');

    // Reset choices
    $field['choices'] = [];

    if ($list && is_array($list)) {
        foreach ($list as $row) {
            $field['choices'][$row['length']] = $row['length'];
        }
    }
    return $field;
});

\add_filter('acf/load_field/name=product_type', function($field) {
    // Get repeater from Theme Options (use option page key here)
    $list = get_field('product_type_list', 'option');

    // Reset choices
    $field['choices'] = [];

    if ($list && is_array($list)) {
        foreach ($list as $row) {
            $field['choices'][$row['type']] = $row['type'];
        }
    }
    return $field;
});

\add_filter('acf/load_field/name=product_protocols', function($field) {
    // Get repeater from Theme Options (use option page key here)
    $list = get_field('product_protocols_list', 'option');

    // Reset choices
    $field['choices'] = [];

    if ($list && is_array($list)) {
        foreach ($list as $row) {
            $field['choices'][$row['protocol']] = $row['protocol'];
        }
    }
    return $field;
});


/**
 * ===============================================================================================
 * Updating cart items quantity badge icon and mini-cart UI inside header.
 * ===============================================================================================
 */
add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    ob_start();
    ?>
    <span id="woo_cart_count"
        class="notification-badge cart-count bg-primary rounded-[50px] py-[3px] px-[5px] text-white text-[9px] flex items-center justify-center absolute -top-1 -right-2.5"
        aria-hidden="true">
        <?php echo WC()->cart->get_cart_contents_count(); ?>
    </span>
    <?php
    $fragments['#woo_cart_count'] = ob_get_clean();

    return $fragments;
});

/**
 * ===============================================================================================
 * Updating cart items inside minicart.
 * ===============================================================================================
*/
use App\View\Composers\MiniCart;

\add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    ob_start();
    echo view('woocommerce.mini-cart-content', MiniCart::buildData())->render();

    // Key must be a real CSS selector so WooCommerce core's own updateFragments()
    // ($(key).replaceWith(value) in add-to-cart.js) can actually apply it — it used to be
    // 'updated_minicart_content', which isn't a selector, so WC core silently no-op'd on it.
    // The value must therefore be a *complete* replacement for that element (including the
    // wrapping #minicart_content div itself, mirrored here from mini-cart.blade.php), otherwise
    // replaceWith() would strip the id off the page and break every later fragment update.
    $fragments['#minicart_content'] = '<div id="minicart_content" class="flex flex-col h-full">' . ob_get_clean() . '</div>';

    return $fragments;
});

/**
 * ===============================================================================================
 * Fix broken "Add to cart" hrefs when a product template renders inside an admin-ajax.php POST
 * request (e.g. load_more_products / get_quick_view_product). WC's add_to_cart_url() builds the
 * URL via add_query_arg('add-to-cart', $id) with no explicit base, which defaults to
 * $_SERVER['REQUEST_URI'] — i.e. admin-ajax.php itself during AJAX. Rebuild it against the page
 * the request actually came from instead.
 * ===============================================================================================
*/
\add_filter('woocommerce_product_add_to_cart_url', function ($url, $product) {
    if (! wp_doing_ajax() || ! $product->is_purchasable()) {
        return $url;
    }

    // No Referer header (privacy browsers, Referrer-Policy: no-referrer, some ad-blockers) —
    // fall back to the product's own permalink rather than the original $url, which would
    // still be the broken admin-ajax.php-based URL.
    $referer = wp_get_referer();
    $base    = $referer ?: get_permalink($product->get_id());

    return add_query_arg('add-to-cart', $product->get_id(), $base);
}, 10, 2);

/**
 * ===============================================================================================
 * Adding class in body tag for logged-in and non-logged-in user.
 * ===============================================================================================
 * It is done to apply custom styling based on logged in state.
*/
add_filter( 'body_class', function ( $classes ) {
    $classes[] = (is_user_logged_in() ?? false) ? 'logged-in-user' : 'no-logged-in-user';
    return $classes;
});

/**
 * ===============================================================================================
 * Intercept the product category base URL (e.g. /product-category/) which is otherwise a 404.
 * Serve the product-categories template showing all top-level categories.
 * ===============================================================================================
*/
\add_filter('template_include', function ($template) {
    $permalinks = (array) get_option('woocommerce_permalinks', []);
    $cat_base   = trim($permalinks['category_base'] ?? 'product-category', '/');
    $path       = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

    if ($path === $cat_base) {
        \status_header(200);
        $app = \Roots\app();
        $app['sage.view'] = 'product-categories';
        $app['sage.data'] = [];
        return get_template_directory() . '/index.php';
    }

    return $template;
}, 101);


/**
 * ===============================================================================================
 * Intercept the product brand base URL (e.g. /brand/) which is otherwise a 404.
 * Serve the product-brands template showing all brands.
 * ===============================================================================================
*/
\add_filter('template_include', function ($template) {
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

    if ($path === 'brand') {
        \status_header(200);
        $app = \Roots\app();
        $app['sage.view'] = 'product-brands';
        $app['sage.data'] = [];
        return get_template_directory() . '/index.php';
    }

    return $template;
}, 101);

add_filter('woocommerce_product_add_to_cart_text', function ($text, $product) {
    $product_id = $product->get_id();
    // If out of stock or not purchasable
    if (!$product->is_purchasable() || !$product->is_in_stock()) {
        return __('Out of Stock', 'woocommerce');
    }
    return $text;
}, 10, 2);

add_filter( 'woocommerce_cart_product_not_enough_stock_message', __NAMESPACE__ . '\\custom_not_enough_stock_message', 10, 3 );
function custom_not_enough_stock_message( $message, $product_data, $stock_quantity ) {
    $product_name = $product_data->get_name();

    return sprintf(
        __( 'Sorry, You cannot add that amount of "%1$s" to the cart because there is not enough stock.', 'your-textdomain' ),
        $product_name,
        wc_format_stock_quantity_for_display( $stock_quantity, $product_data )
    );
}

add_filter( 'woocommerce_cart_product_not_enough_stock_already_in_cart_message', __NAMESPACE__ . '\\custom_already_in_cart_message', 10, 4 );
function custom_already_in_cart_message( $message, $product_data, $stock_quantity, $stock_quantity_in_cart ) {
    $product_name = $product_data->get_name();

    return sprintf(
        __( 'Stock limit for "%1$s" has been reached, so you can\'t add more into the cart.', 'your-textdomain' ),
        $product_name,
        wc_format_stock_quantity_for_display( $stock_quantity_in_cart, $product_data ),
        wc_format_stock_quantity_for_display( $stock_quantity, $product_data )
    );
}

/**
 * Enforces the 50-per-product cap and real stock availability across every add-to-cart entry
 * point (single product page's native ajax_add_to_cart included), counting quantity already
 * sitting in the cart against the cap — mirrors the rules already applied to the cart page
 * (Inc/Cart/ajax.php) and mini-cart (ajax-callbacks/cart.php), which only run once an item is
 * already in the cart and can't see quantity being newly added.
 */
add_filter('woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\dd_validate_add_to_cart_quantity', 10, 3);
function dd_validate_add_to_cart_quantity($passed, $product_id, $quantity) {
    if (! $passed) {
        return $passed;
    }

    $product = wc_get_product($product_id);
    if (! $product) {
        return $passed;
    }

    // Mirrors product-header.blade.php's min(50, max_purchase_quantity ?: 50) — a merchant-set
    // per-product limit (WooCommerce's "Purchase quantity limit") must still cap below the site
    // wide 50, not be overridden by it.
    $site_cap               = 50;
    $max_purchase_quantity  = $product->get_max_purchase_quantity();
    $hard_cap               = $max_purchase_quantity > 0 ? min($site_cap, $max_purchase_quantity) : $site_cap;
    $already_in_cart        = \ddce_get_cart_quantity_for_product($product_id);
    $remaining              = max(0, $hard_cap - $already_in_cart);

    if ($quantity > $remaining) {
        if ($remaining <= 0) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: max quantity, 2: product name */
                    __('You already have the maximum quantity (%1$d) of "%2$s" in your cart.', 'sage'),
                    $hard_cap,
                    $product->get_name()
                ),
                'error'
            );
        } else {
            wc_add_notice(
                sprintf(
                    /* translators: 1: quantity already in cart, 2: product name, 3: quantity still allowed, 4: max quantity */
                    __('You already have %1$d of "%2$s" in your cart. You can add up to %3$d more (maximum %4$d per product).', 'sage'),
                    $already_in_cart,
                    $product->get_name(),
                    $remaining,
                    $hard_cap
                ),
                'error'
            );
        }
        return false;
    }

    if ($product->managing_stock() && ! $product->backorders_allowed() && ! $product->has_enough_stock($already_in_cart + $quantity)) {
        wc_add_notice(
            sprintf(
                /* translators: %s: product name */
                __('"%s" is not available in the quantity you requested.', 'sage'),
                $product->get_name()
            ),
            'error'
        );
        return false;
    }

    return $passed;
}

/**
 * WooCommerce's ajax_add_to_cart click handler is delegated on document.body and sometimes
 * doesn't intercept the click in time (page not fully interactive yet, etc.), so the browser
 * falls through to the button's raw href and adds the item via a full-page GET
 * (?add-to-cart={id}). WC_Form_Handler::add_to_cart_action() (core, wp_loaded priority 20) adds
 * the item but never strips that query var from the URL unless "redirect to cart" is enabled —
 * and the theme's mini-cart drawer only ever opens in response to the AJAX flow's
 * `added_to_cart` JS event, so this fallback path left customers on a URL with ?add-to-cart=
 * still in it and no visible confirmation the item was added (and a page refresh would silently
 * add it again). On success this redirects back to a clean URL flagged so mini-cart.js can open
 * the drawer itself (see refresh_minicart() in ajax-callbacks/cart.php). On failure (e.g. the
 * 50-per-product cap or real stock) it sends the customer to the product's own page instead,
 * where the error notice — persisted in the session — renders via the wc_print_notices() call
 * in content-single-product.blade.php.
 */
add_action('template_redirect', __NAMESPACE__ . '\\dd_clean_up_fallback_add_to_cart');
function dd_clean_up_fallback_add_to_cart() {
    if (empty($_GET['add-to-cart']) || ! is_numeric($_GET['add-to-cart'])) {
        return;
    }
    $product_id = absint($_GET['add-to-cart']);
    if (wc_notice_count('error') > 0) {
        wp_safe_redirect(get_permalink($product_id));
        exit;
    }
    $redirect_url = add_query_arg('dd_added', $product_id, remove_query_arg('add-to-cart'));
    wp_safe_redirect($redirect_url);
    exit;
}

add_filter( 'wpcf7_autop_or_not', '__return_false' );
add_filter('woocommerce_valid_order_statuses_for_order_again', function ($statuses) {
    $statuses[] = 'delivered';
    $statuses[] = 'processing';
    return $statuses;
});

//This logic combine the score of the product with the best selling count to boost the score of the product in search results.
//Relevance score is calculated based on the keyword match and the best selling count is used to boost the score of the product in search results.
add_filter( 'dgwt/wcas/search_results/product/score', function ( $score, $keyword, $product_id, $post ) {
    $best_selling_count = (int) get_post_meta( $product_id, 'best_selling_count', true );
    $boost = min( $best_selling_count * 0.5, 50 );
    return $score + $boost;
}, 10, 4 );

//add_filter('user_has_cap', function ($allcaps, $caps, $args) {
   // if (isset($allcaps['install_plugins'])) {
        //unset($allcaps['install_plugins']);
    //}
    //return $allcaps;
//}, 10, 3);

// This action is here to fix the incompatibility of the WooCommerce Status Actions plugin with the latest version of WooCommerce. 
// The plugin adds a "Add New" button to the "Custom Statuses" page, but the link is broken. This action fixes the link to point to the correct URL.
add_action('admin_footer', function () {
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    $is_plugin_settings_tab = $screen->id === 'woocommerce_page_wc-settings'
        && isset($_GET['tab']) && sanitize_text_field($_GET['tab']) === 'wc_sa_settings';
    $is_status_post_type_screen = strpos($screen->id, 'wc_custom_statuses') !== false;
    $is_delete_status_page = isset($_GET['page']) && sanitize_text_field($_GET['page']) === 'wc_sa_delete_status';
    if (!$is_plugin_settings_tab && !$is_status_post_type_screen && !$is_delete_status_page) {
        return;
    }

    $correct_admin_base = admin_url();
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var correctBase = <?php echo wp_json_encode($correct_admin_base); ?>;
        document.querySelectorAll('a[href*="/wp-admin/"]').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href || href.indexOf('/wp/wp-admin/') !== -1) {
                return; // already correct, skip
            }
            var match = href.match(/\/wp-admin\/(.+)$/);
            if (match) {
                link.setAttribute('href', correctBase + match[1]);
            }
        });
    });
    </script>
    <?php
});

add_filter('pre_delete_post', function ($delete, $post) {
    if ($post->post_type === 'wc_custom_statuses' && $delete === false) {
        return null;
    }
    return $delete;
}, 20, 2);

add_filter( 'woocommerce_checkout_fields', __NAMESPACE__ . '\\dd_require_company_vat_fields' );

function dd_require_company_vat_fields( $fields ) {
    // Company name -> required
    if ( isset( $fields['billing']['billing_company'] ) ) {
        $fields['billing']['billing_company']['required'] = true;
    }
    // VAT number field -> required + rename label
    $vat_key = 'billing_vat_number'; // e.g. could be 'billing_eu_vat_number'

    if ( isset( $fields['billing'][ $vat_key ] ) ) {
        $fields['billing'][ $vat_key ]['required'] = true;
        $fields['billing'][ $vat_key ]['label']    = __( 'EU VAT number', 'sage' );
    }
    return $fields;
}

/**
 * WooCommerce's own checkout-time stock recheck (check_cart_item_stock)
 * adds a hardcoded notice with the exact available quantity. There's no core filter for that string, so we
 * suppress it entirely and build our own per-product notices instead,
 * naming the product but never the stock number. Priority 20 = runs
 * AFTER WC's own check, which runs at default priority 10 on the same hook.
 */
add_action('woocommerce_check_cart_items', __NAMESPACE__ . '\\dd_mask_checkout_stock_notices', 20);
function dd_mask_checkout_stock_notices() {
    if (! function_exists('wc_get_notices') || ! WC()->cart) {
        return;
    }
    $error_notices = wc_get_notices('error');
    if (empty($error_notices)) {
        return;
    }
    $has_stock_notice = false;
    $kept             = [];
    foreach ($error_notices as $notice) {
        $message = is_array($notice) ? ($notice['notice'] ?? '') : $notice;
        // Matches WC core's "...in stock to fulfill your order (X available)."
        if (stripos($message, 'available)') !== false || stripos($message, 'in stock to fulfill') !== false) {
            $has_stock_notice = true;
        } else {
            $kept[] = $message;
        }
    }
    if (! $has_stock_notice) {
        return;
    }
    wc_clear_notices();
    foreach ($kept as $message) {
        wc_add_notice($message, 'error');
    }
    // Re-check each cart item ourselves so we can name the product
    // without exposing how much stock is actually left.
    $affected_products = [];
    foreach (WC()->cart->get_cart() as $cart_item) {
        $product = $cart_item['data'];
        if (! $product || ! $product->managing_stock() || $product->backorders_allowed()) {
            continue;
        }
        if (! $product->has_enough_stock($cart_item['quantity'])) {
            $affected_products[] = $product->get_name();
        }
    }
    if (! empty($affected_products)) {
        foreach ($affected_products as $name) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: product name */
                    __('"%s" is not available in the quantity you requested. Please reduce the quantity to continue.', 'sage'),
                    $name
                ),
                'error'
            );
        }
    } else {
        // Fallback — couldn't isolate the product, keep it generic
        wc_add_notice(
            __('One or more items in your cart exceed the available quantity. Please reduce the quantity and try again.', 'sage'),
            'error'
        );
    }
}

add_filter('upload_mimes', function ($mimes) {
    $mimes['vtt'] = 'text/vtt';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    if (str_ends_with($filename, '.vtt')) {
        $data['ext'] = 'vtt';
        $data['type'] = 'text/vtt';
    }
    return $data;
}, 10, 4);
/**
 * Checkout: "Ship to a different address?" starts unchecked.
 *
 * Ported from legacy UK code snippet #33 during the UK migration. The theme's checkout template
 * calls `woocommerce_checkout_shipping`, so without this WooCommerce's own default applies and the
 * checkout would behave differently from the store customers know.
 *
 * @see migration/reports/code-snippet-parity.md
 */
add_filter('woocommerce_ship_to_different_address_checked', '__return_false');
