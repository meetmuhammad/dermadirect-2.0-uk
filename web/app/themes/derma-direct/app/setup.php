<?php

/**
 * Theme setup.
*/

namespace App;

use Illuminate\Support\Facades\Vite;

/**
 * Inject styles into the block editor.
 *
 * @return array
*/
add_filter('block_editor_settings_all', function ($settings) {
    $style = Vite::asset('resources/css/editor.css');

    $settings['styles'][] = [
        'css' => "@import url('{$style}')",
    ];

    return $settings;
});

/**
 * Inject scripts into the block editor.
 *
 * @return void
*/
add_filter('admin_head', function () {
    if (! get_current_screen()?->is_block_editor()) {
        return;
    }

    $dependencies = json_decode(Vite::content('editor.deps.json'));

    foreach ($dependencies as $dependency) {
        if (! wp_script_is($dependency)) {
            wp_enqueue_script($dependency);
        }
    }

    echo Vite::withEntryPoints([
        'resources/js/editor.js',
    ])->toHtml();
});

/**
 * Use the generated theme.json file.
 *
 * @return string
*/
add_filter('theme_file_path', function ($path, $file) {
    return $file === 'theme.json'
        ? public_path('build/assets/theme.json')
        : $path;
}, 10, 2);

/**
 * Register the initial theme setup.
 *
 * @return void
*/
add_action('after_setup_theme', function () {
    /**
     * Disable full-site editing support.
     *
     * @link https://wptavern.com/gutenberg-10-5-embeds-pdfs-adds-verse-block-color-options-and-introduces-new-patterns
    */
    remove_theme_support('block-templates');

    /**
     * Register the navigation menus.
     *
     * @link https://developer.wordpress.org/reference/functions/register_nav_menus/
    */
    register_nav_menus([
        'primary_navigation' => __('Primary Navigation', 'sage'),
        'all_categories' => __('All Categories Mega Menu', 'sage'),
        'brands' => __('Brands Mega Menu', 'sage'),
    ]);

    /**
     * Disable the default block patterns.
     *
     * @link https://developer.wordpress.org/block-editor/developers/themes/theme-support/#disabling-the-default-block-patterns
    */
    remove_theme_support('core-block-patterns');

    /**
     * Enable plugins to manage the document title.
     *
     * @link https://developer.wordpress.org/reference/functions/add_theme_support/#title-tag
    */
    add_theme_support('title-tag');

    /**
     * Enable post thumbnail support.
     *
     * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
    */
    add_theme_support('post-thumbnails');

    /**
     * Enable responsive embed support.
     *
     * @link https://developer.wordpress.org/block-editor/how-to-guides/themes/theme-support/#responsive-embedded-content
    */
    add_theme_support('responsive-embeds');

    /**
     * Enable HTML5 markup support.
     *
     * @link https://developer.wordpress.org/reference/functions/add_theme_support/#html5
    */
    add_theme_support('html5', [
        'caption',
        'comment-form',
        'comment-list',
        'gallery',
        'search-form',
        'script',
        'style',
    ]);

    /**
     * Enable selective refresh for widgets in customizer.
     *
     * @link https://developer.wordpress.org/reference/functions/add_theme_support/#customize-selective-refresh-widgets
    */
    add_theme_support('customize-selective-refresh-widgets');
}, 20);

/**
 * Register the theme sidebars.
 *
 * @return void
*/
add_action('widgets_init', function () {
    $config = [
        'before_widget' => '<section class="widget %1$s %2$s">',
        'after_widget' => '</section>',
        'before_title' => '<h3>',
        'after_title' => '</h3>',
    ];

    register_sidebar([
        'name' => __('Primary', 'sage'),
        'id' => 'sidebar-primary',
    ] + $config);

    register_sidebar([
        'name' => __('Footer', 'sage'),
        'id' => 'sidebar-footer',
    ] + $config);
});

/**
 * ==============================================================================
 * Woocommerce theme support.
 * ==============================================================================
*/
add_action('after_setup_theme', function() {
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    add_theme_support('woocommerce');
});

