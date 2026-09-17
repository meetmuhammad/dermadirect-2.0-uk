<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;
use App\Helper\Helper;

class Footer extends Composer
{
    protected static $views = [
        'sections.footer',
    ];

    public function override(): array
    {
        return [
            'about_derma_direct' => (string) get_field('about_derma_direct', 'option'),
            'vat_number' => (string) get_field('vat_number', 'option'),

            'footer_nav_menu_items' => Helper::getMenuItems('footer_nav_menu', 'acf_field_name'),
            'footer_services_menu_items' => Helper::getMenuItems('footer_services_menu', 'acf_field_name'),

            'subscribe_text' => (string) get_field('subscribe_text', 'option'),
            'subscribe_form_shortcode' => (string) get_field('subscribe_form_shortcode', 'option'),
            'social_handlers' => Helper::getArrayItems('social_media_profiles', 'option'),

            'copyright_text' => (string) get_field('copyright_text', 'option'),
        ];
    }
}
