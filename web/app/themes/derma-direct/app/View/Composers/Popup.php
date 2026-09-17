<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class Popup extends Composer
{
    protected static $views = [
        'sections.popup',
    ];

    public function override(): array
    {
        $enabled = (bool) get_field('enable_popup', 'option');

        if (! $enabled) {
            return ['popup' => null];
        }

        return [
            'popup' => [
                'session_close'     => (bool) get_field('popup_session_close', 'option'),
                'delay'             => (int) (get_field('popup_delay', 'option') ?: 0),
                'image'             => (int) get_field('popup_image', 'option'),
                'close_btn_color'   => (string) (get_field('popup_close_button_color', 'option') ?: '#000000'),
            ],
        ];
    }
}
