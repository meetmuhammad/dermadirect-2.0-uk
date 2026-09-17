<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use Log1x\AcfComposer\Builder;

use App\Helper\Helper;

class LatestBlogs extends Block
{
    public $name = 'Latest Blogs';
    public $description = 'A beautiful Latest Blogs block.';
    public $category = 'dermadirect';
    public $icon = 'editor-ul';
    public $keywords = ['latest blogs', 'dermadirect'];


    public $supports = [
        'align' => true,
        'mode' => true,
        'multiple' => true,
    ];

    public function with(): array
    {
        return [
            'latestBlogs' => $this->getLatestBlogs(),
        ];
    }

    public function fields(): array
    {
        // No ACF fields needed anymore
        $builder = Builder::make('latest_blogs');

        return $builder->build();
    }

    public function getLatestBlogs(): array
    {
        $posts = get_posts([
            'post_type'      => 'post',
            'posts_per_page' => 4,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'suppress_filters' => false,
        ]);

        if (empty($posts)) {
            return [];
        }

        return array_map(function ($post) {

            return [
                'title'    => $post->post_title ?: get_the_title($post->ID),
                'excerpt'  => wp_trim_words($post->post_excerpt ?: get_the_excerpt($post->ID), 30),
                'link'     => get_permalink($post->ID),
                'image_id' => get_post_thumbnail_id($post->ID),
            ];

        }, $posts);
    }
}