/**
 * ==============================================================================
 * Preloading fonts.
 * ==============================================================================
*/
add_action('wp_head', function () {
    $font_dir = get_theme_file_path('resources/fonts');
    $fonts = glob($font_dir . '/*.{woff2,woff,ttf,otf,eot}', GLOB_BRACE);

    if ($fonts) {
        foreach ($fonts as $index => $font_file) {
            $relative = str_replace($font_dir, '', $font_file);
            $font_uri = get_theme_file_uri('resources/fonts' . $relative);

            $ext = strtolower(pathinfo($font_file, PATHINFO_EXTENSION));

            // Detect correct MIME type for preload
            $mime_types = [
                'woff2' => 'font/woff2',
                'woff'  => 'font/woff',
                'ttf'   => 'font/ttf',
                'otf'   => 'font/otf',
                'eot'   => 'application/vnd.ms-fontobject',
                'svg'   => 'image/svg+xml',
            ];
            $type = $mime_types[$ext] ?? 'font/' . $ext;

            echo $index !== 0 ? "\t" : '';
            echo '<link rel="preload" href="' . esc_url($font_uri) . '" as="font" type="' . esc_attr($type) . '" crossorigin="anonymous">';
            echo "\n";
        }
    }
}, 1);

/**
 * ==============================================================================
 * Asset enqueuing.
 * ==============================================================================
*/
add_action('wp_enqueue_scripts', function() {
    // For lightbox popup.
    $lightbox_script_uri = \Vite::asset('resources/js/lightbox.js');
    wp_enqueue_script('lightbox-popup-js', $lightbox_script_uri, [], null, true);

    // Swiper slider js and css files.
    wp_enqueue_style(
        'swiper-css',
        'https://cdnjs.cloudflare.com/ajax/libs/Swiper/11.0.5/swiper-bundle.min.css',
        [],
        '11.0.5'
    );

    wp_enqueue_script(
        'swiper-js',
        'https://cdnjs.cloudflare.com/ajax/libs/Swiper/11.0.5/swiper-bundle.min.js',
        [],
        '11.0.5',
        true
    );
});

/**
 * ==============================================================================
 * Wishlist - enqueue script + localize data for every front-end page.
 * ==============================================================================
*/
add_action('wp_enqueue_scripts', function () {
    $script_uri = \Vite::asset('resources/js/wishlist.js');
    wp_enqueue_script('dd-wishlist-js', $script_uri, [], null, true);

    wp_localize_script('dd-wishlist-js', 'ddWishlist', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('dd_wishlist_nonce'),
        'items'    => \App\Services\WishlistService::getItems(),
    ]);
});

/**
 * ===============================================================================================
 * Requiring ajax callback files.
 * ===============================================================================================
*/
$ajax_files = glob(get_theme_file_path('/app/ajax-callbacks') . '/*.php');

foreach ($ajax_files as $file) {
    require_once $file;
}

/**
 * ===============================================================================================
 * Initialize Dermadirect Tools.
 * ===============================================================================================
*/
// Initializing Dermadirect Tools menu item.
require_once __DIR__ . '/DermadirectToolsMenu/DermadirectToolsMenu.php';
new \App\DermadirectToolsMenu\DermadirectToolsMenu();

// Autoloader for Dermadirect tool files.
add_action( 'after_setup_theme', function () {
    foreach ( glob( __DIR__ . '/DermadirectToolsMenu/*', GLOB_ONLYDIR ) as $dir ) {

        $file = $dir . '/AdminPage.php';
        if ( !file_exists( $file ) ) continue;

        require_once $file;

        $namespace  = basename( $dir );
        $class_name = "\\App\\DermadirectToolsMenu\\{$namespace}\\AdminPage";
        if ( !class_exists( $class_name ) || !method_exists( $class_name, 'init' ) ) continue;

        $class_name::init();
    }
});

