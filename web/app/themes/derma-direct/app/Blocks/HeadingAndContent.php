<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

class HeadingAndContent extends Block
{
    public $name = 'Heading And Content';
    public $description = 'A beautiful Heading And Content block.';
    public $category = 'dermadirect';
    public $icon = 'editor-ul';
    public $keywords = ['heading and content', 'product', 'dermadirect'];
    public $post_types = [ 'product' ];

    public function with(): array
    {
        return [
            'heading' => (string) get_field('heading'),
            'content' => (string) get_field('content'),
        ];
    }

    public function fields(): array
    {
        $builder = Builder::make('heading_and_content');

        $builder
            ->addText('heading')
            ->addWysiwyg('content');

        return $builder->build();
    }

    public function assets(array $block): void
    {
        $style_uri = \Vite::asset('resources/css/blocks/heading-and-content.css');
        wp_enqueue_style( 'block-heading-and-content-css', $style_uri, [], null );
    }
}
