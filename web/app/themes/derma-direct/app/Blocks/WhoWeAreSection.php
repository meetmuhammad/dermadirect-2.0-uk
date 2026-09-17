<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

use App\Helper\Helper;

class WhoWeAreSection extends Block
{
    public $name = 'Who We Are Section';
    public $description = 'A beautiful Who We Are Section block.';
    public $category = 'dermadirect';
    public $icon = 'buddicons-buddypress-logo';
    public $keywords = ['who we are', 'our story', 'dermadirect'];

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
        $background_image_desktop = (int) (get_field('background_image_desktop') ?? '');
        $background_image_mobile = (int) (get_field('background_image_mobile') ?? '');

        return [
            'background_image_desktop' => $background_image_desktop,
            'background_image_mobile' => $background_image_mobile ?: $background_image_desktop,

            'sub_heading_top' => (string) get_field('sub_heading_top'),
            'heading' => (string) get_field('heading'),
            'content' => (string) get_field('content'),

            'our_usps' => Helper::getArrayItems('our_usps'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('who_we_are_section');

        $builder
            ->addImage('background_image_desktop')
            ->addImage('background_image_mobile');

        $builder
            ->addText('sub_heading_top')
            ->addText('heading')
            ->addTextarea('content');

        $builder
            ->addRepeater('our_usps')
                ->addImage('icon')
                ->addText('heading')
                ->addText('content')
            ->endRepeater();

        return $builder->build();
    }
}
