<?php

namespace App\DermadirectToolsMenu\TrackingCodeSnippets;

use WP_Query;

class Manager
{
    /**
     * This file is responsible for rendering the code snippets in head or footer
     * based on the selected location.
     * It only renders the snippets which are active status (meta field value).
    */
    public static function init(): void
    {
        self::renderSnippets();
    }

    private static function renderSnippets(): void
    {
        $args = [
            'post_type'      => 'tc-snippet',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids', // only get IDs.
            'meta_query'     => [
                [
                    'key'     => '_tc_status',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
        ];

        $active_snippet_ids = get_posts($args); // Posts with meta field status value is active.

        foreach ($active_snippet_ids as $id) {
            $code = get_post_meta($id, '_tc_code_snippet', true);
            $location = get_post_meta($id, '_tc_render_location', true);

            // Rendering it in the head or footer based on selected location.
            add_action(($location === 'head') ? 'wp_head' : 'wp_footer', function() use($code) {
                echo $code;
            });
        }
    }

    private static function addPHPSnippets(): void
    {
        /**
         * Right now, the PHP codes are not implemented yet.
         * When the other plugins are migrated and made functional, then it will be added here.
        */
    }
}
