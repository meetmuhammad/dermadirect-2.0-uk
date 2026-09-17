<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;
use App\Fields\Partials\CtaButtonGroup;

class NewsletterSubscribe extends Block
{
    public $name = 'Newsletter Subscribe';
    public $description = 'A beautiful Newsletter Subscribe block.';
    public $category = 'dermadirect';
    public $icon = 'mailchimp';
    public $keywords = ['newsletter', 'subscribe', 'banner', 'dermadirect'];

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
        $form_image = (int) (get_field('form_image') ?? '');

        return [
            'newsletter_block_title' => (string) get_field('newsletter_block_title'),
            'newsletter_block_subtitle' => (string) get_field('newsletter_block_subtitle'),
            'button_text' => (string) get_field('button_text'),
            'form_image' => $form_image,
            'subscribe_form_shortcode' => (string) get_field('subscribe_form_shortcode'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('newsletter_subscribe');

        $builder
                ->addText('newsletter_block_title')
                ->addText('newsletter_block_subtitle')
                ->addText('button_text')
                ->addImage('form_image')
                ->addText('subscribe_form_shortcode', [
                'required' => 1,
    ]);

        return $builder->build();
    }

    public function assets(array $block): void
    {
        $script_uri = \Vite::asset('resources/js/blocks/newsletter-subscribe.js');
        wp_enqueue_script( 'block-newsletter-subscribe-js', $script_uri, [], null, true );

        $style_uri = \Vite::asset('resources/css/blocks/newsletter-subscribe.css');
        wp_enqueue_style( 'block-newsletter-subscribe-css', $style_uri, [], null );
    }
}
