<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

use App\Helper\Helper;

class ServicesUsp extends Block
{
    public $name = 'Services Usp';
    public $description = 'A beautiful Services Usp block.';
    public $category = 'dermadirect';
    public $icon = 'awards';
    public $keywords = ['services', 'usp', 'dermadirect'];

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
            'usp_points' => Helper::getArrayItems('usp_points'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('services_usp');

        $builder
            ->addRepeater('usp_points')
                ->addImage('icon')
                ->addText('title')
                ->addText('content')
            ->endRepeater();

        return $builder->build();
    }
}
