<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

class ReviewsSlider extends Block
{
    public $name = 'Reviews Slider';
    public $description = 'A beautiful Reviews Slider block.';
    public $category = 'dermadirect';
    public $icon = 'welcome-write-blog';
    public $keywords = [ 'review slider', 'product reviews', 'dermadirect' ];

    public $supports = [
        'align' => true,
        'align_text' => false,
        'align_content' => false,
        'full_height' => false,
        'anchor' => false,
        'mode' => true,
        'multiple' => true,
        'jsx' => true,
        'color' => [
            'background' => false,
            'text' => false,
            'gradients' => false,
        ],
        'spacing' => [
            'padding' => false,
            'margin' => false,
        ],
    ];

    public function with(): array
    {
        return [
            'reviews' => $this->getLatestReviews(),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('reviews_slider');

        $builder
            ->addNumber('reviews_count', [
                'min' => '6',
                'step' => '2',
            ]);

        return $builder->build();
    }

    private function getLatestReviews(): array
    {
        $reviews_count = (int) get_field(('reviews_count') ?? 6);

        $args = [
            'number'     => $reviews_count,
            'status'     => 'approve',
            'post_type'  => 'product',
            'orderby'    => 'comment_date_gmt',
            'order'      => 'DESC',
            'type'       => 'review',
            'parent'     => 0,
        ];

        // Some themes/plugins (like WooCommerce) modify comment queries, therefore reseting filters for safety.
        remove_filter('comments_clauses', ['WC_Comments', 'exclude_order_comments'], 10);

        $comments = get_comments($args);
        $reviews = [];

        foreach ($comments as $comment) {
            $rating = get_comment_meta($comment->comment_ID, 'rating', true);
            $product_id = $comment->comment_post_ID;

            $reviews[] = [
                'name'       => $comment->comment_author,
                'location'   => get_comment_meta($comment->comment_ID, 'billing_city', true) ?: '',
                'text'       => $comment->comment_content,
                'rating'     => (int) $rating,
                'image'      => get_avatar_url($comment->comment_author_email, ['size' => 96]),
                'product_id' => $product_id,
                'product'    => get_the_title($product_id),
                'date'       => $comment->comment_date,
            ];
        }

        // Splitting the array into two equal parts
        $half = max(1, (int) ceil(count($reviews) / 2));
        $reviews_split = array_chunk($reviews, $half);

        // This returns an array with 2 arrays inside
        return [
            'first_half'  => $reviews_split[0] ?? [],
            'second_half' => $reviews_split[1] ?? [],
        ];
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/reviews-slider.js');
        wp_enqueue_script( 'block-reviews-slider-js', $script_uri, [], null, true );

        $style_uri = \Vite::asset('resources/css/blocks/reviews-slider.css');
        wp_enqueue_style( 'block-reviews-slider-css', $style_uri, [], null );
    }
}
