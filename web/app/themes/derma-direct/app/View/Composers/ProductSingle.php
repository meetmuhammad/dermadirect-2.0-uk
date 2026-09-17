<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;
use App\DermadirectToolsMenu\NextDayDeliveryTimer\NextDayDeliveryTimerManager;
use App\Helper\Helper;

class ProductSingle extends Composer
{
    protected static $views = [
        'woocommerce.single-product',
        'woocommerce.content-single-product',
    ];

    public function override(): array
    {
        $this->enqueueAssets();

        return [
            'image_ids' => $this->getImageIDs(),
            'people_who_loved' => $this->getPeopleWhoLovedTheProduct(),

            'package_includes' => Helper::getArrayItems('package_includes'),

            'icon_before_atc' => (string) get_field('icon_before_atc'),
            'punch_line_before_atc' => (string) get_field('punch_line_before_atc'),

            'icon_after_atc' => (string) get_field('icon_after_atc'),
            'punch_line_after_atc' => (string) get_field('punch_line_after_atc'),

            'next_day_delivery_timer_banner' => $this->getNextDayDeliveryTimerBanner(),

            'product_notes_points' => Helper::getArrayItems('product_notes_points'),

            'key_benefits' => Helper::getArrayItems('key_benefits'),

            'buy_bulk_button' => (bool) get_field('buy_bulk_button'),

            // - - - - - -
            'product_description_content' => apply_filters('the_content', get_the_content()),

            // - - - - - -
            'reviews_section' => $this->getReviewsSectionHeading(),
            'recommended_products' => $this->getRecommendedProducts(),
        ];
    }

    private function getImageIDs(): array
    {
        $product = wc_get_product( get_the_ID() );

        if (!$product) return [];

        // Featured image ID
        $featured_id = $product->get_image_id();

        // Gallery image IDs
        $gallery_ids = $product->get_gallery_image_ids();

        // Merge them into one array with featured first
        $all_image_ids = $featured_id
            ? array_merge([$featured_id], $gallery_ids)
            : $gallery_ids;

        // Example output
        return $all_image_ids;
    }

    function getPeopleWhoLovedTheProduct(): array
    {
        $all_args = [
            'post_id'    => get_the_ID(),
            'status'     => 'approve',
            'meta_key'   => 'rating',
            'meta_value' => '5',
            'type'       => 'review',
            'parent'     => 0,
            'orderby'    => 'comment_date_gmt',
            'order'      => 'DESC',
            'number'     => 0,
        ];

        remove_filter('comments_clauses', ['WC_Comments', 'exclude_order_comments'], 10);
        $all_comments = get_comments($all_args);


        // STEP 1: Count *unique* reviewers.
        $unique_reviewers = [];
        foreach ($all_comments as $comment) {
            $email = $comment->comment_author_email;
            $unique_reviewers[$email] = true;
        }

        $total_count = count($unique_reviewers);

        // STEP 2: Get latest 2 unique reviewers
        $seen = [];
        $latest_two = [];

        foreach ($all_comments as $comment) {
            $email = $comment->comment_author_email;

            if (isset($seen[$email])) continue;

            $seen[$email] = true;

            $latest_two[] = [
                'name'       => $comment->comment_author,
                'avatar'     => get_avatar_url($email, ['size' => 96]),
                'review'     => $comment->comment_content,
                'date'       => $comment->comment_date,
                'user_id'    => $comment->user_id,
                'comment_id' => $comment->comment_ID,
            ];

            if (count($latest_two) === 2) break;
        }

        $text = [];

        foreach ($latest_two as $reviewers) {
            $text[] = $reviewers['name'] ?? '';
        }

        if ($total_count > 2) {
            $text[] = "and {$total_count} customers";
        }

        return [
            'reviewers' => $latest_two,    // Latest 2 unique reviewers
            'text' => join(', ', $text) . ' loved this product',
        ];
    }

    private function getReviewsSectionHeading(): array
    {
        return [
            'section_heading' => [
                'heading' => 'Reviews',
                'tagline' => 'What people say about this product',
                'max_width' => 'max-w-none',
                'alignment' => 'text-left',
                'padding_bottom' => 'pb-0',
                'padding_top' => 'pt-0',
            ],
        ];
    }

    private function getRecommendedProducts(): array
    {
        /**
         * This method gives data to the heading section and recommended-products section (blocks templates).
         * It gives 4 random products of the same category as the current product is.
        */
        $product_id = get_the_ID();

        $categories = wp_get_post_terms( $product_id, 'product_cat', ['fields' => 'ids'] );
        if ( empty($categories) ) return [];

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => 4,
            'post__not_in'   => [ $product_id ], // exclude current product
            'orderby'        => 'rand',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $categories,
                    'operator' => 'IN',
                ],
            ],
        ];

        $related_query = new \WP_Query( $args );
        $product_ids = [];

        if ( $related_query->have_posts() ) {
            while ( $related_query->have_posts() ) {
                $related_query->the_post();
                $product_ids[] = get_the_ID();
            }
            wp_reset_postdata();
        }

        return [
            'section_heading' => [
                'heading' => 'Frequently Bought Together',
                'tagline' => '<p>Targeted Skincare Solutions</p>',
                'max_width' => 'max-w-inherit',
                'padding_bottom' => 'pb-0',
                'alignment' => 'text-center',
                'remove_section_padding' => true,
            ],
            'products' => $product_ids,
        ];
    }

    private function enqueueAssets(): void
    {
        $script_uri = \Vite::asset('resources/js/product-single.js');
        wp_enqueue_script( 'product-single-page-js', $script_uri, [], null, true );

        wp_localize_script('product-single-page-js', 'sp_localized_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ajax-nonce'),
            'product_id' => get_the_ID(),
            'already_in_cart_qty' => function_exists('ddce_get_cart_quantity_for_product')
                ? ddce_get_cart_quantity_for_product((int) get_the_ID())
                : 0,
            'hard_cap' => 50,
        ]);

        $common_script_uri = \Vite::asset('resources/js/woocommerce-common.js');
        wp_enqueue_script( 'woocommerce-common-js', $common_script_uri, [], null, true );

        $write_review_script_uri = \Vite::asset('resources/js/write-review.js');
        wp_enqueue_script( 'product-write-review-js', $write_review_script_uri, [], null, true );

        wp_localize_script('product-write-review-js', 'pr_localized_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ajax-nonce'),
        ]);

        $common_style_uri = \Vite::asset('resources/css/woocommerce-common.css');
        wp_enqueue_style( 'woocommerce-common-style', $common_style_uri, [], null, 'all' );

        // For product description wrapper section which has toggle acordion.
        $wrapper_script_uri = \Vite::asset('resources/js/blocks/product-description.js');
        wp_enqueue_script( 'block-product-description-js', $wrapper_script_uri, [], null, true );

        $wrapper_style_uri = \Vite::asset('resources/css/blocks/product-description.css');
        wp_enqueue_style( 'block-product-description-css', $wrapper_style_uri, [], null );

        $heading_content_style_uri = \Vite::asset('resources/css/blocks/heading-and-content.css');
        wp_enqueue_style( 'block-heading-and-content-css', $heading_content_style_uri, [], null );
    }

    private function getNextDayDeliveryTimerBanner(): string
    {
        $banner_markup = NextDayDeliveryTimerManager::getBannerMarkup();

        if ($banner_markup !== '') {
            NextDayDeliveryTimerManager::enqueueFrontendAssets();
        }

        return $banner_markup;
    }
}
