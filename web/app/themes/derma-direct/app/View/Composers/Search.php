<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class Search extends Composer
{
    protected static $views = [
        'search',
    ];

    public function override(): array
    {
        $query = get_search_query();

        $products_by_category = $this->getProductsByCategory($query);
        $total_products       = (int) array_sum(array_column($products_by_category, 'count'));

        $blog_posts  = $this->getBlogPosts($query);
        $total_posts = count($blog_posts);

        return [
            'search_query'        => $query,
            'shop_url'            => get_permalink(wc_get_page_id('shop')) ?: home_url('/shop'),
            'products_by_category' => $products_by_category,
            'total_products'      => $total_products,
            'blog_posts'          => $blog_posts,
            'total_posts'         => $total_posts,
        ];
    }

    // Hard cap: beyond this many results the search is too broad to be useful anyway.
    private const MAX_PRODUCTS = 200;

    private function getProductsByCategory(string $query): array
    {
        if (empty($query)) return [];

        $ids_query = new \WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            's'              => $query,
            'posts_per_page' => self::MAX_PRODUCTS,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $product_ids = $ids_query->posts;
        wp_reset_postdata();

        if (empty($product_ids)) return [];

        // Prime WP object cache for products and their term relationships
        // in two bulk calls instead of N individual queries.
        _prime_post_caches($product_ids, false, true);
        wp_queue_posts_for_term_meta_lazyload($product_ids);
        update_object_term_cache($product_ids, 'product');

        $by_category   = [];
        $uncategorized = [];

        foreach ($product_ids as $id) {
            $product = wc_get_product($id); // hits object cache after prime

            if (!$product || !$product->is_visible()) continue;

            $terms = get_the_terms($id, 'product_cat'); // hits term cache

            if (empty($terms) || is_wp_error($terms)) {
                $uncategorized[] = $product;
                continue;
            }

            // Use first non-uncategorized term where possible
            $cat = $terms[0];
            foreach ($terms as $t) {
                if ($t->slug !== 'uncategorized') {
                    $cat = $t;
                    break;
                }
            }

            $cat_key = $cat->term_id;

            if (!isset($by_category[$cat_key])) {
                $by_category[$cat_key] = [
                    'name'     => $cat->name,
                    'slug'     => $cat->slug,
                    'products' => [],
                    'count'    => 0,
                ];
            }

            $by_category[$cat_key]['products'][] = $product;
            $by_category[$cat_key]['count']++;
        }

        if (!empty($uncategorized)) {
            $by_category[0] = [
                'name'     => __('Other', 'sage'),
                'slug'     => 'other',
                'products' => $uncategorized,
                'count'    => count($uncategorized),
            ];
        }

        return $by_category;
    }

    private function getBlogPosts(string $query): array
    {
        /**
         * Query blog posts for the search term.
        */
        if (empty($query)) return [];

        $posts_query = new \WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            's'              => $query,
            'posts_per_page' => 12,
        ]);

        $posts = [];

        if ($posts_query->have_posts()) {
            while ($posts_query->have_posts()) {
                $posts_query->the_post();

                $post_id = get_the_ID();

                $posts[] = [
                    'id'           => $post_id,
                    'title'        => get_the_title(),
                    'permalink'    => get_permalink(),
                    'excerpt'      => wp_trim_words(get_the_excerpt(), 20, '...'),
                    'date'         => get_the_date(),
                    'thumbnail_id' => get_post_thumbnail_id($post_id),
                    'categories'   => get_the_category_list(', '),
                    'author'       => get_the_author(),
                ];
            }
            wp_reset_postdata();
        }

        return $posts;
    }
}