/**
 * =============================================================================================
 * CONDITIONAL CSS FOR ACCOUNT / CHECKOUT / CART PAGES
 * =============================================================================================
*/
add_action('wp_enqueue_scripts', function () {
    // Account Page CSS
    if (is_account_page()) {
        $style_uri = \Vite::asset('resources/css/account-page.css');
        wp_enqueue_style('account-page-css', $style_uri, [], null );
    }

    // Blog Page CSS
    if (is_home()) {
        $style_uri = \Vite::asset('resources/css/blog-page.css');
        wp_enqueue_style('blog-page-css', $style_uri, [], null);
    }

    // Checkout Page CSS
    if (is_checkout()) {
        $style_uri = \Vite::asset('resources/css/checkout-page.css');
        wp_enqueue_style('checkout-page-css', $style_uri, [], null );

        wp_enqueue_script(
            'checkout-page-js',
            \Vite::asset( 'resources/js/checkout-page.js' ),
            [ 'jquery' ],
            null,
            true
        );
    }

    // Cart Page Assets
    if ( is_cart() ) {

        wp_enqueue_style(
            'cart-page-css',
            \Vite::asset( 'resources/css/cart-page.css' ),
            [],
            null
        );

        wp_enqueue_script(
            'cart-page-js',
            \Vite::asset( 'resources/js/cart-page.js' ),
            [ 'jquery' ],
            null,
            true
        );

        wp_localize_script(
            'cart-page-js',
            'ddceParams',
            [
                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'ddce_cart_nonce' ),
                'cutoff_hour'     => \ddce_get_cutoff_time()['hour'],
                'cutoff_minute'   => \ddce_get_cutoff_time()['minute'],
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'free_threshold'  => \ddce_get_free_delivery_threshold(),
            ]
        );
    }

    if (is_account_page()) {

        wp_enqueue_script(
            'account-dashboard',
            \Vite::asset( 'resources/js/account-dashboard.js' ),
            [],
            null,
            true
        );

        wp_localize_script('account-dashboard', 'AccountDashboardConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('favourite_products_nonce'),
        ]);
    }
});

/**
 * =============================================================================================
 * Add tracking code snippets (scripts) in head and footer.
 * =============================================================================================
 * Get the code snippets from Theme Options ACF fields and load them in head or footer section.
*/
add_action('wp_head', function () {
    $header_scripts = (string) (get_field('header_scripts', 'option') ?? '');
    if (!empty($header_scripts)) {
        echo $header_scripts;
    }
}, 99);

add_action('wp_footer', function () {
    $footer_scripts = (string) (get_field('footer_scripts', 'option') ?? '');
    if (!empty($footer_scripts)) {
        echo $footer_scripts;
    }
}, 99);

/**
 * =============================================================================================
 * Require all shortcode files.
 * =============================================================================================
*/
$shortcode_files = glob(get_theme_file_path('/app/Shortcodes') . '/*.php');

foreach ($shortcode_files as $file) {
    require_once $file;
}

add_action('pre_get_posts', function ($query) {
    if (!is_admin() && $query->is_main_query() && $query->is_home()) {
        $query->set('posts_per_page', 4);
    }
});

add_action('pre_get_posts', function ($query) {
    if (!is_admin() && $query->is_main_query()) {
        if ($query->is_home() || $query->is_category()) {
            $query->set('posts_per_page', 4);
        }
    }
});

//Redirect to brand page when click on search if search term is brand
add_action( 'template_redirect', function () {
    if ( ! is_tax( 'product_tag' ) ) {
        return;
    }

    $tag_term = get_queried_object();
    if ( ! $tag_term instanceof WP_Term ) {
        return;
    }

    $brand_term = get_term_by( 'slug', $tag_term->slug, 'product_brand' );
    if ( ! $brand_term ) {
        $brand_term = get_term_by( 'name', $tag_term->name, 'product_brand' );
    }

    if ( ! $brand_term ) {
        return;
    }

    $brand_link = get_term_link( $brand_term );
    if ( is_wp_error( $brand_link ) ) {
        return;
    }

    wp_safe_redirect( $brand_link, 301 );
    exit;
} );

require_once get_theme_file_path(
    'app/Inc/Cart/main-include.php'
);

/**
 * =============================================================================================
 * Reposition Stripe Express Checkout buttons (Apple Pay / Google Pay) near payment section.
 * =============================================================================================
*/
add_action('wp', function () {
    if (!is_checkout()) {
        return;
    }

    if (!class_exists('WC_Stripe') || !method_exists('WC_Stripe', 'get_instance')) {
        return;
    }

    $stripe = \WC_Stripe::get_instance();
    $element = $stripe->express_checkout_configuration ?? null;

    if (!$element) {
        return;
    }

    remove_action('woocommerce_checkout_before_customer_details', [$element, 'display_express_checkout_button_html'], 1);
    add_action('woocommerce_review_order_before_payment', [$element, 'display_express_checkout_button_html'], 5);
}, 20);

