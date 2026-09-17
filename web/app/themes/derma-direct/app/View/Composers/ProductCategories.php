<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class ProductCategories extends Composer
{
    protected static $views = [
        'product-categories',
    ];

    public function override(): array
    {
        return [
            'categories' => $this->getCategories(),
        ];
    }

    /**
     * Get all top-level WooCommerce product categories with thumbnail data.
     *
     * @return array
    */
    private function getCategories(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'parent'     => 0,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $categories = [];

        foreach ($terms as $term) {
            // Skip the default "Uncategorized" placeholder
            if ($term->slug === 'uncategorized') {
                continue;
            }

            $thumbnail_id  = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
            $thumbnail_url = $thumbnail_id ? (string) wp_get_attachment_url($thumbnail_id) : '';

            $categories[] = [
                'id'            => $term->term_id,
                'name'          => $term->name,
                'slug'          => $term->slug,
                'count'         => (int) $term->count,
                'description'   => $term->description,
                'permalink'     => (string) get_term_link($term),
                'thumbnail_id'  => $thumbnail_id,
                'thumbnail_url' => $thumbnail_url,
            ];
        }

        return $categories;
    }
}
