<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

use App\Helper\Helper;

class BrandsSlider extends Block
{
    public $name = 'Brands Slider';
    public $description = 'A beautiful Brands Slider block.';
    public $category = 'dermadirect';
    public $icon = 'superhero';

    public function with(): array
    {
        return [
            'brands_slides'         => Helper::getArrayItems('brands_banner_slides'),
            'heading'        => (string)get_field('heading'),
            'autoplay'       => (bool) get_field('autoplay_slides'),
            'autoplay_delay' => (int) (get_field('autoplay_delay') ?: 5000),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('brands_slider');

        $builder
            ->addText('heading');

        $builder
            ->addRepeater('brands_banner_slides')
                ->addImage('brand_image')
                ->addLink('link')
            ->endRepeater();

        $builder
            ->addTrueFalse('autoplay_slides');

        $builder
            ->addNumber('autoplay_delay', [
                'min' => 500,
                'step' => 100,
            ]);

        return $builder->build();
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/brands-slider.js');
        wp_enqueue_script( 'block-brands-slider-js', $script_uri, [], null, true );

        $style_uri = \Vite::asset('resources/css/blocks/brands-slider.css');
        wp_enqueue_style( 'block-brandsx-slider-css', $style_uri, [], null );

    }
}