/*
 * =============================================================================================
 * Company name & VAT number fields on checkout (billing + shipping).
 *
 * Company name reuses WooCommerce's native billing_company / shipping_company fields, so it
 * already appears in admin order edit, order emails, and My Account order view for free.
 * VAT number has no native equivalent, so it's added as a custom field and displayed manually.
 * =============================================================================================
*/
add_filter('woocommerce_billing_fields', __NAMESPACE__ . '\\dd_add_billing_company_vat_fields', 20);
function dd_add_billing_company_vat_fields($fields) {
    // WooCommerce's "Company name" field visibility setting (woocommerce_checkout_company_field,
    // defaults to hidden) strips billing_company out of $fields before this filter runs, so it's
    // redefined unconditionally here rather than only patched via isset().
    $fields['billing_company'] = array_merge($fields['billing_company'] ?? [], [
        'label'    => __('Company name', 'woocommerce'),
        'class'    => ['form-row-wide'],
        'required' => false,
        'priority' => 25,
    ]);


    $fields['billing_vat_number'] = [
        'label'    => __('VAT number', 'derma-direct'),
        'type'     => 'text',
        'required' => false,
        'class'    => ['form-row-wide'],
        'priority' => 26,
    ];

    return $fields;
}
/**
 * =============================================================================================
 * Match the Stripe Payment Element (card/iDEAL/etc fields) with the checkout design.
 * =============================================================================================
 * Stripe's fields render inside a cross-origin iframe hosted on Stripe's servers, so theme CSS
 * can never reach them. They must be styled via Stripe's Elements Appearance API instead.
 * @see https://woocommerce.com/document/stripe/customization/style-payment-form/
 * @see https://docs.stripe.com/elements/appearance-api
 *
 * NOTE: WooCommerce Stripe caches the generated appearance in transients. After changing this,
 * clear it once so the new styles take effect:
 *   wp transient delete wc_stripe_appearance
 *   wp transient delete wc_stripe_blocks_appearance
*/
add_filter('wc_stripe_upe_params', function ($stripe_params) {
    $appearance = (object) [
        'variables' => (object) [
            'colorPrimary'       => '#000000',
            'colorBackground'    => '#ffffff',
            'colorText'          => '#171717',
            'colorTextSecondary' => '#5a5555',
            'colorDanger'        => '#c0392b',
            'fontFamily'         => '"Quicksand", sans-serif',
            'fontSizeBase'       => '15px',
            'borderRadius'       => '8px',
            'spacingUnit'        => '4px',
        ],
        'rules' => (object) [
            '.Input' => (object) [
                'border'    => '1px solid #171717',
                'boxShadow' => 'none',
                'padding'   => '14px 16px',
            ],
            '.Input:focus' => (object) [
                'border'    => '1px solid #000000',
                'boxShadow' => '0 0 0 3px rgba(0, 0, 0, 0.08)',
            ],
            '.Input--invalid' => (object) [
                'border' => '1px solid #c0392b',
            ],
            '.Label' => (object) [
                'fontFamily' => '"Poppins", sans-serif',
                'fontWeight' => '500',
                'color'      => '#171717',
            ],
            '.Tab' => (object) [
                'border'       => '1px solid #ececec',
                'borderRadius' => '8px',
                'boxShadow'    => 'none',
            ],
            '.Tab:hover' => (object) [
                'border' => '1px solid #171717',
            ],
            '.Tab--selected' => (object) [
                'border'    => '1px solid #000000',
                'boxShadow' => '0 0 0 1px #000000',
            ],
            '.TabLabel--selected' => (object) [
                'color' => '#000000',
            ],
        ],
    ];

    // Shortcode (classic) checkout.
    $stripe_params['appearance'] = $appearance;

    // Block checkout.
    $stripe_params['blocksAppearance'] = $appearance;

    return $stripe_params;
}, 20);

