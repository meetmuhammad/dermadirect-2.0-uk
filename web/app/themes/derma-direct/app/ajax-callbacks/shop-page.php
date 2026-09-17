<?php
/**
 * AJAX: Load more WooCommerce products
 */

declare(strict_types=1);

use App\View\Composers\ProductArchive;

add_action('wp_ajax_load_more_products', 'load_more_products');
add_action('wp_ajax_nopriv_load_more_products', 'load_more_products');

function load_more_products() {
    check_ajax_referer('ajax-nonce', 'nonce');

    $sort = sanitize_text_field($_POST['sort'] ?? '');

    // Build query parameters
    $params = [
        'paged'             => absint($_POST['page'] ?? 1),
        'per_page'          => 12,
        'sort'              => $sort,
        'categories'        => isset($_POST['categories']) ? array_map('sanitize_text_field', (array) $_POST['categories']) : [],
        'brands'            => isset($_POST['brands']) ? array_map('sanitize_text_field', (array) $_POST['brands']) : [],
        'treatment_areas'   => isset($_POST['treatment_areas']) ? array_map('sanitize_text_field', (array) $_POST['treatment_areas']) : [],
        'ingredients'       => isset($_POST['ingredients']) ? array_map('sanitize_text_field', (array) $_POST['ingredients']) : [],
        'product_gauge'     => isset($_POST['product_gauge']) ? array_map('sanitize_text_field', (array) $_POST['product_gauge']) : [],
        'product_length'    => isset($_POST['product_length']) ? array_map('sanitize_text_field', (array) $_POST['product_length']) : [],
        'product_type'      => isset($_POST['product_type']) ? array_map('sanitize_text_field', (array) $_POST['product_type']) : [],
        'product_protocols' => isset($_POST['product_protocols']) ? array_map('sanitize_text_field', (array) $_POST['product_protocols']) : [],
        'category_id'       => absint($_POST['category_id'] ?? 0),
        'price_min'         => absint($_POST['price_min'] ?? 0),
        'price_max'         => absint($_POST['price_max'] ?? 0),
    ];

    $result = ProductArchive::queryProducts($params);
    $query  = $result['products'];

    ob_start();
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            wc_get_template_part('content', 'product');
        }
    }
    wp_reset_postdata();

    wp_send_json([
        'html'           => ob_get_clean(),
        'page'           => $params['paged'],
        'max_page'       => $result['max_pages'],
        'total_products' => $result['products_found'],
    ]);
}


add_action('wp_ajax_get_quick_view_product','get_quick_view_product');

add_action('wp_ajax_nopriv_get_quick_view_product','get_quick_view_product');


function get_quick_view_product(){

    check_ajax_referer('ajax-nonce', 'nonce');

    $product_id = absint($_POST['product_id'] ?? 0);
    $product    = wc_get_product($product_id);

    // Brand Display
    $brand = '';
    $brand_terms = wp_get_post_terms(
        $product_id,
        'product_brand',
        [
            'fields' => 'names'
        ]
    );

    if (!empty($brand_terms) && !is_wp_error($brand_terms)) {
        $brand = $brand_terms[0];
    }
    //Product Package
    $package_includes = get_field('package_includes',$product_id);

    // In/Out Stock
    $stock_status = '';
    if ($product->is_in_stock()) {
        $stock_status = '<span class="text-green-600 font-medium">🟢 In Stock</span>';
    } else {
        $stock_status = '<span class="text-red-500 font-medium">🔴 Out of Stock</span>';
    }
    if (!$product || $product->get_status() !== 'publish') {
        wp_send_json_error('Invalid product');
    }

    // Use same pricing logic as product card
    $price_obj = \App\Helper\Helper::getProductPricingInfo($product_id);

    $buy_bulk = get_field('buy_bulk_button', $product_id);

    if (!$product->is_in_stock()) {
        $button_text = 'Out of Stock';
    } else {
        $button_text = 'Add to Cart';
    }

    $cart = sprintf(
        '<a href="%s"
            data-quantity="1"
            class="add_to_cart_button ajax_add_to_cart w-full bg-black hover:cursor-pointer text-white font-semibold rounded-xl flex p-4 items-center justify-center gap-2 transition"
            data-product_id="%s">

            %s

        </a>',
        esc_url($product->add_to_cart_url()),
        esc_attr($product_id),
        esc_html($button_text)
    );

    wp_send_json_success([
        'image' => wp_get_attachment_image_url(
            $product->get_image_id(),
            'full'
        ),
        'category' => wc_get_product_category_list($product_id),
        'brand' => $brand,
        'package_includes' => $package_includes,
        'stock_status' => $stock_status,
        'name' => $product->get_name(),
        'description' => wp_trim_words(
            wp_strip_all_tags(
                $product->get_short_description()
            ),
            40,
            '...'
        ),
        'permalink' => get_permalink($product_id),
        'price' => [
            'is_sale' => $price_obj['is_on_sale'] ?? false,

            'regular_ex_vat' => $price_obj['regular_price_ex_vat'] ?? '',
            'regular_inc_vat' => $price_obj['regular_price_inc_vat'] ?? '',

            'purchase_ex_vat' => $price_obj['purchase_price_ex_vat'] ?? '',
            'purchase_inc_vat' => $price_obj['purchase_price_inc_vat'] ?? '',

            'saving_amount' => $price_obj['saving_amount'] ?? '',
            'saving_percent' => $price_obj['saving_percent'] ?? '',
        ],
        
        'cart' => $cart
    ]);
}
