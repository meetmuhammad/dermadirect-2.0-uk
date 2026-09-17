<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

class VideoBanner extends Block
{
    public $name = 'Video Banner';
    public $description = 'A beautiful Video Banner block.';
    public $icon = 'editor-ul';
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
            'desktop_background_image' => (int) get_field('desktop_background_image'),
            'mobile_background_image' => (int) get_field('mobile_background_image'),
            'video' => (array) get_field('video'),
            'video_captions' => (array) get_field('video_captions'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('video_banner');

        $builder
            ->addImage('desktop_background_image')
            ->addImage('mobile_background_image')
            ->addFile('video', [
                'return_format' => 'array',
                'library' => 'all',
                'mime_types' => 'mp4,quicktime,x-msvideo,webm,ogg',
            ])
            ->addRepeater('video_captions', [
                'label' => 'Video Captions',
                'instructions' => 'Add one row per language. Phase 1: English only.',
                'button_label' => 'Add Caption Track',
                'min' => 0,
            ])
                ->addSelect('caption_language', [
                    'label' => 'Language',
                    'choices' => [
                        'en' => 'English',
                        'de' => 'German',
                        'fr' => 'French',
                        'es' => 'Spanish',
                        'it' => 'Italian',
                        'nl' => 'Dutch',
                        'pl' => 'Polish',
                    ],
                    'default_value' => 'en',
                    'return_format' => 'value',
                ])
                ->addFile('caption_file', [
                    'label' => 'Caption File (.vtt)',
                    'return_format' => 'array',
                    'library' => 'all',
                    'mime_types' => 'vtt',
                ])
                ->addTrueFalse('is_default', [
                    'label' => 'Default track',
                    'instructions' => 'Show this caption track automatically on load.',
                    'default_value' => 0,
                ])
            ->endRepeater();

        return $builder->build();
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/video-banner.js');
        wp_enqueue_script( 'block-video-banner-js', $script_uri, [], null, true );
    }
}
