<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

use App\Helper\Helper;

class TestimonialCards extends Block
{
    public $name = 'Testimonial Cards';
    public $description = 'A beautiful Testimonial Cards Slider block.';
    public $category = 'dermadirect';
    public $icon = 'superhero';

    public function with(): array
    {
        return [
            'testimonial_cards'         => Helper::getArrayItems('testimonial_cards'),
            'heading'        => (string)get_field('heading'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('testimonial_cards');

        $builder
            ->addText('heading');

        $builder
            ->addRepeater('testimonial_cards')
                ->addText('client_name')
                ->addText('country_or_state')
                ->addText('client_title')
                ->addNumber('rating', [
                    'min' => 1,
                    'max' => 5,
                    'step' => 1,
                ])
                ->addWysiwyg('testimonial_content')
            ->endRepeater();

        return $builder->build();
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/testimonials.js');
        wp_enqueue_script( 'block-testimonials-js', $script_uri, [], null, true );

        $style_uri = \Vite::asset('resources/css/blocks/testimonials.css');
        wp_enqueue_style( 'block-testimonials-css', $style_uri, [], null );

    }
}