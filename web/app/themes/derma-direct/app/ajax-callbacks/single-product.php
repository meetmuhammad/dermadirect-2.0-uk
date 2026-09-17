<?php
add_action('wp_ajax_load_product_reviews', 'load_product_reviews');
add_action('wp_ajax_nopriv_load_product_reviews', 'load_product_reviews');
function load_product_reviews() {
    $product_id = intval($_POST['product_id']);
    $paged      = max(1, intval($_POST['paged']));
    $per_page   = 4;
    $offset     = ($paged - 1) * $per_page;

    $reviews = get_comments([
        'post_id'  => $product_id,
        'status'   => 'approve',
        'type'     => 'review',
        'number'   => $per_page,
        'offset'   => $offset,
        'orderby'  => 'comment_date',
        'order'    => 'DESC'
    ]);

    ob_start();

    if (!empty($reviews ?? [])) {
        foreach ($reviews as $review) {
            echo \Roots\view('woocommerce.partials.single-review', [
                'review' => $review,
                'images' => [],
            ])->render();
        }
    } else {
        echo false;
        wp_send_json_success(ob_get_clean());
    }

    // pagination
    $total = get_comments([
        'post_id' => $product_id,
        'status'  => 'approve',
        'type'    => 'review',
        'count'   => true
    ]);

    $total_pages = ceil($total / $per_page);

    // Smart pagination render.
    $pagination = render_smart_pagination($paged, $total_pages);

    echo '<div class="review-pagination flex gap-2 mt-4 justify-center">';

    foreach ($pagination as $p) {
        if ($p === '...') {
            echo '<span class="px-3 py-1 text-gray-500">...</span>';
        } elseif ($p == $paged) {
            echo '<span class="px-3 py-1 rounded bg-primary text-white font-bold" data-page="'.$p.'">'.$p.'</span>';
        } else {
            echo '<a href="#" class="px-3 py-1 rounded bg-gray-100 hover:bg-primary hover:text-white" data-page="'.$p.'">'.$p.'</a>';
        }
    }

    echo '</div>';

    wp_send_json_success(ob_get_clean());
}

function render_smart_pagination($current, $total) {
    /**
     * This is a helper function
    */
    $pages = [];

    // Always show first page
    $pages[] = 1;

    // Add left dots
    if ($current > 3) {
        $pages[] = '...';
    }

    // Pages around current
    for ($i = $current - 1; $i <= $current + 1; $i++) {
        if ($i > 1 && $i < $total) {
            $pages[] = $i;
        }
    }

    // Add right dots
    if ($current < $total - 2) {
        $pages[] = '...';
    }

    // Always show last page
    if ($total > 1) {
        $last = $total;
        if (!in_array($last, $pages)) {
            $pages[] = $last;
        }
    }

    return $pages;
}

add_action('wp_ajax_save_product_review', 'save_product_review_callback');
add_action('wp_ajax_nopriv_save_product_review', 'save_product_review_callback');
function save_product_review_callback() {

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ajax-nonce')) {
        wp_send_json(['success' => false, 'message' => 'Invalid nonce']);
    }

    /* ----------------------------
     * REQUIRE LOGIN
     * ----------------------------
    */
    if (!is_user_logged_in()) {
        wp_send_json([
            'success' => false,
            'message' => 'Please log in to submit a review.'
        ]);
    }

    $user_id = get_current_user_id();

    $product_id = intval($_POST['product_id']);
    $name       = sanitize_text_field($_POST['name']);
    $email      = sanitize_email($_POST['email']);
    $content    = sanitize_textarea_field($_POST['review']);
    $rating     = intval($_POST['rating']);

    if (!$product_id || !$name || !$email || !$content) {
        wp_send_json(['success' => false, 'message' => 'Missing required fields']);
    }

    /* ----------------------------
     * CHECK IF THE USER PURCHASED THIS PRODUCT
     * ----------------------------
    */
    $has_purchased = wc_customer_bought_product($email, $user_id, $product_id);

    if (!$has_purchased) {
        wp_send_json([
            'success' => false,
            'message' => 'You must purchase this product before submitting a review.'
        ]);
    }

    /* ----------------------------
     * INSERT COMMENT (WooCommerce review)
     * ----------------------------
    */
    $commentdata = [
        'comment_post_ID'      => $product_id,
        'comment_author'       => $name,
        'comment_author_email' => $email,
        'comment_content'      => $content,
        'user_id'              => $user_id,
        'comment_type'         => 'review',
        'comment_approved'     => 0, // or 1 if you want auto approval
    ];

    $comment_id = wp_insert_comment($commentdata);

    if (!$comment_id) {
        wp_send_json(['success' => false, 'message' => 'Failed to save review']);
    }

    // Save rating
    if ($rating > 0 && $rating <= 5) {
        update_comment_meta($comment_id, 'rating', $rating);
    }

    wp_send_json(['success' => true, 'message' => 'Review submitted successfully']);
}
