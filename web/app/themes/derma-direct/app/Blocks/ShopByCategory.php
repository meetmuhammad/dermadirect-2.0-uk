<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

use App\Helper\Helper;
use App\Fields\Partials\CtaButtonGroup;

class ShopByCategory extends Block
{
    public $name = 'Shop By Category';
    public $description = 'A beautiful Shop By Category block.';
    public $category = 'dermadirect';
    public $icon = 'category';
    public $keywords = [ 'shop by category', 'browse by category', 'dermadirect' ];

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
            'top_links' => Helper::getArrayItems('top_links'),
            'category_data' => $this->getCategoryData(),
            'flip_layout' => (bool) (get_field('flip_layout') ?? false),

            'cta' => CtaButtonGroup::getData(),
            'punchline' => (string) get_field('punchline'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('shop_by_category');

        $builder
            ->addRepeater('top_links')
                ->addLink('link')
            ->endRepeater();

        $builder
            ->addTaxonomy('product_category', [
                'taxonomy' => 'product_cat',
                'return_format' => 'id',
                'field_type' => 'select',
            ])
            ->addImage('bg_image')
            ->addTrueFalse('flip_layout');

        $builder
            ->addPartial(CtaButtonGroup::class)
            ->addText('punchline');

        return $builder->build();
    }

    private function getCategoryData(): array
    {
        $cat_id = (int) (get_field('product_category') ?? 0);

        $cat = get_term($cat_id, 'product_cat');
        if (!$cat || is_wp_error($cat)) return [];

        $data = [
            'name' => $cat->name,
            'description' => term_description($cat_id, 'product_cat'),
            'icon' => get_term_meta($cat_id, 'thumbnail_id', true),
            'background_image' => (int) (get_field('bg_image') ?? 0),
            'permalink' => get_term_link($cat_id, 'product_cat'),
            'products' => [],
        ];

        $products = wc_get_products(['limit' => -1, 'category' => [$cat->slug]]);
        foreach ($products as $p) {
            $product_id = $p->get_id();

            $p_price = Helper::getProductPricingInfo( $product_id );
            $brands = wp_get_post_terms($product_id, 'product_brand', ['fields' => 'names']);
            $brand_names = !empty($brands) ? implode(', ', [...$brands, $p_price['is_on_sale'] ? 'Sale' : '']) : '';

            $data['products'][] = [
                'id' => $product_id,
                'title' => $p->get_name(),
                'short_description' => $p->get_short_description(),
                'price' => $p_price,
                'permalink' => $p->get_permalink(),
                'image' => $p->get_image_id(),
                'brand' => $brand_names,
            ];
        }

        return $data ?? [];
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/shop-by-category.js');
        wp_enqueue_script( 'block-shop-by-category-js', $script_uri, [], null, true );
    }
}
