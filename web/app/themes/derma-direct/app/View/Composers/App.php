<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class App extends Composer
{
    /**
     * List of views served by this composer.
     *
     * @var array
     */
    protected static $views = [
        '*',
    ];

    /**
     * Retrieve the site name.
     */
    public function siteName(): string
    {
        return get_bloginfo('name', 'display');
    }

    public function with(): array
    {
        $this->enqueueAssets();
        return [
            'siteName' => $this->siteName(),
        ];
    }

    public function enqueueAssets(): void
    {
        $search_style_uri = \Vite::asset('resources/css/search.css');
        wp_enqueue_style('search-css', $search_style_uri, [], null, 'all');

        $search_script_uri = \Vite::asset('resources/js/search-results.js');

        $training_style_uri = \Vite::asset('resources/css/training-partner.css');
        wp_enqueue_style('training-partner-css', $training_style_uri, [], null, 'all');

        $training_script_uri = \Vite::asset('resources/js/training-partner.js');
        wp_enqueue_script('training-partner-js', $training_script_uri, [], null, true);

        wp_enqueue_script('search-js', $search_script_uri, [], null, true);

        wp_localize_script(
            'search-js',
            'ddBrandSearch',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
            ]
        );
    }
}
