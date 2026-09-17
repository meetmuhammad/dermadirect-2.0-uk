<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

class ProductDescription extends Block
{
    public $name = 'Product Description';
    public $description = 'A beautiful Product Description block.';
    public $category = 'dermadirect';
    public $icon = 'format-aside';
    public $keywords = ['product description', 'dermadirect'];
    public $post_types = ['product'];

    public $parent = [];
    public $ancestor = [];

    public $supports = [
        'align' => ['wide', 'full'],
        'multiple' => true,
        'jsx' => true,
        'inserter' => true,
        'anchor' => true,
        'innerBlocks' => true,
    ];

    public $allowed_blocks = [
        'core/paragraph',
        'core/heading',
        'acf/heading-and-content',
        'acf/treatment-areas',
    ];

    public $template = [
        'core/heading' => ['placeholder' => 'Hello World'],
        'core/paragraph' => ['placeholder' => 'Welcome to the Product Description block.'],
    ];

    public function with(): array
    {
        return [
            'allowed_blocks' => $this->allowed_blocks,
            'template' => $this->template,
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('product_description');

        return $builder->build();
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/product-description.js');
        wp_enqueue_script( 'block-product-description-js', $script_uri, [], null, true );

        $style_uri = \Vite::asset('resources/css/blocks/product-description.css');
        wp_enqueue_style( 'block-product-description-css', $style_uri, [], null );
    }
}
