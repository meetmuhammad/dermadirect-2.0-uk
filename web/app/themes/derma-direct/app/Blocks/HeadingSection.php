<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

class HeadingSection extends Block
{
    public $name = 'Heading Section';
    public $description = 'A beautiful Heading Section block.';
    public $category = 'dermadirect';
    public $icon = 'heading';
    public $keywords = ['heading', 'title', 'dermadirect'];

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
            'heading' => (string) get_field('heading'),
            'tagline' => (string) get_field('tagline'),

            'max_width' => (string) get_field('max_width'),
            'padding_top' => (string) get_field('padding_top'),
            'padding_bottom' => (string) get_field('padding_bottom'),
            'alignment' => (string) get_field('alignment'),
            'layout' => (string) get_field('layout'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('heading_section');

        $builder
            ->addText('heading')
            ->addWysiwyg('tagline');

        $builder
            ->addTrueFalse('max_width', [
                'label' => 'Max width Toggle',
                'instructions' => '',
                'required' => 0,
                'default_value' => 0,
            ]);

        $builder
            ->addSelect('padding_top', [
                'choices' => [
                    'pt-20 sm:pt-30 lg:pt-40' => __('X Large', 'sage'),
                    'pt-10 sm:pt-20 md:pt-30' => __('Large', 'sage'),
                    'pt-10 md:pt-15 lg:pt-20' => __('Medium Large', 'sage'),
                    'pt-6 sm:pt-10 md:pt-20' => __('Medium', 'sage'),
                    'pt-4 sm:pt-6 md:pt-10' => __('Small', 'sage'),
                    'pt-0' => __('No Padding', 'sage'),
                ],
                'default_value' => 'pt-6 sm:pt-10 md:pt-20',
            ])
            ->addSelect('padding_bottom', [
                'choices' => [
                    'pb-20 sm:pb-30 lg:pb-40' => __('X Large', 'sage'),
                    'pb-10 sm:pb-20 md:pb-30' => __('Large', 'sage'),
                    'pb-10 md:pb-15 lg:pb-20' => __('Medium Large', 'sage'),
                    'pb-6 sm:pb-10 md:pb-20' => __('Medium', 'sage'),
                    'pb-4 sm:pb-6 md:pb-10' => __('Small', 'sage'),
                    'pb-0' => __('No Padding', 'sage'),
                ],
                'default_value' => 'pb-6 sm:pb-10 md:pb-20',
            ])
            ->addSelect('alignment', [
                'choices' => [
                    '[&_h2]:text-center [&_p]:text-center [&_h2]:mx-auto items-center' => __('Centered', 'sage'),
                    'text-left' => __('Left aligned', 'sage'),
                ],
                'default_value' => '[&_h2]:text-center [&_p]:text-center [&_h2]:mx-auto items-center',
            ])
            ->addSelect('layout', [
                'choices' => [
                    'divider' => __('Divider b/w text', 'sage'),
                    'no-divider' => __('No divider b/w text', 'sage'),
                ],
                'default_value' => 'no-divider',
            ]);

        return $builder->build();
    }
}