add_filter('woocommerce_shipping_fields', __NAMESPACE__ . '\\dd_add_shipping_company_vat_fields', 20);
function dd_add_shipping_company_vat_fields($fields) {
    // Same as billing_company above — redefined unconditionally since WooCommerce may have
    // already stripped shipping_company out based on the company field visibility setting.
    $fields['shipping_company'] = array_merge($fields['shipping_company'] ?? [], [
        'label'    => __('Company name', 'woocommerce'),
        'class'    => ['form-row-wide'],
        'required' => false,
        'priority' => 25,
    ]);

    $fields['shipping_vat_number'] = [
        'label'    => __('VAT number', 'derma-direct'),
        'type'     => 'text',
        'required' => false,
        'class'    => ['form-row-wide'],
        'priority' => 26,
    ];

    return $fields;
}

add_action('woocommerce_checkout_update_order_meta', __NAMESPACE__ . '\\dd_save_vat_number_fields');
function dd_save_vat_number_fields($order_id) {
    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    if (!empty($_POST['billing_vat_number'])) {
        $order->update_meta_data('_billing_vat_number', sanitize_text_field(wp_unslash($_POST['billing_vat_number'])));
    }

    if (!empty($_POST['shipping_vat_number'])) {
        $order->update_meta_data('_shipping_vat_number', sanitize_text_field(wp_unslash($_POST['shipping_vat_number'])));
    }

    $order->save();
}

// Show VAT number on the admin order edit screen (Billing/Shipping address panels).
add_action('woocommerce_admin_order_data_after_billing_address', __NAMESPACE__ . '\\dd_show_billing_vat_admin');
function dd_show_billing_vat_admin($order) {
    $vat = $order->get_meta('_billing_vat_number');

    if ($vat) {
        echo '<p><strong>' . esc_html__('VAT number', 'derma-direct') . ':</strong> ' . esc_html($vat) . '</p>';
    }
}

add_action('woocommerce_admin_order_data_after_shipping_address', __NAMESPACE__ . '\\dd_show_shipping_vat_admin');
function dd_show_shipping_vat_admin($order) {
    $vat = $order->get_meta('_shipping_vat_number');

    if ($vat) {
        echo '<p><strong>' . esc_html__('VAT number', 'derma-direct') . ':</strong> ' . esc_html($vat) . '</p>';
    }
}

// Show VAT number on the customer-facing order details page (thank-you page & My Account > Orders).
add_action('woocommerce_order_details_after_customer_details', __NAMESPACE__ . '\\dd_show_vat_customer_order_details');
function dd_show_vat_customer_order_details($order) {
    $billing_vat = $order->get_meta('_billing_vat_number');
    $shipping_vat = $order->get_meta('_shipping_vat_number');

    if (!$billing_vat && !$shipping_vat) {
        return;
    }

    echo '<section class="dd-order-vat-numbers">';

    if ($billing_vat) {
        echo '<p><strong>' . esc_html__('Billing VAT number', 'derma-direct') . ':</strong> ' . esc_html($billing_vat) . '</p>';
    }

    if ($shipping_vat) {
        echo '<p><strong>' . esc_html__('Shipping VAT number', 'derma-direct') . ':</strong> ' . esc_html($shipping_vat) . '</p>';
    }

    echo '</section>';
}

// Show VAT number in order confirmation / admin notification emails.
add_action('woocommerce_email_order_meta', __NAMESPACE__ . '\\dd_show_vat_in_email', 10, 3);
function dd_show_vat_in_email($order, $sent_to_admin, $plain_text) {
    $billing_vat = $order->get_meta('_billing_vat_number');
    $shipping_vat = $order->get_meta('_shipping_vat_number');

    if (!$billing_vat && !$shipping_vat) {
        return;
    }

    if ($plain_text) {
        if ($billing_vat) {
            echo esc_html__('Billing VAT number', 'derma-direct') . ': ' . esc_html($billing_vat) . "\n";
        }

        if ($shipping_vat) {
            echo esc_html__('Shipping VAT number', 'derma-direct') . ': ' . esc_html($shipping_vat) . "\n";
        }

        return;
    }

    echo '<section class="dd-order-vat-numbers">';

    if ($billing_vat) {
        echo '<p><strong>' . esc_html__('Billing VAT number', 'derma-direct') . ':</strong> ' . esc_html($billing_vat) . '</p>';
    }

    if ($shipping_vat) {
        echo '<p><strong>' . esc_html__('Shipping VAT number', 'derma-direct') . ':</strong> ' . esc_html($shipping_vat) . '</p>';
    }

    echo '</section>';
}
