<?php

use Roots\Acorn\Application;

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our theme. We will simply require it into the script here so that we
| don't have to worry about manually loading any of our classes later on.
|
*/

if (! file_exists($composer = __DIR__.'/vendor/autoload.php')) {
    wp_die(__('Error locating autoloader. Please run <code>composer install</code>.', 'sage'));
}

require $composer;

/*
|--------------------------------------------------------------------------
| Register The Bootloader
|--------------------------------------------------------------------------
|
| The first thing we will do is schedule a new Acorn application container
| to boot when WordPress is finished loading the theme. The application
| serves as the "glue" for all the components of Laravel and is
| the IoC container for the system binding all of the various parts.
|
*/

Application::configure()
    ->withProviders([
        App\Providers\ThemeServiceProvider::class,
    ])
    ->boot();

/*
|--------------------------------------------------------------------------
| Register Sage Theme Files
|--------------------------------------------------------------------------
|
| Out of the box, Sage ships with categorically named theme files
| containing common functionality and setup to be bootstrapped with your
| theme. Simply add (or remove) files from the array below to change what
| is registered alongside Sage.
|
*/

collect(['setup', 'filters'])
    ->each(function ($file) {
        if (! locate_template($file = "app/{$file}.php", true, true)) {
            wp_die(
                /* translators: %s is replaced with the relative file path */
                sprintf(__('Error locating <code>%s</code> for inclusion.', 'sage'), $file)
            );
        }
    });


add_action('init', function(){

    if (!get_option('best_selling_cron_reset_done')) {

        wp_clear_scheduled_hook(
            'update_best_selling_products'
        );

        update_option(
            'best_selling_cron_reset_done',
            true
        );

    }

});

if (! function_exists('get_pagination')) {
function get_pagination($query) {
    $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
    $total = $query->max_num_pages;

    if ($total <= 1) return '';

    if (is_category()) {
        $base_url = get_category_link(get_queried_object_id());
    } else {
        $blog_page = get_page_by_path('blog');
        $base_url  = get_permalink($blog_page->ID);
    }

    $links = paginate_links([
        'base'      => trailingslashit($base_url) . '%_%',
        'format'    => 'page/%#%/',
        'current'   => $paged,
        'total'     => $total,
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
        'type'      => 'array',
    ]);

    if (!$links) return '';

    $html = '';
    foreach ($links as $link) {
        $is_current = strpos($link, 'current') !== false;
        $base       = 'flex items-center justify-center w-10 h-10 rounded-md font-semibold transition';
        $state      = $is_current
                        ? 'bg-black text-white pointer-events-none'
                        : 'bg-white text-black hover:bg-black hover:text-white';

        $link = preg_replace('/class="[^"]*"/', 'class="' . $base . ' ' . $state . '"', $link);
        $html .= '<div>' . $link . '</div>';
    }

    return $html;
}
}

//Cron Job for Best_selling _count_sorting
add_filter('cron_schedules', function($schedules){
    $schedules['every_fifteen_minutes'] = [
        'interval' => 900,
        'display'  => 'Every 15 Minutes'
    ];
    return $schedules;
});

add_action('init', function(){
    if (!wp_next_scheduled('update_best_selling_products')) {
        wp_schedule_event(
            time(),
            'every_fifteen_minutes',
            'update_best_selling_products'
        );
    }
});


add_action('update_best_selling_products', function(){
    $date = date(
        'Y-m-d',
        strtotime('-30 days')
    );

    $orders = wc_get_orders([
        'limit'        => -1,
        'status'       => [
            'processing',
            'completed'
        ],
        'date_created' => '>=' . $date,
    ]);

    $products_count = [];
    foreach($orders as $order){
        foreach($order->get_items() as $item){
            $product_id = $item->get_product_id();
            if(!$product_id){
                continue;
            }
            $qty = $item->get_quantity();
            if(!isset($products_count[$product_id])){
                $products_count[$product_id] = 0;
            }
            $products_count[$product_id] += $qty;
        }
    }

    // Reset all products first
    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ]);

    foreach($products as $product_id){
        update_field(
            'best_selling_count',
            $products_count[$product_id] ?? 0,
            $product_id
        );
    }
});

//Run this function for initial cron job
add_action('init', function(){
    if (!get_option('best_selling_initial_run')) {
        do_action('update_best_selling_products');
        update_option(
            'best_selling_initial_run',
            true
        );
    }
});


// add_action('template_redirect', function () {
//     if (is_user_logged_in()) {
//         return;
//     }
//     if (is_admin()) {
//         return;
//     }
//     if (
//         strpos($_SERVER['REQUEST_URI'], '/wp/wp-admin') !== false ||
//         strpos($_SERVER['REQUEST_URI'], '/wp/wp-login.php') !== false ||
//         strpos($_SERVER['REQUEST_URI'], '/wp-json') !== false ||
//         strpos($_SERVER['REQUEST_URI'], 'wp-cron.php') !== false
//     ) {
//         return;
//     }
//     if (is_page('coming-soon')) {
//         return;
//     }
//     wp_safe_redirect(home_url('/coming-soon'));
//     exit;
// });
//

/**
 * Cap max purchase quantity at 50 (or actual stock if lower) to prevent stock leak.
 */
add_filter('woocommerce_quantity_input_max', function ($max_value, $product) {
    $stock_qty = $product->get_stock_quantity();
    return ($stock_qty !== null && $stock_qty > 0) ? min(50, $stock_qty) : 50;
}, 10, 2);
