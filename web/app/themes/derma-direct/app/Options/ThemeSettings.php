<?php

namespace App\Options;

use Log1x\AcfComposer\Builder;
use Log1x\AcfComposer\Options as Field;
use App\Helper\Helper;

class ThemeSettings extends Field
{
    public $name = 'Theme Settings';
    public $title = 'Theme Settings | Options';
    public $position = 2;

    public function fields(): array
    {
        $builder = Builder::make('theme_settings');

        // ------------------------------------------------------------
        $builder
            ->addTab('header');

        $builder
            ->addSelect('header_top_menu', [
                'placeholder' => __('Select menu', 'sage'),
                'choices' => Helper::getAvailableMenus(),
                'return_format' => 'value',
            ])
            ->addText('top_banner_text')

            ->addImage('website_logo')
            ->addSelect('woocommerce_menu', [
                'placeholder' => __('Select menu', 'sage'),
                'choices' => Helper::getAvailableMenus(),
                'return_format' => 'value',
            ])
            ->addSelect('main_navigation_menu', [
                'placeholder' => __('Select menu', 'sage'),
                'choices' => Helper::getAvailableMenus(),
                'return_format' => 'value',
            ])
            ->addTrueFalse('header_sticky_desktop', [
                'label' => __('Sticky Header (Desktop)', 'sage'),
                'ui' => 1,
                'ui_on_text' => 'Sticky',
                'ui_off_text' => 'Normal',
                'default_value' => 0,
            ])
            ->addTrueFalse('header_sticky_mobile', [
                'label' => __('Sticky Header (Mobile)', 'sage'),
                'ui' => 1,
                'ui_on_text' => 'Sticky',
                'ui_off_text' => 'Normal',
                'default_value' => 0,
            ])
            ->addTextarea('header_scripts', [
                'instructions' => __('Add scripts here which will load in the head tag. Tracking codes etc.', 'sage'),
            ])

            ->addLink('training_button_link', [
                'label' => __('Training Button Link', 'sage'),
            ]);

        // ------------------------------------------------------------
        $builder
            ->addTab('footer');

        $builder
            ->addTextarea('about_derma_direct')
            ->addText('vat_number')
            ->addSelect('footer_nav_menu', [
                'placeholder' => __('Select menu', 'sage'),
                'choices' => Helper::getAvailableMenus(),
                'return_format' => 'value',
            ])
            ->addSelect('footer_services_menu', [
                'placeholder' => __('Select menu', 'sage'),
                'choices' => Helper::getAvailableMenus(),
                'return_format' => 'value',
            ])
            ->addText('subscribe_text')
            ->addText('subscribe_form_shortcode')
            ->addTextarea('footer_scripts', [
                'instructions' => __('Add scripts here which will load in the footer.', 'sage'),
            ]);

        // ------------------------------------------------------------
        $builder
            ->addTab('contact_info');

        $builder
            ->addLink('need_help_contact', [
                'wrapper' => ['width' => '50'],
            ])
            ->addLink('support_center_number', [
                'wrapper' => ['width' => '50'],
            ])

            ->addRepeater('social_media_profiles')
                 ->addSelect('platform', [
                    'choices' => [
                        'youtube-icon' => __('Youtube' ,'neara-sage'),
                        'instagram-icon' => __('Instagram' ,'neara-sage'),
                        'facebook-icon' => __('Facebook' ,'neara-sage'),
                        'twitter-icon' => __('Twitter' ,'neara-sage'),
                        'x-twitter-icon' => __('X App' ,'neara-sage'),
                        'linkedin-icon' => __('LinkedIn' ,'neara-sage'),
                    ],
                    'default_value' => 'youtube-icon',
                    'return_format' => 'value',
                    'ui' => true,
                    'wrapper' => ['width' => '30'],
                ])
                ->addText('profile_link', [
                    'wrapper' => ['width' => '70'],
                ])
            ->endRepeater();

        // ------------------------------------------------------------
        $builder
            ->addTab('product');

        $builder
            ->addRepeater('product_ingredients_list')
                ->addText('ingredient', ['wrapper' => ['width' => '70']])
            ->endRepeater();

        $builder
            ->addRepeater('product_gauge_list')
                ->addText('gauge', ['wrapper' => ['width' => '70']])
            ->endRepeater();

        $builder
            ->addRepeater('product_length_list')
                ->addText('length', ['wrapper' => ['width' => '70']])
            ->endRepeater();

        $builder
            ->addRepeater('product_type_list')
                ->addText('type', ['wrapper' => ['width' => '70']])
            ->endRepeater();

        $builder
            ->addRepeater('product_protocols_list')
                ->addText('protocol', ['wrapper' => ['width' => '70']])
            ->endRepeater();

        // ------------------------------------------------------------
        $builder
            ->addTab('popup');

        $builder
            ->addTrueFalse('enable_popup')

            ->addTrueFalse('popup_session_close', [
                'label' => 'Close Popup For Current Session',
            ])

            ->addNumber('popup_delay', [
                'label' => 'Popup Delay (seconds)',
                'default_value' => 1500,
            ])

            ->addImage('popup_image')

            ->addColorPicker('popup_close_button_color', [
                'label'         => 'Close Button Color',
                'default_value' => '#000000',
                'wrapper'       => ['width' => '50'],
            ]);

        // ------------------------------------------------------------
        $builder
            ->addTab('others');

        $builder
            ->addText('copyright_text')

            ->addNumber('free_delivery_threshold', [
                'label'         => 'Free Delivery Threshold (£)',
                'default_value' => 50,
                'min'           => 0,
                'step'          => 0.01,
                'instructions'  => 'Cart total required to qualify for free delivery.',
            ])

            ->addRepeater('trust_strip_items', [
                'label'        => 'Trust Strip Items',
                'instructions' => 'Items shown in the trust strip on the cart page (e.g. Secure Checkout, 100% Authentic Products).',
                'button_label' => 'Add Item',
                'min'          => 0,
               ])
                ->addText('strip_item_text', [
                    'label'        => 'Strip Item Text',
                ])
            ->endRepeater();


    $builder
    ->addTab('notifications');

    $builder
        ->addRepeater('site_notifications', [
            'label'        => 'Notifications',
            'instructions' => 'Notifications shown on the My Account dashboard.',
            'button_label' => 'Add Notification',
            'min'          => 0,
        ])
            ->addText('title', [
                'label' => 'Title',
            ])
            ->addTextarea('content', [
                'label' => 'Content',
                'rows'  => 3,
            ])
            ->addSelect('type', [
                'label'   => 'Type',
                'choices' => [
                    'warning' => 'Warning (Yellow)',
                    'info'    => 'Info (Blue)',
                    'offer'   => 'Offer (Green)',
                    'error'   => 'Error (Red)',
                ],
                'default_value' => 'info',
            ])
            ->addDateTimePicker('start_datetime', [
                'label'          => 'Notification Start',
                'display_format' => 'd/m/Y H:i',
                'return_format'  => 'Y-m-d H:i:s',
            ])
            ->addDateTimePicker('end_datetime', [
                'label'          => 'Notification End',
                'display_format' => 'd/m/Y H:i',
                'return_format'  => 'Y-m-d H:i:s',
            ])
        ->endRepeater();

        return $builder->build();
    }
}
