<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class ProductBrands extends Composer
{
    protected static $views = [
        'product-brands',
    ];

    public function override(): array
    {
        return [
            'brands' => $this->getBrands(),
        ];
    }

    private function getBrands(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_brand',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $brands = [];

        foreach ($terms as $term) {
            $permalink = get_term_link($term);

            if (is_wp_error($permalink)) {
                continue;
            }

            $thumbnail_id  = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
            $thumbnail_url = $thumbnail_id ? (string) wp_get_attachment_url($thumbnail_id) : '';

            $brands[] = [
                'id'            => $term->term_id,
                'name'          => $term->name,
                'slug'          => $term->slug,
                'count'         => (int) $term->count,
                'description'   => $term->description,
                'permalink'     => (string) $permalink,
                'thumbnail_id'  => $thumbnail_id,
                'thumbnail_url' => $thumbnail_url,
            ];
        }

        return $brands;
    }
}