<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

use App\Helper\Helper;

class RecommendedProducts extends Block
{
    public $name = 'Recommended Products';
    public $description = 'A beautiful Recommended Products block.';
    public $category = 'dermadirect';
    public $icon = 'editor-ul';
    public $keywords = ['recommended products', 'dermadirect'];

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
            'products' => Helper::getArrayItems('products'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('recommended_products');

        $builder
            ->addPostObject('products', [
                'post_type' => ['product'],
                'multiple' => true,
                'return_format' => 'id',
                'ui' => true,
            ]);

        return $builder->build();
    }
}
