<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;
use App\Fields\Partials\CtaButtonGroup;

class SaleBanner extends Block
{
    public $name = 'Sale Banner';
    public $description = 'A beautiful Sale Banner block.';
    public $category = 'dermadirect';
    public $icon = 'money-alt';
    public $keywords = ['sale', 'discount', 'banner', 'dermadirect'];

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
            'banner_with_image' => (array) get_field('banner_with_image'),
            'banner_with_bg_image' => (array) get_field('banner_with_bg_image'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('sale_banner');

        $builder
            ->addGroup('banner_with_image')
                ->addText('subtitle_top')
                ->addText('title')
                ->addText('content')
                ->addImage('image')
            ->endGroup();

        $builder
            ->addGroup('banner_with_bg_image')
                ->addText('subtitle_top')
                ->addText('title')
                ->addText('title_suffix')
                ->addText('content')
                ->addImage('background_image')
                ->addlink('cta_button')
            ->endGroup();

        return $builder->build();
    }
}
