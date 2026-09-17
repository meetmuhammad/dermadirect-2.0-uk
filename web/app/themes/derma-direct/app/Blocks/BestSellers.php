<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

class BestSellers extends Block
{
    public $name = 'Best Sellers';
    public $description = 'A beautiful Best Sellers block.';
    public $category = 'dermadirect';
    public $icon = 'thumbs-up';
    public $keywords = ['best seller', 'product', 'dermadirect'];

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
            'best_seller_products' => $this->getBestSellerProductsData(),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('best_sellers');

        $builder
            ->addPostObject('products', [
                'post_type' => ['product'],
                'multiple' => true,
                'return_format' => 'id',
                'ui' => true,
            ]);

        return $builder->build();
    }

    private function getBestSellerProductsData(): array
    {
        $product_ids = (array) get_field('products');
        if (empty($product_ids ?? [])) return [];

        $data = [];
        foreach ($product_ids as $id) {
            $product = wc_get_product($id);
            if (!$product) continue; // Skip if not valid product.

            $brands = wp_get_post_terms($id, 'product_brand', ['fields' => 'names']);
            $brands_list = implode(', ', $brands);

            $data[] = [
                'title' => $product->get_name(),
                'short_description' => $product->get_short_description(),
                'brands' => $brands_list,
                'link' => get_the_permalink($id),
                'featured_image' => get_post_thumbnail_id($id),
            ];
        }

        return $data;
    }
}
