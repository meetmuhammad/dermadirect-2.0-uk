<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

class VideoSection extends Block
{
    public $name = 'Video Section';
    public $description = 'A beautiful Video Section block.';
    public $category = 'dermadirect';
    public $icon = 'video-alt';
    public $keywords = ['video section', 'dermadirect'];

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
            'supporting_image' => (int) get_field('supporting_image'),

            'heading' => (string) get_field('heading'),
            'content' => (string) get_field('content'),

            'video' => (array) get_field('video'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('video_section');

        $builder
            ->addImage('background_image_desktop')
            ->addImage('background_image_mobile')
            ->addImage('supporting_image');

        $builder
            ->addText('heading')
            ->addText('content');

        $builder
            ->addFile('video', [
                'mime_types' => 'mp4, mov, avi, wmv, flv, mkv, webm, ogv',
            ]);

        return $builder->build();
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/video-section.js');
        wp_enqueue_script( 'block-video-section-js', $script_uri, [], null, true );
    }
}
