<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;
use App\Fields\Partials\CtaButtonGroup;

class HeroSection extends Block
{
    public $name = 'Hero Section';
    public $description = 'A beautiful Hero Section block.';
    public $category = 'dermadirect';
    public $icon = 'superhero-alt';
    public $keywords = ['hero', 'banner', 'dermadirect'];

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

            'subheading_top' => (string) get_field('subheading_top'),
            'heading' => (string) get_field('heading'),
            'subheading_bottom' => (string) get_field('subheading_bottom'),

            'cta' => CtaButtonGroup::getData('cta_button'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('hero_section');

        $builder
            ->addImage('background_image_desktop')
            ->addImage('background_image_mobile');

        $builder
            ->addText('subheading_top')
            ->addText('heading')
            ->addWysiwyg('subheading_bottom');

        $builder
            ->addPartial(CtaButtonGroup::class, [
                'name' => 'cta_button',
            ]);

        return $builder->build();
    }
}
