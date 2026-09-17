<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

use App\Helper\Helper;

class HeroBannerSlider extends Block
{
    public $name = 'Hero Banner Slider';
    public $description = 'A beautiful Hero Banner Slider block.';
    public $category = 'dermadirect';
    public $icon = 'superhero';

    public function with(): array
    {
        return [
            'slides'         => Helper::getArrayItems('banner_slides'),
            'autoplay'       => (bool) get_field('autoplay_slides'),
            'video_slide_delay'    => (float) (get_field('video_slide_autoplay_delay') ?: 5),
            'image_slide_delay'   => (float) (get_field('image_slide_autoplay_delay') ?: 3),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('hero_banner_slider');

        $builder
            ->addRepeater('banner_slides')
                ->addImage('desktop_image')
                ->addImage('mobile_image')
                ->addLink('link')
                ->addTrueFalse('enable_video', [
                    'label' => 'Enable Video',
                    'ui' => 1,
                ])
                ->addFile('video', [
                    'label' => 'Video File',
                    'return_format' => 'array',
                    'library' => 'all',
                    'mime_types' => 'mp4,quicktime,x-msvideo,webm,ogg',
                ])
                ->conditional('enable_video', '==', 1)


                // ---- Video Captions ----
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
                        'default_value' => 0,
                    ])
                ->endRepeater()
                ->conditional('enable_video', '==', 1)


                // ---- Text content: always shown, every slide ----
                ->addText('heading')

                ->addTextarea('description', [
                    'rows' => 3,
                ])

                ->addRepeater('bullets', [
                    'label'        => 'Bullet Points',
                    'button_label' => 'Add Bullet',
                ])
                    ->addText('bullet_text', ['label' => 'Text'])
                ->endRepeater()

                ->addImage('content_image', [
                    'label'        => 'Content Image',
                    'instructions' => 'Used for "Content Left / Image Right" and "Image Left / Content Right" layouts.',
                ])
                ->conditional('enable_video', '!=', 1)

                ->addSelect('content_layout', [
                    'label' => 'Content Layout',
                    'choices' => [
                        'content_left'  => 'Content Left',
                        'content_right' => 'Content Right',
                    ],
                    'default_value' => 'content_left',
                    'ui' => 1,
                ])

                ->addColorPicker('content_text_color', [
                    'label' => 'Content Text Color',
                    'default_value' => '#000000',
                ])
            ->endRepeater();

        $builder
            ->addTrueFalse('autoplay_slides');

        $builder
            ->addNumber('video_slide_autoplay_delay', [
                'label' => 'Video Slide Autoplay Delay (seconds)',
                'default_value' => 5,
                'min' => 1,
                'step' => 1,
            ]);

        $builder
            ->addNumber('image_slide_autoplay_delay', [
                'label' => 'Image Slide Autoplay Delay (seconds)',
                'default_value' => 3,
                'min' => 1,
                'step' => 0.5,
            ]);

        return $builder->build();
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/hero-banner-slider.js');
        wp_enqueue_script( 'block-hero-banner-slider-js', $script_uri, [], null, true );

        $style_uri = \Vite::asset('resources/css/blocks/hero-banner-slider.css');
        wp_enqueue_style( 'block-hero-banner-slider-css', $style_uri, [], null );

        $script_uri = \Vite::asset('resources/js/blocks/video-banner.js');
        wp_enqueue_script( 'block-hero-banner-video-js', $script_uri, [], null, true );
    }
}
